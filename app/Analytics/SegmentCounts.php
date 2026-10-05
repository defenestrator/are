<?php

namespace App\Analytics;

use App\ControlBus\ApprovalStatus;
use App\Models\Question;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * What the audience did on ARE in one stretch of time (#12): counts only,
 * never who.
 *
 * - Questions by where they were asked: "web" (the vote page), a chat
 *   platform ("twitch", "youtube", ...), or "unknown" for questions submitted
 *   before questions.source existed.
 * - Votes: people's first vote on a question in the window. Changing a vote
 *   is not a second vote. Votes cast before votes were dated are in no window.
 * - Bus: chat ballots, publications sent to the game, publications vetoed
 *   (by a moderator or the kill switch), and free-text actions approved.
 * - Song requests made, and clip markers placed on the stream.
 */
final class SegmentCounts
{
    /**
     * @param  array<string, int>  $questions  source => count
     */
    public function __construct(
        public readonly array $questions = [],
        public readonly int $votes = 0,
        public readonly int $ballots = 0,
        public readonly int $published = 0,
        public readonly int $vetoed = 0,
        public readonly int $approved = 0,
        public readonly int $songRequests = 0,
        public readonly int $clipsMarked = 0,
    ) {}

    /**
     * Counts for [$from, $until). Clip markers are counted only on the given
     * session, because each marker knows its stream; everything else is
     * counted by time.
     */
    public static function between(CarbonInterface $from, CarbonInterface $until, ?int $streamSessionId = null): self
    {
        $window = fn ($query, string $column = 'created_at') => $query->where($column, '>=', $from)->where($column, '<', $until);

        $questions = $window(DB::table('questions'))
            ->groupBy('source')
            ->select('source')
            ->selectRaw('count(*) as total')
            ->pluck('total', 'source');

        $bySource = [];
        foreach ($questions as $source => $total) {
            $key = $source === null || $source === '' ? 'unknown' : (string) $source;
            $bySource[$key] = ($bySource[$key] ?? 0) + (int) $total;
        }
        ksort($bySource);

        return new self(
            questions: $bySource,
            votes: $window(DB::table('question_votes'))->count(),
            ballots: $window(DB::table('bus_ballots'))->count(),
            published: $window(DB::table('bus_publications'))->count(),
            vetoed: $window(DB::table('bus_publications'), 'vetoed_at')->count(),
            approved: $window(DB::table('bus_approvals')->where('status', ApprovalStatus::Approved->value), 'decided_at')->count(),
            songRequests: $window(DB::table('song_requests'))->count(),
            clipsMarked: $streamSessionId === null ? 0 : $window(DB::table('stream_markers')->where('stream_session_id', $streamSessionId))->count(),
        );
    }

    public function totalQuestions(): int
    {
        return array_sum($this->questions);
    }

    public function webQuestions(): int
    {
        return $this->questions[Question::SOURCE_WEB] ?? 0;
    }

    /**
     * Chat questions by platform, e.g. ['twitch' => 4, 'youtube' => 1].
     *
     * @return array<string, int>
     */
    public function chatQuestions(): array
    {
        return array_diff_key($this->questions, [Question::SOURCE_WEB => true, 'unknown' => true]);
    }

    /** "3 web · 4 twitch · 1 youtube", or "—" with none. */
    public function questionsLabel(): string
    {
        $parts = [];
        if ($this->webQuestions() > 0) {
            $parts[] = $this->webQuestions().' web';
        }
        foreach ($this->chatQuestions() as $platform => $count) {
            $parts[] = $count.' '.$platform.' chat';
        }
        if (($this->questions['unknown'] ?? 0) > 0) {
            $parts[] = $this->questions['unknown'].' unknown';
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    public function plus(self $other): self
    {
        $questions = $this->questions;
        foreach ($other->questions as $source => $count) {
            $questions[$source] = ($questions[$source] ?? 0) + $count;
        }
        ksort($questions);

        return new self(
            $questions,
            $this->votes + $other->votes,
            $this->ballots + $other->ballots,
            $this->published + $other->published,
            $this->vetoed + $other->vetoed,
            $this->approved + $other->approved,
            $this->songRequests + $other->songRequests,
            $this->clipsMarked + $other->clipsMarked,
        );
    }

    public function isEmpty(): bool
    {
        return $this->totalQuestions() === 0 && $this->votes === 0 && $this->ballots === 0 && $this->published === 0
            && $this->vetoed === 0 && $this->approved === 0 && $this->songRequests === 0 && $this->clipsMarked === 0;
    }
}
