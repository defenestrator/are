<?php

use App\Models\ModerationAction;
use App\Models\MusicPlayerToken;
use App\Models\TwitchModerator;
use App\Models\User;

/*
 * #149: the audit log must say who acted. CLI actions have no moderator, and
 * used to read as "deleted user".
 */

function auditModerator(string $name = 'Mod Person'): User
{
    $mod = User::factory()->twitch()->create(['name' => $name]);
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);

    return $mod;
}

beforeEach(function () {
    config(['services.twitch.broadcaster_id' => '1000']);
});

test('bus:kill and bus:kill --off are recorded as the CLI, with the command', function () {
    $this->artisan('bus:kill')->assertSuccessful();
    $this->artisan('bus:kill', ['--off' => true])->assertSuccessful();

    $killed = ModerationAction::where('action', 'bus.killed')->sole();
    $restored = ModerationAction::where('action', 'bus.restored')->sole();

    expect($killed->moderator_id)->toBeNull()
        ->and($killed->details)->toMatchArray(['via' => 'cli', 'command' => 'bus:kill'])
        ->and($killed->actorName())->toBe('CLI (bus:kill)')
        ->and($restored->actorName())->toBe('CLI (bus:kill --off)');

    $this->actingAs(auditModerator())->get(route('moderation'))
        ->assertOk()
        ->assertSee('CLI (bus:kill)')
        ->assertSee('CLI (bus:kill --off)')
        ->assertDontSee('deleted user');
});

test('a CLI action recorded before the command was kept still reads as the CLI', function () {
    $action = ModerationAction::record(null, 'bus.killed', null, ['via' => 'cli', 'reason' => 'bus:kill']);

    expect($action->actorName())->toBe('CLI');
});

test('moderators, players and deleted moderators are each named for what they are', function () {
    $mod = auditModerator('Mod Person');
    $byMod = ModerationAction::record($mod, 'topic.set', null, ['topic' => 'Tea']);
    $byPlayer = ModerationAction::recordForPlayer(MusicPlayerToken::make(['name' => 'obs']), 'song_request.advanced');

    $gone = auditModerator('Gone Person');
    $byGone = ModerationAction::record($gone, 'topic.cleared');
    $gone->delete();

    expect($byMod->fresh()->actorName())->toBe('Mod Person')
        ->and($byPlayer->fresh()->actorName())->toBe('player obs')
        ->and($byGone->fresh()->moderator_id)->toBeNull()
        ->and($byGone->fresh()->actorName())->toBe('deleted user');
});
