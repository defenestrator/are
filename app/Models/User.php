<?php

namespace App\Models;

use App\TwitchSubscription;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
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

    /**
     * Users whose name contains $term literally (% and _ match themselves),
     * ignoring case.
     *
     * Case: PostgreSQL's LIKE is case-sensitive, so pgsql uses ILIKE. SQLite's
     * LIKE ignores case for ASCII letters, and MySQL's follows the column
     * collation, which is case-insensitive by default (utf8mb4_unicode_ci).
     *
     * Escaping: the explicit ESCAPE is required because SQLite has no default
     * LIKE escape character (MySQL and PostgreSQL default to backslash). The
     * escape character is ! rather than a backslash because a backslash inside
     * the ESCAPE literal needs different quoting on MySQL ('\\') than on SQLite
     * and PostgreSQL ('\'). ESCAPE '!' overrides the default the same way on
     * all three. Laravel's whereLike() picks ILIKE on pgsql but cannot add an
     * ESCAPE clause, hence the raw clause.
     *
     * @param  Builder<User>  $query
     */
    public function scopeWhereNameContains(Builder $query, string $term): void
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);

        $grammar = $query->getQuery()->getGrammar();
        $column = $grammar->wrap($query->qualifyColumn('name'));
        $operator = $grammar instanceof PostgresGrammar ? 'ilike' : 'like';

        $query->whereRaw("{$column} {$operator} ? escape '!'", ['%'.$escaped.'%']);
    }

    public function questions()
    {
        return $this->hasMany(Question::class);
    }

    public static function getBroadcasterID(): string
    {
        return config('services.twitch.broadcaster_id');
    }

    /**
     * Every Twitch channel this app serves: the primary channel plus any extras.
     *
     * @return list<string>
     */
    public static function getBroadcasterIDs(): array
    {
        return array_values(array_unique(array_filter(array_merge(
            [config('services.twitch.broadcaster_id')],
            config('services.twitch.broadcaster_ids', []),
        ))));
    }

    public static function getAllFriendIDs(): array
    {
        return array_values(array_unique(array_merge(self::getBroadcasterIDs(), config('services.twitch.friend_ids', []))));
    }

    public function isBroadcaster(): bool
    {
        return $this->twitch_id !== null && in_array($this->twitch_id, self::getBroadcasterIDs(), true);
    }

    /**
     * Memoised per user instance for the request (keyed on the Twitch ID and
     * served channels), so every @can('moderate') does not re-query. Bans
     * are deliberately not memoised: the moderate gate checks isBanned()
     * live, so a ban landing mid-request still takes effect.
     */
    public function isModerator(): bool
    {
        $twitchId = $this->twitch_id;
        $broadcasterIds = self::getBroadcasterIDs();

        return $twitchId !== null && once(fn () => TwitchModerator::where('twitch_user_id', $twitchId)
            ->whereIn('broadcaster_id', $broadcasterIds)
            ->exists());
    }

    public function isAdminUser(): bool
    {
        return $this->isBroadcaster() || $this->isModerator();
    }

    /**
     * Banned or timed out here (a local ban), or on any Twitch channel this app serves.
     */
    public function isBanned(): bool
    {
        return $this->isLocallyBanned() || $this->isTwitchBanned();
    }

    /**
     * Banned or timed out by a moderator in ARE. Keyed on the user, so it
     * applies however they signed in.
     */
    public function isLocallyBanned(): bool
    {
        return $this->localBans()->inEffect()->exists();
    }

    /**
     * Banned or timed out on any Twitch channel this app serves.
     */
    public function isTwitchBanned(): bool
    {
        return $this->twitch_id !== null && TwitchBan::inEffect()
            ->where('twitch_user_id', $this->twitch_id)
            ->whereIn('broadcaster_id', self::getBroadcasterIDs())
            ->exists();
    }

    /**
     * Every local ban ever placed on this user, lifted or not. Use the
     * UserBan::inEffect() scope for the ones that still apply.
     *
     * @return HasMany<UserBan, $this>
     */
    public function localBans(): HasMany
    {
        return $this->hasMany(UserBan::class);
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
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}
