<?php

namespace App\Models;

use App\TwitchSubscription;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'twitch_id',
        'twitch_avatar_url',
        'facebook_id',
        'facebook_avatar_url',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }

    public function questions()
    {
        return $this->hasMany(Question::class);
    }

    static public function getBroadcasterID(): string
    {
        return config('services.twitch.broadcaster_id');
    }

    /**
     * Every Twitch channel this app serves: the primary channel plus any extras.
     *
     * @return list<string>
     */
    static public function getBroadcasterIDs(): array
    {
        return array_values(array_unique(array_filter(array_merge(
            [config('services.twitch.broadcaster_id')],
            config('services.twitch.broadcaster_ids', []),
        ))));
    }

    static public function getAllFriendIDs(): array
    {
        return array_values(array_unique(array_merge(self::getBroadcasterIDs(), config('services.twitch.friend_ids', []))));
    }

    public function isBroadcaster(): bool
    {
        return $this->twitch_id !== null && in_array($this->twitch_id, self::getBroadcasterIDs(), true);
    }

    public function isModerator(): bool
    {
        return $this->twitch_id !== null && TwitchModerator::where('twitch_user_id', $this->twitch_id)
            ->whereIn('broadcaster_id', self::getBroadcasterIDs())
            ->exists();
    }

    public function isAdminUser(): bool
    {
        return $this->isBroadcaster() || $this->isModerator();
    }

    /**
     * Banned or timed out on any channel this app serves.
     */
    public function isBanned(): bool
    {
        return $this->twitch_id !== null && TwitchBan::inEffect()
            ->where('twitch_user_id', $this->twitch_id)
            ->whereIn('broadcaster_id', self::getBroadcasterIDs())
            ->exists();
    }

    public function getHighestSubscription(): TwitchSubscription
    {
        $subscription = UserTwitchSubscription::where('user_id', $this->id)
            ->where('twitch_subscription', '>=', TwitchSubscription::Tier1)
            ->whereIn('broadcaster_id', self::getAllFriendIDs())
            ->orderBy('twitch_subscription', 'desc')
            ->first();

        return $subscription->twitch_subscription ?? TwitchSubscription::None;
    }

    public function canSubmitQuestion(): bool
    {
        if ($this->isBanned()) {
            return false;
        }

        $subscription = $this->getHighestSubscription();

        // Deliberate: non-subscribers can always submit, with no cap. The queue
        // exists to drive engagement, not to sell subscriptions.
        if ($subscription === TwitchSubscription::None) {
            return true;
        }

        return Topic::current() !== null
            && $this->questions()->active()->count() < $subscription->maxActiveQuestions();
    }

    public function votes()
    {
        return $this->hasMany(QuestionVote::class);
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn(string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}
