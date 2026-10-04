<?php

namespace App\Models;

use Database\Factories\ShortLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A UTM-tagged short link: GET /go/{code} counts the click, remembers the
 * UTM params in the session for lead attribution, and 302s to the
 * destination with the UTM params appended.
 *
 * The public interface is deliberately small, because overlays, clips and
 * analytics build on it:
 *
 *   $link = ShortLink::for('/about#work-with-us', 'twitch', 'stream', '2026-10-04-orkestera', 'overlay');
 *   $link->url();            // https://app/go/k3x9q2 (what you show on stream)
 *   $link->destinationUrl(); // https://app/about?utm_source=twitch&...#work-with-us
 *   ShortLink::attribution(); // UTM + short_link_id of the last link this session clicked
 *
 * Convention: utm_source is the channel (twitch, youtube), utm_medium is the
 * surface (stream, clip, vod), utm_campaign names the stream, and
 * utm_content says where on that surface the link appeared (overlay, chat).
 *
 * @property int $id
 * @property string $code
 * @property string $destination
 * @property string $tuple_hash
 * @property string $utm_source
 * @property string $utm_medium
 * @property string $utm_campaign
 * @property string|null $utm_content
 * @property int $clicks
 */
class ShortLink extends Model
{
    /** @use HasFactory<ShortLinkFactory> */
    use HasFactory;

    /** Session key holding the last-clicked link's attribution. */
    public const SESSION_KEY = 'lead_attribution';

    public const UTM_FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'];

    protected $fillable = [
        'code',
        'destination',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
    ];

    protected $attributes = [
        'clicks' => 0,
    ];

    protected function casts(): array
    {
        return [
            'clicks' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // tuple_hash backs the unique index that makes one destination + UTM
        // tuple map to exactly one code. destination is too long for a
        // composite unique index on MySQL, so the index is on its hash.
        static::saving(function (ShortLink $link) {
            $link->tuple_hash = static::tupleHash(
                $link->destination,
                $link->utm_source,
                $link->utm_medium,
                $link->utm_campaign,
                $link->utm_content,
            );
        });
    }

    /**
     * Find or create the short link for this destination and UTM tuple, so
     * callers can ask for a link on every render without minting duplicates.
     * Concurrent first calls are safe: the loser of the insert race hits the
     * unique index on tuple_hash and firstOrCreate returns the winner's row.
     */
    public static function for(
        string $destination,
        string $source,
        string $medium,
        string $campaign,
        ?string $content = null,
    ): self {
        $content = $content === '' ? null : $content;

        return static::firstOrCreate(
            ['tuple_hash' => static::tupleHash($destination, $source, $medium, $campaign, $content)],
            fn () => [
                'code' => static::generateCode(),
                'destination' => $destination,
                'utm_source' => $source,
                'utm_medium' => $medium,
                'utm_campaign' => $campaign,
                'utm_content' => $content,
            ],
        );
    }

    /** SHA-256 of the destination and UTM tuple; unique across short_links. */
    public static function tupleHash(
        string $destination,
        string $source,
        string $medium,
        string $campaign,
        ?string $content = null,
    ): string {
        return hash('sha256', (string) json_encode([$destination, $source, $medium, $campaign, $content === '' ? null : $content]));
    }

    /**
     * The attribution stored by the last short link clicked in this session:
     * the utm_* keys plus short_link_id, or an empty array.
     *
     * @return array<string, int|string|null>
     */
    public static function attribution(): array
    {
        $stored = session(self::SESSION_KEY);

        return is_array($stored) ? $stored : [];
    }

    public static function generateCode(int $length = 6): string
    {
        do {
            $code = Str::lower(Str::random($length));
        } while (static::where('code', $code)->exists());

        return $code;
    }

    /** The short URL to show viewers. */
    public function url(): string
    {
        return route('short-links.go', $this->code);
    }

    /** The destination with this link's UTM params appended, keeping any fragment. */
    public function destinationUrl(): string
    {
        [$base, $fragment] = array_pad(explode('#', $this->destination, 2), 2, null);

        $url = url($base ?? '/');
        $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($this->utm());

        return $fragment === null ? $url : $url.'#'.$fragment;
    }

    /**
     * Record a click: count it atomically and remember its attribution in
     * the session (last click wins).
     */
    public function recordClick(): void
    {
        $this->increment('clicks');

        session()->put(self::SESSION_KEY, [...$this->utm(), 'short_link_id' => $this->id]);
    }

    /**
     * The non-empty UTM params, keyed by their query-string names.
     *
     * @return array<string, string>
     */
    public function utm(): array
    {
        return array_filter(
            $this->only(self::UTM_FIELDS),
            fn ($value) => $value !== null && $value !== '',
        );
    }
}
