<?php

namespace Database\Seeders;

use App\Enums\Overlay;
use App\IdentityProvider;
use App\Models\OverlayToken;
use App\Models\Question;
use App\Models\Topic;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Data for the load test (#176, scripts/loadtest/): `php artisan db:seed
 * --class=LoadtestSeeder` on a fresh CI or staging database.
 *
 * Creates LOADTEST_VIEWERS viewers (default 100), each with a Twitch identity
 * so chat commands resolve to them, a topic, a queue of questions and a token
 * for every overlay. Writes the ids and tokens to
 * scripts/loadtest/.fixtures.json, which is git-ignored. Refuses to run
 * outside APP_ENV=testing, because it mints live overlay tokens.
 */
class LoadtestSeeder extends Seeder
{
    public const FIXTURES = 'scripts/loadtest/.fixtures.json';

    /** Twitch user ids of the seeded viewers start here. */
    public const FIRST_TWITCH_ID = 800000;

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('LoadtestSeeder only runs with APP_ENV=testing (CI or staging), never production.');
        }

        $broadcasterId = (string) config('services.twitch.broadcaster_id');
        if ($broadcasterId === '') {
            throw new RuntimeException('Set TWITCH_CHANNEL_ID: the chat-command burst is sent as that channel\'s chat.');
        }

        $count = max(1, (int) env('LOADTEST_VIEWERS', 100));
        $viewers = [];
        $chatters = [];

        for ($i = 0; $i < $count; $i++) {
            $twitchId = (string) (self::FIRST_TWITCH_ID + $i);
            $viewers[] = User::factory()->withIdentity(IdentityProvider::Twitch, $twitchId)->create(['name' => "Load Viewer {$i}"])->id;
            $chatters[] = $twitchId;
        }

        Topic::set('Load test');

        $questions = [];
        foreach (array_slice($viewers, 0, min(30, $count)) as $i => $userId) {
            $questions[] = Question::create(['user_id' => $userId, 'question' => "Seeded load-test question {$i}"])->id;
        }

        Track::factory()->streamSafe()->count(5)->create();

        $tokens = [];
        foreach (Overlay::cases() as $overlay) {
            $tokens[$overlay->value] = OverlayToken::issue($overlay);
        }

        file_put_contents(base_path(self::FIXTURES), json_encode([
            'broadcasterId' => $broadcasterId,
            'viewers' => $viewers,
            'chatters' => $chatters,
            'questions' => $questions,
            'overlayTokens' => $tokens,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
