<?php

use App\Enums\ContentIdStatus;
use App\Models\Track;
use App\Models\TwitchModerator;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

function catalogueAdmin(): User
{
    $mod = User::factory()->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);

    return $mod;
}

function mp3(string $name = 'song.mp3'): UploadedFile
{
    return UploadedFile::fake()->create($name, 200, 'audio/mpeg');
}

beforeEach(function () {
    config(['services.twitch.broadcaster_id' => '1000', 'music.disk' => 'music']);
    Storage::fake('music');
    Storage::fake('local'); // Livewire's temporary uploads
});

test('the broadcaster and moderators may manage the catalogue; viewers may not', function () {
    $track = Track::factory()->create();
    $viewer = User::factory()->create();

    foreach ([catalogueAdmin(), User::factory()->create(['twitch_id' => '1000'])] as $admin) {
        expect($admin->can('viewAny', Track::class))->toBeTrue()
            ->and($admin->can('create', Track::class))->toBeTrue()
            ->and($admin->can('update', $track))->toBeTrue()
            ->and($admin->can('delete', $track))->toBeTrue();
    }

    expect($viewer->can('viewAny', Track::class))->toBeFalse()
        ->and($viewer->can('create', Track::class))->toBeFalse()
        ->and($viewer->can('update', $track))->toBeFalse()
        ->and($viewer->can('delete', $track))->toBeFalse();
});

test('the catalogue page is admin-only', function () {
    $this->get(route('music.catalogue'))->assertRedirect();
    $this->actingAs(User::factory()->create())->get(route('music.catalogue'))->assertForbidden();
    $this->actingAs(catalogueAdmin())->get(route('music.catalogue'))
        ->assertOk()
        ->assertSee('Music catalogue')
        ->assertSee('allow-list all four channels with the distributor first');
});

test('a viewer cannot call the catalogue actions directly', function () {
    $track = Track::factory()->create();

    Volt::actingAs(User::factory()->create())->test('music.catalogue')
        ->set('title', 'Sneaky')
        ->set('artist', 'Nobody')
        ->set('file', mp3())
        ->call('save')
        ->assertForbidden();

    Volt::actingAs(User::factory()->create())->test('music.catalogue')
        ->call('delete', $track->id)
        ->assertForbidden();

    expect(Track::count())->toBe(1);
});

test('an admin uploads a track and its stems to the music disk', function () {
    Volt::actingAs(catalogueAdmin())->test('music.catalogue')
        ->set('title', 'Midnight Tea')
        ->set('artist', 'EDOS')
        ->set('durationSeconds', 185)
        ->set('attribution', 'Midnight Tea by EDOS')
        ->set('streamSafe', true)
        ->set('file', mp3())
        ->set('stems', UploadedFile::fake()->create('stems.zip', 300, 'application/zip'))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('title', '');

    $track = Track::sole();

    expect($track->title)->toBe('Midnight Tea')
        ->and($track->content_id_status)->toBe(ContentIdStatus::NotRegistered)
        ->and($track->stream_safe)->toBeTrue()
        ->and($track->duration_seconds)->toBe(185)
        ->and($track->file_path)->toStartWith('tracks/')
        ->and($track->stems_path)->toStartWith('stems/');

    Storage::disk('music')->assertExists([$track->file_path, $track->stems_path]);
});

test('a new track needs an audio file, and stems must be a zip', function () {
    Volt::actingAs(catalogueAdmin())->test('music.catalogue')
        ->set('title', 'No File')
        ->set('artist', 'EDOS')
        ->call('save')
        ->assertHasErrors(['file' => 'required']);

    Volt::actingAs(catalogueAdmin())->test('music.catalogue')
        ->set('title', 'Wrong Type')
        ->set('artist', 'EDOS')
        ->set('file', UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))
        ->set('stems', UploadedFile::fake()->create('stems.rar', 10, 'application/vnd.rar'))
        ->call('save')
        ->assertHasErrors(['file' => 'mimetypes', 'stems' => 'mimes']);

    expect(Track::count())->toBe(0);
});

test('replacing a file on edit deletes the old one', function () {
    Storage::disk('music')->put('tracks/old.mp3', 'old');
    $track = Track::factory()->create(['file_path' => 'tracks/old.mp3']);

    Volt::actingAs(catalogueAdmin())->test('music.catalogue')
        ->call('edit', $track->id)
        ->assertSet('title', $track->title)
        ->set('title', 'Renamed')
        ->set('file', mp3('new.mp3'))
        ->call('save')
        ->assertHasNoErrors();

    $track->refresh();
    expect($track->title)->toBe('Renamed')->and($track->file_path)->not->toBe('tracks/old.mp3');
    Storage::disk('music')->assertMissing('tracks/old.mp3');
    Storage::disk('music')->assertExists($track->file_path);
});

test('editing without a new file keeps the existing one', function () {
    $track = Track::factory()->create(['file_path' => 'tracks/keep.mp3']);

    Volt::actingAs(catalogueAdmin())->test('music.catalogue')
        ->call('edit', $track->id)
        ->set('artist', 'New Credits')
        ->call('save')
        ->assertHasNoErrors();

    expect($track->refresh()->artist)->toBe('New Credits')->and($track->file_path)->toBe('tracks/keep.mp3');
});

test('deleting a track removes its files', function () {
    Storage::disk('music')->put('tracks/a.mp3', 'a');
    Storage::disk('music')->put('stems/a.zip', 'a');
    $track = Track::factory()->create(['file_path' => 'tracks/a.mp3', 'stems_path' => 'stems/a.zip']);

    Volt::actingAs(catalogueAdmin())->test('music.catalogue')->call('delete', $track->id);

    expect(Track::count())->toBe(0);
    Storage::disk('music')->assertMissing(['tracks/a.mp3', 'stems/a.zip']);
});

test('a track registered with Content ID cannot be marked stream-safe', function () {
    Volt::actingAs(catalogueAdmin())->test('music.catalogue')
        ->set('title', 'Claimed Song')
        ->set('artist', 'EDOS')
        ->set('contentIdStatus', 'registered')
        ->set('streamSafe', true)
        ->set('file', mp3())
        ->call('save')
        ->assertHasErrors(['streamSafe' => 'declined_if'])
        ->assertSee('Allow-list all four channels with the distributor first');

    expect(Track::count())->toBe(0);
});

test('a registered track can be saved when it is not stream-safe, and an allow-listed one can be stream-safe', function () {
    foreach ([['registered', false], ['allow_listed', true], ['not_registered', true]] as [$status, $safe]) {
        Volt::actingAs(catalogueAdmin())->test('music.catalogue')
            ->set('title', "Song {$status}")
            ->set('artist', 'EDOS')
            ->set('contentIdStatus', $status)
            ->set('streamSafe', $safe)
            ->set('file', mp3())
            ->call('save')
            ->assertHasNoErrors();
    }

    expect(Track::count())->toBe(3);
});

test('an existing stream-safe track cannot be switched to registered while still stream-safe', function () {
    $track = Track::factory()->streamSafe()->create();

    Volt::actingAs(catalogueAdmin())->test('music.catalogue')
        ->call('edit', $track->id)
        ->set('contentIdStatus', 'registered')
        ->call('save')
        ->assertHasErrors(['streamSafe' => 'declined_if']);

    expect($track->refresh()->content_id_status)->toBe(ContentIdStatus::NotRegistered);
});

test('the model refuses a registered stream-safe track from any writer', function () {
    expect(fn () => Track::factory()->create([
        'content_id_status' => ContentIdStatus::Registered,
        'stream_safe' => true,
    ]))->toThrow(InvalidArgumentException::class);

    expect(Track::count())->toBe(0);
});

test('an unknown Content ID status is rejected', function () {
    Volt::actingAs(catalogueAdmin())->test('music.catalogue')
        ->set('title', 'Odd')
        ->set('artist', 'EDOS')
        ->set('contentIdStatus', 'pending')
        ->set('file', mp3())
        ->call('save')
        ->assertHasErrors(['contentIdStatus']);
});
