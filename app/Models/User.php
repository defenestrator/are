<?php

namespace App\Models;

use App\IdentityProvider;
use App\Support\RequestMemo;
use App\TwitchSubscription;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    // Tokens here are moderators' kill-switch tokens (agent:kill-token, #10).
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable. Platform ids live on
     * identities; link them through App\Identities, not here.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
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

    /**
     * The platform accounts this person signs in or chats with.
     *
     * @return HasMany<Identity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(Identity::class);
    }

    /**
     * One-time codes for linking a chat account with `!link CODE`.
     *
     * @return HasMany<LinkCode, $this>
     */
    public function linkCodes(): HasMany
    {
        return $this->hasMany(LinkCode::class);
    }

    /**
     * This user's account on $provider, if linked. Reads the loaded relation,
     * so eager-load `identities` when listing users.
     */
    public function identityFor(IdentityProvider $provider): ?Identity
    {
        return $this->identities->first(fn (Identity $identity) => $identity->provider === $provider);
    }

    /**
     * Kept as read-only accessors so callers that predate identities still work.
     *
     * @return Attribute<mixed, never>
     */
    protected function twitchId(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->identityFor(IdentityProvider::Twitch)?->provider_user_id);
    }

    /**
     * @return Attribute<mixed, never>
     */
    protected function twitchAvatarUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->identityFor(IdentityProvider::Twitch)?->avatar_url);
    }

    /**
     * The picture to show for this person: the first avatar among their
     * identities, oldest first, so it comes from the account they first
     * signed in with (the one that names them) when that account has one.
     * Null when none has a picture; views fall back to initials.
     *
     * @return Attribute<mixed, never>
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->identities
            ->sortBy('id')
            ->map(fn (Identity $identity) => $identity->avatar_url)
            ->first(fn (?string $url) => filled($url)));
    }

    /**
     * @return Attribute<mixed, never>
     */
    protected function facebookId(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->identityFor(IdentityProvider::Facebook)?->provider_user_id);
    }

    /**
     * @return Attribute<mixed, never>
     */
    protected function facebookAvatarUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->identityFor(IdentityProvider::Facebook)?->avatar_url);
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
     * The broadcaster of this one channel, which must be one this app serves.
     */
    public function isBroadcasterOf(string $broadcasterId): bool
    {
        return $this->twitch_id !== null
            && $this->twitch_id === $broadcasterId
            && in_array($broadcasterId, self::getBroadcasterIDs(), true);
    }

    /**
     * A Twitch moderator of this one channel, which must be one this app
     * serves. Use this, not isModerator(), for anything that acts on a
     * channel (chat commands, Helix calls): a moderator of channel B has no
     * powers on channel A.
     *
     * Memoised like isModerator(), per user instance and per channel (once()
     * keys on the Twitch ID and broadcaster ID the closure uses).
     */
    public function isModeratorOf(string $broadcasterId): bool
    {
        $twitchId = $this->twitch_id;

        return $twitchId !== null
            && in_array($broadcasterId, self::getBroadcasterIDs(), true)
            && once(fn () => TwitchModerator::where('twitch_user_id', $twitchId)
                ->where('broadcaster_id', $broadcasterId)
                ->exists());
    }

    /**
     * A moderator of ANY channel this app serves. The web vote queue, topic
     * and moderation page are one queue shared by every served channel
     * (topics and questions carry no channel), so the global `moderate` gate
     * uses this. For a channel-scoped action use isModeratorOf().
     *
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
        $standing = $this->banStanding();

        return $standing['local'] || $standing['twitch'];
    }

    /**
     * Both ban answers in one query, memoised for the request (#173): a
     * /vote render asked them once per card, gate and component.
     *
     * @return array{local: bool, twitch: bool}
     */
    public function banStanding(): array
    {
        return app(RequestMemo::class)->remember('bans.'.$this->id, function () {
            $local = UserBan::inEffect()->appliesTo($this)->toBase();
            $twitch = $this->twitchBans()->toBase();

            $row = DB::query()->selectRaw(
                'exists('.$local->toSql().') as local, exists('.$twitch->toSql().') as twitch',
                [...$local->getBindings(), ...$twitch->getBindings()],
            )->first();

            return ['local' => (bool) $row->local, 'twitch' => (bool) $row->twitch];
        });
    }

    /**
     * Banned or timed out by a moderator in ARE. Matches the user and every
     * platform account they hold, so it applies however they signed in and
     * survives the account being deleted and re-created.
     */
    public function isLocallyBanned(): bool
    {
        return $this->banStanding()['local'];
    }

    /**
     * Banned or timed out on any Twitch channel this app serves, through any
     * of the user's identities. A platform ban on one identity bans the person.
     */
    public function isTwitchBanned(): bool
    {
        return $this->banStanding()['twitch'];
    }

    /**
     * Twitch bans in effect on any of this user's Twitch identities, on any
     * channel this app serves.
     *
     * @return Builder<TwitchBan>
     */
    private function twitchBans(): Builder
    {
        return TwitchBan::inEffect()
            ->whereIn('twitch_user_id', $this->identities()
                ->where('provider', IdentityProvider::Twitch)
                ->select('provider_user_id'))
            ->whereIn('broadcaster_id', self::getBroadcasterIDs());
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
