<?php

namespace App\Models;

use App\Enums\ContentIdStatus;
use Database\Factories\TrackFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A track from the original music catalogue. file_path and stems_path are
 * relative to config('music.disk') and are never shown to visitors.
 *
 * @property ContentIdStatus $content_id_status
 * @property bool $stream_safe
 */
class Track extends Model
{
    /** @use HasFactory<TrackFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'artist',
        'file_path',
        'stems_path',
        'content_id_status',
        'stream_safe',
        'duration_seconds',
        'attribution',
    ];

    protected $attributes = [
        'content_id_status' => 'not_registered',
        'stream_safe' => false,
    ];

    protected function casts(): array
    {
        return [
            'content_id_status' => ContentIdStatus::class,
            'stream_safe' => 'boolean',
            'duration_seconds' => 'integer',
        ];
    }

    /**
     * The admin form validates this too; the model refuses it for any other
     * writer, because a registered track on the pack gets other creators claimed.
     */
    protected static function booted(): void
    {
        static::saving(function (Track $track) {
            if ($track->stream_safe && ! $track->content_id_status->permitsStreamSafe()) {
                throw new InvalidArgumentException('A track registered with Content ID cannot be stream-safe until every channel is allow-listed.');
            }
        });
    }

    /**
     * Flagged stream-safe and not registered with Content ID (or registered
     * and allow-listed). These are the tracks in the public stream-safe pack.
     *
     * @param  Builder<Track>  $query
     */
    public function scopeStreamSafe(Builder $query): void
    {
        $query->where('stream_safe', true)
            ->where('content_id_status', '!=', ContentIdStatus::Registered->value);
    }

    /**
     * Tracks viewers may request on stream (the `!song` command, #21). Only
     * stream-safe tracks, so a request can never get the stream claimed.
     *
     * @param  Builder<Track>  $query
     */
    public function scopeRequestable(Builder $query): void
    {
        $query->streamSafe();
    }

    public function isStreamSafe(): bool
    {
        return $this->stream_safe && $this->content_id_status->permitsStreamSafe();
    }

    /**
     * The credit other creators paste into their stream or video description.
     */
    public function creditLine(): string
    {
        $credit = trim((string) $this->attribution) ?: "\"{$this->title}\" by {$this->artist}";

        return $credit.' · '.route('music.index');
    }

    public function formattedDuration(): ?string
    {
        if ($this->duration_seconds === null) {
            return null;
        }

        return intdiv($this->duration_seconds, 60).':'.str_pad((string) ($this->duration_seconds % 60), 2, '0', STR_PAD_LEFT);
    }

    /**
     * The name a download is saved as, e.g. "artist-title.mp3".
     */
    public function downloadName(string $path, string $suffix = ''): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return Str::slug("{$this->artist} {$this->title} {$suffix}").($extension !== '' ? ".{$extension}" : '');
    }
}
