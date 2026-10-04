<?php

namespace App\Analytics;

use App\Models\StreamSession;

/**
 * Audience numbers for one Twitch stream session (#12).
 *
 * - Average and peak concurrent viewers come from Helix Get Streams samples
 *   taken every few minutes while the stream is live (SampleTwitchViewers).
 * - Unique chatters counts distinct chatters during the session, excluding
 *   the broadcaster (RecordTwitchChatter).
 * - Participation is unique chatters ÷ average concurrent viewers. It is an
 *   approximation, and it reads high: viewers come and go, so more people
 *   watch a stream than its average concurrent count, while every chatter is
 *   counted. Compare streams with it, not as an absolute rate.
 */
final class StreamMetrics
{
    public function __construct(
        public readonly StreamSession $session,
        public readonly ?float $averageViewers,
        public readonly ?int $peakViewers,
        public readonly int $samples,
        public readonly int $uniqueChatters,
    ) {}

    /**
     * Sessions that started in the range, oldest first.
     *
     * @return list<self>
     */
    public static function forRange(DateRange $range): array
    {
        return StreamSession::query()
            ->where('started_at', '>=', $range->from)
            ->where('started_at', '<', $range->until)
            ->withAvg('viewerSamples', 'viewer_count')
            ->withMax('viewerSamples', 'viewer_count')
            ->withCount(['viewerSamples', 'chatters'])
            ->orderBy('started_at')
            ->orderBy('id')
            ->get()
            ->map(fn (StreamSession $session) => new self(
                $session,
                $session->viewer_samples_avg_viewer_count === null ? null : (float) $session->viewer_samples_avg_viewer_count,
                $session->viewer_samples_max_viewer_count === null ? null : (int) $session->viewer_samples_max_viewer_count,
                (int) $session->viewer_samples_count,
                (int) $session->chatters_count,
            ))
            ->values()
            ->all();
    }

    /** The stream's utm_campaign, the key its short-link clicks and enquiries are grouped under. */
    public function stream(): string
    {
        return $this->session->utmCampaign();
    }

    /** Unique chatters ÷ average concurrent viewers, or null without viewer samples. */
    public function participation(): ?float
    {
        return $this->averageViewers === null || $this->averageViewers <= 0.0
            ? null
            : $this->uniqueChatters / $this->averageViewers;
    }

    public function averageLabel(): string
    {
        return $this->averageViewers === null ? '—' : number_format($this->averageViewers, 0);
    }

    public function peakLabel(): string
    {
        return $this->peakViewers === null ? '—' : number_format($this->peakViewers);
    }

    /** "≈ 43%", marked as an approximation, or "—" without viewer samples. */
    public function participationLabel(): string
    {
        $participation = $this->participation();

        return $participation === null ? '—' : '≈ '.number_format($participation * 100, 0).'%';
    }
}
