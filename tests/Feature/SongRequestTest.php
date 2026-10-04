<?php

use App\Chat\ChatCommandRegistry;
use App\Chat\ChatCommandResult;
use App\Chat\ChatCommandStatus;
use App\Enums\ContentIdStatus;
use App\Enums\Overlay;
use App\Enums\SongRequestSource;
use App\Enums\SongRequestStatus;
use App\IdentityProvider;
use App\Jobs\EventSub\HandleChannelPointRedemption;
use App\Jobs\EventSub\HandleChatMessage;
use App\Models\ModerationAction;
use App\Models\OverlayToken;
use App\Models\SongRequest;
use App\Models\Track;
use App\Models\TwitchBan;
use App\Models\TwitchModerator;
use App\Models\User;
use App\SongRequests;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

const SONG_REWARD = 'reward-song-0001';

function songCommand(string $text, string $chatterId = '5550001'): ?ChatCommandResult
{
    return app(ChatCommandRegistry::class)->run(IdentityProvider::Twitch, '1000', $chatterId, 'songfan', (string) Str::uuid(), $text);
}

function songFan(string $twitchId = '5550001'): User
{
    return User::factory()->twitch($twitchId)->create(['name' => 'Song Fan']);
}

function songModerator(): User
{
    $mod = User::factory()->twitch()->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);

    return $mod;
}

function redeemSong(string $input, array $overrides = []): void
{
    $event = $overrides + [
        'id' => (string) Str::uuid(),
        'broadcaster_user_id' => '1000',
        'broadcaster_user_login' => 'edos',
        'broadcaster_user_name' => 'EDOS',
        'user_id' => '5550002',
        'user_login' => 'pointspender',
        'user_name' => 'PointSpender',
        'user_input' => $input,
        'status' => 'unfulfilled',
        'reward' => ['id' => SONG_REWARD, 'title' => 'Request a song', 'cost' => 500, 'prompt' => 'Song title'],
        'redeemed_at' => now()->toIso8601ZuluString(),
    ];

    (new HandleChannelPointRedemption((string) Str::uuid(), now()->toIso8601ZuluString(), $event))->handle();
}

beforeEach(function () {
    config([
        'services.twitch.broadcaster_id' => '1000',
        'music.requests_per_user' => 2,
        'music.song_request_reward_id' => SONG_REWARD,
    ]);
});

// --- !song -------------------------------------------------------------------

test('!song by title queues a requestable track for the linked chatter', function () {
    $fan = songFan();
    $track = Track::factory()->streamSafe()->create(['title' => 'Midnight Tea', 'artist' => 'EDOS']);

    $result = songCommand('!song midnight TEA');

    expect($result->status)->toBe(ChatCommandStatus::Done)
        // Song numbers, never titles or the viewer's query (#128).
        ->and($result->reply)->toBe("Requested song #{$track->id}. It is number 1 in the queue.");

    $request = SongRequest::sole();
    expect($request->track->is($track))->toBeTrue()
        ->and($request->requester->is($fan))->toBeTrue()
        ->and($request->requester_name)->toBe('songfan')
        ->and($request->source)->toBe(SongRequestSource::Chat)
        ->and($request->status)->toBe(SongRequestStatus::Queued);
});

test('!song arrives through the real Twitch chat path', function () {
    songFan();
    Track::factory()->streamSafe()->create(['title' => 'Midnight Tea']);

    (new HandleChatMessage((string) Str::uuid(), now()->toIso8601ZuluString(), [
        'broadcaster_user_id' => '1000',
        'broadcaster_user_login' => 'edos',
        'broadcaster_user_name' => 'EDOS',
        'chatter_user_id' => '5550001',
        'chatter_user_login' => 'songfan',
        'chatter_user_name' => 'songfan',
        'message_id' => (string) Str::uuid(),
        'message' => ['text' => '!song Midnight Tea', 'fragments' => []],
        'message_type' => 'text',
        'badges' => [],
    ]))->handle();

    expect(SongRequest::count())->toBe(1);
});

test('!song accepts a track number, with or without #', function () {
    songFan();
    $first = Track::factory()->streamSafe()->create();
    $second = Track::factory()->streamSafe()->create();

    expect(songCommand("!song #{$first->id}")->status)->toBe(ChatCommandStatus::Done)
        ->and(songCommand("!song {$second->id}")->status)->toBe(ChatCommandStatus::Done)
        ->and(SongRequest::orderBy('id')->pluck('track_id')->all())->toBe([$first->id, $second->id]);
});

test('!song matches a unique partial title, prefers an exact one, and lists ambiguous matches', function () {
    songFan();
    $tea = Track::factory()->streamSafe()->create(['title' => 'Tea']);
    $midnight = Track::factory()->streamSafe()->create(['title' => 'Midnight Tea']);
    $forTwo = Track::factory()->streamSafe()->create(['title' => 'Tea for Two']);
    Track::factory()->streamSafe()->create(['title' => 'Solar Wind']);

    expect(SongRequests::resolve('tea')->title)->toBe('Tea')
        ->and(SongRequests::resolve('solar')->title)->toBe('Solar Wind');

    $result = songCommand('!song te');
    expect($result->status)->toBe(ChatCommandStatus::Rejected)
        ->and($result->reply)->toBe("More than one song matches. Use the song number: #{$midnight->id}, #{$tea->id}, #{$forTwo->id}.")
        ->and($result->reply)->not->toContain('Midnight Tea');
});

test('!song never reaches a track that is not requestable', function () {
    songFan();
    $hidden = Track::factory()->create(['title' => 'Unreleased Demo']);
    $claimed = Track::factory()->registered()->create(['title' => 'Claimed Song']);

    foreach (['!song Unreleased Demo', "!song #{$hidden->id}", '!song Claimed Song', "!song {$claimed->id}", '!song nothing like this'] as $text) {
        $result = songCommand($text);
        expect($result->status)->toBe(ChatCommandStatus::Rejected)
            ->and($result->reply)->toContain('No requestable song matches');
    }

    expect(SongRequest::count())->toBe(0);
});

test('!song with no song answers with usage and the pack link', function () {
    songFan();

    expect(songCommand('!song')->reply)->toBe('Usage: !song <title or number>. Songs: '.route('music.index'));
});

test('a track already queued or playing is not added twice, and can be requested again once it has played', function () {
    songFan();
    songFan('5550009');
    $track = Track::factory()->streamSafe()->create(['title' => 'Midnight Tea']);

    expect(songCommand('!song Midnight Tea')->status)->toBe(ChatCommandStatus::Done);

    $again = songCommand('!song Midnight Tea', '5550009');
    expect($again->status)->toBe(ChatCommandStatus::Rejected)
        ->and($again->reply)->toBe("Song #{$track->id} is already in the request queue.");

    SongRequests::playNext(songModerator());
    expect(songCommand('!song Midnight Tea', '5550009')->status)->toBe(ChatCommandStatus::Rejected);

    SongRequests::finish(songModerator());
    expect(songCommand('!song Midnight Tea', '5550009')->status)->toBe(ChatCommandStatus::Done)
        ->and(SongRequest::where('track_id', $track->id)->count())->toBe(2);
});

test('each person may have only music.requests_per_user songs waiting', function () {
    songFan();
    Track::factory()->streamSafe()->count(3)->sequence(['title' => 'One'], ['title' => 'Two'], ['title' => 'Three'])->create();

    expect(songCommand('!song One')->status)->toBe(ChatCommandStatus::Done)
        ->and(songCommand('!song Two')->status)->toBe(ChatCommandStatus::Done);

    $third = songCommand('!song Three');
    expect($third->status)->toBe(ChatCommandStatus::Rejected)
        ->and($third->reply)->toBe('You already have 2 songs in the request queue. Wait for one to play.');

    // Once one of theirs is on air, it no longer counts as waiting.
    SongRequests::playNext(songModerator());
    expect(songCommand('!song Three')->status)->toBe(ChatCommandStatus::Done);
});

test('!song needs a linked user and refuses banned ones', function () {
    Track::factory()->streamSafe()->create(['title' => 'Midnight Tea']);

    expect(songCommand('!song Midnight Tea', '9999999')->status)->toBe(ChatCommandStatus::Unlinked);

    songFan();
    TwitchBan::create(['broadcaster_id' => '1000', 'twitch_user_id' => '5550001']);
    expect(songCommand('!song Midnight Tea')->status)->toBe(ChatCommandStatus::Banned)
        ->and(SongRequest::count())->toBe(0);
});

// --- Channel points ----------------------------------------------------------

test('redeeming the song reward queues the song, linked or not, outside the per-user limit', function () {
    config(['music.requests_per_user' => 1]);
    $linked = songFan('5550002');
    Track::factory()->streamSafe()->count(3)->sequence(['title' => 'One'], ['title' => 'Two'], ['title' => 'Three'])->create();

    redeemSong('One');
    redeemSong('Two');
    redeemSong('Three', ['user_id' => '7770001', 'user_name' => 'Lurker']);

    $requests = SongRequest::with('track')->orderBy('id')->get();
    expect($requests)->toHaveCount(3)
        ->and($requests->pluck('track.title')->all())->toBe(['One', 'Two', 'Three'])
        ->and($requests->pluck('source')->unique()->all())->toBe([SongRequestSource::ChannelPoints])
        ->and($requests[0]->requester->is($linked))->toBeTrue()
        ->and($requests[0]->requester_name)->toBe('PointSpender')
        ->and($requests[0]->channel_point_redemption_id)->not->toBeNull()
        ->and($requests[2]->requester_id)->toBeNull()
        ->and($requests[2]->requester_name)->toBe('Lurker');
});

test('a redelivered redemption queues its song once', function () {
    Track::factory()->streamSafe()->create(['title' => 'Midnight Tea']);
    $id = (string) Str::uuid();

    redeemSong('Midnight Tea', ['id' => $id]);
    redeemSong('Midnight Tea', ['id' => $id]);

    expect(SongRequest::count())->toBe(1);
});

test('other rewards, or no configured reward, queue nothing', function () {
    Track::factory()->streamSafe()->create(['title' => 'Midnight Tea']);

    redeemSong('Midnight Tea', ['reward' => ['id' => 'some-other-reward', 'title' => 'Hydrate', 'cost' => 100]]);
    config(['music.song_request_reward_id' => null]);
    redeemSong('Midnight Tea');

    expect(SongRequest::count())->toBe(0);
});

test('a refused redemption is logged and queues nothing', function () {
    Log::spy();
    Track::factory()->registered()->create(['title' => 'Claimed Song']);
    Track::factory()->streamSafe()->create(['title' => 'Midnight Tea']);

    redeemSong('Claimed Song');
    redeemSong('Midnight Tea', ['user_id' => '7770002']);
    TwitchBan::create(['broadcaster_id' => '1000', 'twitch_user_id' => '7770002']);
    redeemSong('Midnight Tea', ['user_id' => '7770002']);

    expect(SongRequest::count())->toBe(1);
    Log::shouldHaveReceived('info')->with('Channel-point song request refused', Mockery::any())->twice();
});

// --- Moderator page ----------------------------------------------------------

test('the requests page is moderator-only, and so are its actions', function () {
    $request = SongRequest::factory()->create();

    $this->get(route('music.requests'))->assertRedirect();
    $this->actingAs(User::factory()->create())->get(route('music.requests'))->assertForbidden();
    $this->actingAs(songModerator())->get(route('music.requests'))->assertOk()->assertSee($request->track->title);

    foreach ([['play', $request->id], ['skip', $request->id], ['clear'], ['playNext'], ['finish']] as $call) {
        Volt::actingAs(User::factory()->create())->test('music.requests')->call(...$call)->assertForbidden();
    }

    expect($request->refresh()->status)->toBe(SongRequestStatus::Queued)
        ->and(fn () => SongRequests::clear(User::factory()->create()))->toThrow(AuthorizationException::class);
});

test('a moderator plays, finishes and skips requests', function () {
    $mod = songModerator();
    [$first, $second, $third] = SongRequest::factory()->count(3)->create()->all();

    $page = Volt::actingAs($mod)->test('music.requests')->call('play', $second->id);
    expect($second->refresh()->status)->toBe(SongRequestStatus::Playing)
        ->and($second->started_at)->not->toBeNull();

    // Playing another finishes the one on air.
    $page->call('playNext');
    expect($second->refresh()->status)->toBe(SongRequestStatus::Played)
        ->and($first->refresh()->status)->toBe(SongRequestStatus::Playing);

    $page->call('finish');
    expect($first->refresh()->status)->toBe(SongRequestStatus::Played)->and(SongRequests::nowPlaying())->toBeNull();

    $page->call('skip', $third->id);
    expect($third->refresh()->status)->toBe(SongRequestStatus::Skipped)
        ->and(ModerationAction::where('action', 'song_request.skipped')->count())->toBe(1);

    // A finished request cannot be played again.
    $page->call('play', $third->id)->assertHasErrors('queue');
});

test('clearing the queue skips what is waiting and leaves what is playing', function () {
    $mod = songModerator();
    $playing = SongRequest::factory()->playing()->create();
    SongRequest::factory()->count(2)->create();

    Volt::actingAs($mod)->test('music.requests')->call('clear');

    expect(SongRequest::where('status', SongRequestStatus::Skipped->value)->count())->toBe(2)
        ->and($playing->refresh()->status)->toBe(SongRequestStatus::Playing)
        ->and(ModerationAction::where('action', 'song_request.cleared')->sole()->details)->toBe(['cleared' => 2]);
});

// --- Now-playing overlay -----------------------------------------------------

test('the now-playing overlay shows the playing request with its attribution', function () {
    $token = OverlayToken::issue(Overlay::NowPlaying);
    $track = Track::factory()->allowListed()->streamSafe()->create(['title' => 'Midnight Tea', 'artist' => 'EDOS', 'attribution' => 'Music: Midnight Tea by EDOS']);
    SongRequest::factory()->for($track)->playing()->create(['requester_name' => 'songfan']);
    SongRequest::factory()->create(['requester_name' => 'still-waiting']);

    $this->get(route('overlay.show', ['overlay' => 'now-playing', 'token' => $token]))
        ->assertOk()
        ->assertSee('data-now-playing', false)
        ->assertSee('Midnight Tea')
        ->assertSee('EDOS')
        ->assertSee('Music: Midnight Tea by EDOS · '.route('music.index'))
        ->assertSee('Requested by songfan')
        ->assertDontSee('still-waiting')
        ->assertSee('wire:poll.5s.keep-alive', false);
});

test('the now-playing overlay is blank when nothing is playing, and once its token is rotated', function () {
    OverlayToken::issue(Overlay::NowPlaying);

    $overlay = Volt::test('overlays.now-playing')->assertSeeHtml('data-overlay-empty')->assertDontSeeHtml('data-now-playing');

    SongRequest::factory()->playing()->create();
    $overlay->call('$refresh')->assertSeeHtml('data-now-playing');

    OverlayToken::issue(Overlay::NowPlaying);
    $overlay->call('$refresh')->assertDontSeeHtml('data-now-playing')->assertDontSeeHtml('wire:poll');
});

test('deleting a track removes its requests', function () {
    $track = Track::factory()->streamSafe()->create(['content_id_status' => ContentIdStatus::NotRegistered]);
    SongRequest::factory()->for($track)->create();

    $track->delete();

    expect(SongRequest::count())->toBe(0);
});

// #130: two !song requests at once, for different tracks, could both pass the
// per-person cap, because only the track row was locked. A true race cannot
// run in one process, so pin the order instead: inside the transaction, the
// requester's row is locked before the track and before the count.
test('a request locks the requester row before the track and before counting their queue', function () {
    $fan = songFan();
    $track = Track::factory()->streamSafe()->create(['title' => 'Midnight Tea']);
    SongRequest::factory()->for($fan, 'requester')->create();

    DB::enableQueryLog();
    SongRequests::request($track, $fan, 'songfan', SongRequestSource::Chat);
    $queries = collect(DB::getQueryLog())->pluck('query')->values();
    DB::disableQueryLog();

    $userLock = $queries->search(fn (string $sql) => preg_match('/from ["`]?users["`]? where ["`]?users["`]?\.["`]?id["`]? = \?/i', $sql) === 1);
    $trackLock = $queries->search(fn (string $sql) => preg_match('/from ["`]?tracks["`]?/i', $sql) === 1);
    $count = $queries->search(fn (string $sql) => str_contains(strtolower($sql), 'count(*)') && str_contains($sql, 'song_requests'));
    $insert = $queries->search(fn (string $sql) => str_starts_with(strtolower($sql), 'insert into') && str_contains($sql, 'song_requests'));

    expect($userLock)->toBeInt()
        ->and($trackLock)->toBeInt()
        ->and($count)->toBeInt()
        ->and($insert)->toBeInt()
        ->and($userLock)->toBeLessThan($trackLock)
        ->and($trackLock)->toBeLessThan($count)
        ->and($count)->toBeLessThan($insert);

    // SQLite has no row locks (it locks the whole database for writes), so its
    // grammar drops FOR UPDATE. Production runs PostgreSQL, where it must be there.
    if (DB::connection()->getDriverName() === 'pgsql') {
        expect(strtolower($queries[$userLock]))->toContain('for update')
            ->and(strtolower($queries[$trackLock]))->toContain('for update');
    }
});

test('a request with no linked requester takes no user lock', function () {
    $track = Track::factory()->streamSafe()->create();

    DB::enableQueryLog();
    SongRequests::request($track, null, 'Lurker', SongRequestSource::ChannelPoints);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $sql) => preg_match('/from ["`]?users["`]?/i', $sql) === 1))->toBeEmpty()
        ->and(SongRequest::count())->toBe(1);
});
