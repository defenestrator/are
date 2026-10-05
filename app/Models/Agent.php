<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\HasApiTokens;

/**
 * An Orkestera-driven VTuber that runs the show through /api/agent (#10).
 * It authenticates with a Sanctum token from `agent:token`. Its service
 * user is how it takes part in the Chat Control Bus: as one person. It is
 * Authenticatable because the Sanctum guard authenticates it, not a User;
 * it has no password and cannot sign in to the site.
 *
 * @property int $id
 * @property string $name
 * @property int $user_id
 */
class Agent extends Authenticatable
{
    use HasApiTokens;

    /** Every ability an agent token gets by default. */
    public const ABILITIES = ['agent:queue', 'agent:answer', 'agent:avatar', 'agent:bus'];

    protected $fillable = [
        'name',
        'user_id',
    ];

    /**
     * The agent behind a request to /api/agent, if the request carries an
     * agent's token (the Sanctum guard authenticates any tokenable model).
     */
    public static function fromToken(): ?self
    {
        $actor = Auth::guard('sanctum')->user();

        return $actor instanceof self ? $actor : null;
    }

    /**
     * The agent called $name, created with its service user if missing.
     */
    public static function named(string $name): self
    {
        $existing = self::where('name', $name)->first();
        if ($existing !== null) {
            return $existing;
        }

        $user = User::create(['name' => mb_substr($name, 0, 48).' (agent)']);

        return self::create(['name' => $name, 'user_id' => $user->id]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AgentClaim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(AgentClaim::class);
    }
}
