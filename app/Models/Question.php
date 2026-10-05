<?php

namespace App\Models;

use App\Events\VoteCast;
use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    /** questions.source for the vote page. Chat questions store their platform instead. */
    public const SOURCE_WEB = 'web';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'vote_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Questions still in the queue (not archived by clearing a topic).
     *
     * @param  Builder<Question>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('questions.archived_at');
    }

    public static function getSortedQuestions($limit = 50)
    {
        return self::withVoteTotals()
            ->orderBy('votes', 'desc')
            ->limit($limit)
            ->with('user.identities')
            ->get();
    }

    public static function getRecentQuestions($limit = 50)
    {
        return self::withVoteTotals()
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->with('user.identities')
            ->get();
    }

    /**
     * Open questions with their vote total selected as `votes`.
     *
     * The total is a correlated subquery, so only open questions' votes are
     * read, each through the question_id index. A join grouped over
     * question_votes hash-joins every vote ever cast, archived streams
     * included (#179). The total is summed on read, not stored on the
     * question, because votes also disappear through database cascades
     * (deleting a user or a question) that no application code sees, and a
     * stored total would silently drift.
     *
     * @return Builder<Question>
     */
    private static function withVoteTotals(): Builder
    {
        return self::query()
            ->active()
            ->select('questions.*')
            ->selectSub(
                fn ($query) => $query->from('question_votes')
                    ->selectRaw('coalesce(sum(question_votes.count), 0)')
                    ->whereColumn('question_votes.question_id', 'questions.id'),
                'votes',
            );
    }

    public function voteCount(): int
    {
        return (int) QuestionVote::where('question_id', $this->id)->sum('count');
    }

    /**
     * Record $user's vote (+1 or -1) and broadcast the new total.
     *
     * @return array{votes: int, version: int} the total and version, read together
     */
    public function recordVote(User $user, int $count): array
    {
        return DB::transaction(function () use ($user, $count) {
            $locked = self::lockedForVoteChange($this->id);

            // created_at is when this person first voted on the question and
            // updated_at when they last changed it, so votes can be counted by
            // time (#12). The primary key (user_id, question_id) is the conflict.
            $now = now();
            DB::table('question_votes')->upsert(
                [['question_id' => $this->id, 'user_id' => $user->id, 'count' => $count, 'created_at' => $now, 'updated_at' => $now]],
                ['user_id', 'question_id'],
                ['count', 'updated_at'],
            );

            return $locked->announceVoteChange();
        }, attempts: 3);
    }

    /**
     * Lock the row so concurrent vote changes on this question run one at a
     * time; each then reads its total and version without another slipping in.
     * Call inside a transaction, before changing the question's votes.
     */
    public static function lockedForVoteChange(int $id): self
    {
        return self::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Bump the vote version and broadcast it with the total. Call inside the
     * transaction that holds lockedForVoteChange(); VoteCast goes out after it
     * commits, and browsers ignore any VoteCast older than the one they show.
     *
     * @return array{votes: int, version: int}
     */
    public function announceVoteChange(): array
    {
        $this->increment('vote_version');
        $result = ['votes' => $this->voteCount(), 'version' => (int) $this->vote_version];

        VoteCast::dispatch($this->id, $result['votes'], $result['version']);

        return $result;
    }

    /**
     * The vote page's two lists. They are the same for every viewer, so a burst
     * of refreshes after a new question costs one pair of aggregate queries.
     *
     * One fixed key holds the lists together with the queue version they were
     * built from, so the database cache store keeps a single row instead of one
     * per vote. A copy built from any version but the current one (see
     * forgetCachedQueue) is rebuilt, so a slow reader that stores pre-change
     * lists late is simply rebuilt by the next reader.
     *
     * @return array{top: Collection<int, Question>, recent: Collection<int, Question>}
     */
    public static function cachedQueue(): array
    {
        $version = Cache::get('questions.queue-version');
        if ($version === null) {
            // Evicted or cleared: start a new version so it never matches an old
            // copy. add() only writes if the key is still missing (atomically,
            // given a TTL), so readers racing here end up on the version that won.
            $candidate = (string) Str::ulid();
            Cache::add('questions.queue-version', $candidate, now()->addDays(30));
            $version = Cache::get('questions.queue-version') ?? $candidate;
        }

        $cached = Cache::get('questions.queue');
        if (is_array($cached) && ($cached['version'] ?? null) === $version) {
            return $cached['lists'];
        }

        $lists = [
            'top' => self::getSortedQuestions(),
            'recent' => self::getRecentQuestions(),
        ];
        Cache::put('questions.queue', ['version' => $version, 'lists' => $lists], now()->addMinutes(10));

        return $lists;
    }

    /**
     * Mark every cached copy of the queue as out of date.
     */
    public static function forgetCachedQueue(): string
    {
        $version = (string) Str::ulid();
        Cache::forever('questions.queue-version', $version);

        return $version;
    }
}
