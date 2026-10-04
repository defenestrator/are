<?php

namespace App\Models;

use App\IdentityProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A chat message that ran (or tried to run) a command. It exists so that
 * each message runs its command at most once. See ChatCommandRegistry::run().
 *
 * @property IdentityProvider $provider
 * @property string $message_id
 * @property string $command
 * @property string|null $status
 */
class ChatCommandRun extends Model
{
    use MassPrunable;

    protected $fillable = [
        'provider',
        'message_id',
        'command',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'provider' => IdentityProvider::class,
        ];
    }

    /**
     * Claim a message for running its command. True for the first claim of a
     * (provider, message id) pair, false for every later one. Atomic: the
     * unique index decides, so concurrent workers cannot both win.
     */
    public static function claim(IdentityProvider $provider, string $messageId, string $command): bool
    {
        $now = now();

        return DB::table('chat_command_runs')->insertOrIgnore([
            'provider' => $provider->value,
            'message_id' => $messageId,
            'command' => $command,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }

    /**
     * Platforms stop redelivering within minutes; a week is ample.
     *
     * @return Builder<ChatCommandRun>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subWeek());
    }
}
