<?php

use App\Enums\ContentIdStatus;
use App\Models\Track;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['music.disk' => 'music']);
    Storage::fake('music');
});

test('the stream-safe filter keeps only stream-safe tracks that are not registered with Content ID', function () {
    $safe = Track::factory()->streamSafe()->create();
    $allowListed = Track::factory()->allowListed()->streamSafe()->create();
    Track::factory()->create();                  // not flagged stream-safe
    Track::factory()->registered()->create();    // registered, not allow-listed
    Track::factory()->allowListed()->create();   // allow-listed but not flagged

    // A row written around the model guard must still never reach the pack.
    $sneaky = Track::factory()->streamSafe()->create();
    Track::whereKey($sneaky->id)->toBase()->update(['content_id_status' => ContentIdStatus::Registered->value]);

    expect(Track::streamSafe()->pluck('id')->sort()->values()->all())->toBe([$safe->id, $allowListed->id]);
});

test('requestable tracks are exactly the stream-safe ones', function () {
    Track::factory()->streamSafe()->create();
    Track::factory()->allowListed()->streamSafe()->create();
    Track::factory()->registered()->create();
    Track::factory()->create();

    expect(Track::requestable()->orderBy('id')->pluck('id')->all())->toBe(Track::streamSafe()->orderBy('id')->pluck('id')->all())
        ->and(Track::requestable()->count())->toBe(2);
});

test('the pack page lists stream-safe tracks with attribution and a link back to ARE, and nothing else', function () {
    Track::factory()->streamSafe()->withStems()->create(['title' => 'Midnight Tea', 'artist' => 'EDOS', 'attribution' => 'Music: Midnight Tea by EDOS']);
    Track::factory()->streamSafe()->create(['title' => 'Plain Credit', 'artist' => 'EDOS']);
    Track::factory()->registered()->create(['title' => 'Claimed Song']);
    Track::factory()->create(['title' => 'Unreleased Demo', 'file_path' => 'tracks/secret-file-name.mp3']);

    $this->get('/music')
        ->assertOk()
        ->assertSee('Midnight Tea')
        ->assertSee('Music: Midnight Tea by EDOS · '.route('music.index'))
        ->assertSee('"Plain Credit" by EDOS · '.route('music.index'))
        ->assertSee(route('music.download', Track::firstWhere('title', 'Midnight Tea')))
        ->assertSee(route('music.stems', Track::firstWhere('title', 'Midnight Tea')))
        ->assertDontSee('Claimed Song')
        ->assertDontSee('Unreleased Demo')
        ->assertDontSee('tracks/');
});

test('the pack page renders with no tracks', function () {
    $this->get('/music')->assertOk()->assertSee('No tracks in the pack yet.');
});

test('a stream-safe track downloads through the controller', function () {
    Storage::disk('music')->put('tracks/abc123.mp3', 'audio-bytes');
    $track = Track::factory()->streamSafe()->create(['title' => 'Midnight Tea', 'artist' => 'EDOS', 'file_path' => 'tracks/abc123.mp3']);

    $response = $this->get(route('music.download', $track))->assertOk()->assertDownload('edos-midnight-tea.mp3');

    expect($response->streamedContent())->toBe('audio-bytes');
});

test('stems download through the controller', function () {
    Storage::disk('music')->put('stems/abc123.zip', 'zip-bytes');
    $track = Track::factory()->allowListed()->streamSafe()->create(['title' => 'Midnight Tea', 'artist' => 'EDOS', 'stems_path' => 'stems/abc123.zip']);

    $this->get(route('music.stems', $track))->assertOk()->assertDownload('edos-midnight-tea-stems.zip');
});

test('downloads 404 for tracks that are not stream-safe', function (array $attributes) {
    $track = Track::factory()->withStems()->create($attributes);
    Storage::disk('music')->put($track->file_path, 'audio-bytes');
    Storage::disk('music')->put($track->stems_path, 'zip-bytes');

    $this->get(route('music.download', $track))->assertNotFound();
    $this->get(route('music.stems', $track))->assertNotFound();
})->with([
    'not flagged' => [['stream_safe' => false]],
    'registered' => [['content_id_status' => ContentIdStatus::Registered, 'stream_safe' => false]],
    'allow-listed but not flagged' => [['content_id_status' => ContentIdStatus::AllowListed, 'stream_safe' => false]],
]);

test('downloads 404 for a missing track, missing stems or a missing file', function () {
    $noStems = Track::factory()->streamSafe()->create();
    Storage::disk('music')->put($noStems->file_path, 'audio-bytes');
    $missingFile = Track::factory()->streamSafe()->create();

    $this->get(route('music.download', 999))->assertNotFound();
    // Past bigint range: rejected by the route instead of reaching Postgres.
    $this->get('/music/99999999999999999999/download')->assertNotFound();
    $this->get(route('music.stems', $noStems))->assertNotFound();
    $this->get(route('music.download', $missingFile))->assertNotFound();
});
