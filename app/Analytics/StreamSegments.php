<?php

namespace App\Analytics;

use App\Models\StreamSession;
use App\Models\Topic;
use Carbon\CarbonImmutable;

/**
 * A stream session split into segments by topic, with ARE's counts for each
 * segment and for the whole stream (#12).
 *
 * Why topics, not fixed 15-minute buckets: a topic is the show's own
 * structure. A moderator sets it, the queue fills with questions about it,
 * then it changes, so a topic's numbers say which part of the show people
 * joined in on. A fixed bucket would cut one discussion in two at an
 * arbitrary minute. Topics also need no new storage: Topic::set (which
 * TopicChanged announces) archives the old topic and creates the next one, so
 * every topic's created_at and archived_at is already there, for past streams
 * too.
 *
 * A segment is where a topic's lifetime overlaps the session. Time in the
 * session with no topic set is its own "(no topic)" segment, so nothing is
 * dropped.
 *
 * Counts are by time, except clip markers, which belong to their session.
 * Topics and the vote queue are shared by every channel, so if two served
 * channels stream at once, the same questions and votes appear on both.
 */
final class StreamSegments
{
    /** "Who drove it" until the VTuber bridge (#10) can drive a segment. */
    public const DRIVER_HUMAN = 'human';

    /**
     * @param  list<array{topic: ?string, from: CarbonImmutable, until: CarbonImmutable, driver: string, counts: SegmentCounts}>  $segments
     */
    private function __construct(
        public readonly StreamSession $session,
        public readonly array $segments,
        public readonly SegmentCounts $total,
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
            ->orderBy('started_at')
            ->orderBy('id')
            ->get()
            ->map(fn (StreamSession $session) => self::for($session))
            ->values()
            ->all();
    }

    public static function for(StreamSession $session): self
    {
        $start = CarbonImmutable::parse($session->started_at);
        $end = CarbonImmutable::parse($session->ended_at ?? now());

        $topics = Topic::query()
            ->where('created_at', '<', $end)
            ->where(fn ($q) => $q->whereNull('archived_at')->orWhere('archived_at', '>', $start))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        // Every moment a topic started or ended inside the session.
        $cuts = collect([$start, $end]);
        foreach ($topics as $topic) {
            foreach ([$topic->created_at, $topic->archived_at] as $at) {
                if ($at !== null && $at->gt($start) && $at->lt($end)) {
                    $cuts->push(CarbonImmutable::parse($at));
                }
            }
        }
        $cuts = $cuts->unique(fn (CarbonImmutable $at) => $at->getTimestamp())->sortBy(fn (CarbonImmutable $at) => $at->getTimestamp())->values();

        $segments = [];
        for ($i = 0; $i < $cuts->count() - 1; $i++) {
            $from = $cuts[$i];
            $until = $cuts[$i + 1];

            $active = $topics->last(fn (Topic $t) => CarbonImmutable::parse($t->created_at)->lte($from)
                && ($t->archived_at === null || CarbonImmutable::parse($t->archived_at)->gt($from)));
            $label = $active?->topic;

            // Two pieces under the same topic (or both with none) are one segment.
            $last = array_key_last($segments);
            if ($last !== null && $segments[$last]['topic_id'] === $active?->id) {
                $segments[$last]['until'] = $until;

                continue;
            }

            $segments[] = ['topic_id' => $active?->id, 'topic' => $label, 'from' => $from, 'until' => $until];
        }

        $total = new SegmentCounts;
        $built = [];
        foreach ($segments as $segment) {
            $counts = SegmentCounts::between($segment['from'], $segment['until'], $session->id);
            $total = $total->plus($counts);
            $built[] = [
                'topic' => $segment['topic'],
                'from' => $segment['from'],
                'until' => $segment['until'],
                'driver' => self::DRIVER_HUMAN,
                'counts' => $counts,
            ];
        }

        return new self($session, $built, $total);
    }

    public function stream(): string
    {
        return $this->session->utmCampaign();
    }
}
