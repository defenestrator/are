<?php

namespace Database\Seeders;

use App\Enums\Overlay;
use App\IdentityProvider;
use App\Models\OverlayToken;
use App\Models\Question;
use App\Models\ShortLink;
use App\Models\Topic;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Data for the browser end-to-end suite (#155): `php artisan db:seed
 * --class=E2eSeeder` on a fresh database. Writes what the specs need (user
 * ids, overlay tokens, the short-link code) to e2e/.fixtures.json, which is
 * git-ignored. Refuses to run outside APP_ENV=testing, because it mints live
 * overlay tokens.
 */
class E2eSeeder extends Seeder
{
    public const FIXTURES = 'e2e/.fixtures.json';

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('E2eSeeder only runs with APP_ENV=testing.');
        }

        $broadcasterId = (string) config('services.twitch.broadcaster_id');
        if ($broadcasterId === '') {
            throw new RuntimeException('Set TWITCH_CHANNEL_ID so the suite has a broadcaster to sign in as.');
        }

        $broadcaster = User::factory()->withIdentity(IdentityProvider::Twitch, $broadcasterId)->create(['name' => 'E2E Broadcaster']);
        $viewer = User::factory()->withIdentity(IdentityProvider::Twitch, '900001')->create(['name' => 'E2E Viewer']);
        $otherViewer = User::factory()->withIdentity(IdentityProvider::Twitch, '900002')->create(['name' => 'E2E Other Viewer']);

        Topic::set('Songs about kale');
        $question = Question::create(['user_id' => $otherViewer->id, 'question' => 'A seeded question to vote on']);

        $track = Track::factory()->streamSafe()->create(['title' => 'E2E Stream Safe Song', 'artist' => 'EDOS']);

        // A short link back to /about, so the attribution flow stays on this server.
        $shortLink = ShortLink::for('/about', 'twitch', 'stream', 'e2e-smoke', 'chat');

        $tokens = [];
        foreach (Overlay::cases() as $overlay) {
            $tokens[$overlay->value] = OverlayToken::issue($overlay);
        }

        $fixtures = [
            'broadcaster' => $broadcaster->id,
            'viewer' => $viewer->id,
            'otherViewer' => $otherViewer->id,
            'question' => $question->id,
            'track' => $track->id,
            'shortLinkCode' => $shortLink->code,
            'overlayTokens' => $tokens,
        ];

        file_put_contents(base_path(self::FIXTURES), json_encode($fixtures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
