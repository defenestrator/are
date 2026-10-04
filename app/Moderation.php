<?php

namespace App;

use App\Models\ModerationAction;
use App\Models\Question;
use App\Models\Topic;
use App\Models\User;
use App\Models\UserBan;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Moderator actions. Every one checks the actor and writes to the audit log.
 */
class Moderation
{
    /**
     * Ban a user locally, for $minutes or permanently when $minutes is null.
     */
    public static function ban(User $moderator, User $target, ?int $minutes, ?string $reason = null): UserBan
    {
        self::authorize($moderator);

        if ($target->is($moderator)) {
            throw new InvalidArgumentException('You cannot ban yourself.');
        }
        if ($target->isBroadcaster()) {
            throw new InvalidArgumentException('A broadcaster cannot be banned here.');
        }
        if ($minutes !== null && $minutes < 1) {
            throw new InvalidArgumentException('A timeout must last at least one minute.');
        }

        return DB::transaction(function () use ($moderator, $target, $minutes, $reason) {
            $ban = $target->localBans()->create([
                'moderator_id' => $moderator->id,
                'reason' => $reason,
                'ends_at' => $minutes === null ? null : now()->addMinutes($minutes),
            ]);

            ModerationAction::record($moderator, 'user.banned', $target, [
                'minutes' => $minutes,
                'reason' => $reason,
            ]);

            return $ban;
        });
    }

    /**
     * Lift every local ban in effect for the user. Twitch bans are lifted on Twitch.
     */
    public static function unban(User $moderator, User $target): int
    {
        self::authorize($moderator);

        return DB::transaction(function () use ($moderator, $target) {
            $lifted = $target->localBans()->inEffect()->update(['lifted_at' => now()]);
            ModerationAction::record($moderator, 'user.unbanned', $target, ['lifted' => $lifted]);

            return $lifted;
        });
    }

    /**
     * Authors may delete their own question; moderators may delete any, and
     * those deletions are logged with the text so the record survives.
     */
    public static function deleteQuestion(User $actor, Question $question): void
    {
        $isAuthor = $actor->id === $question->user_id;
        if (! $isAuthor && ! $actor->isAdminUser()) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $question, $isAuthor) {
            if (! $isAuthor) {
                ModerationAction::record($actor, 'question.deleted', $question, [
                    'question' => $question->question,
                    'author_id' => $question->user_id,
                ]);
            }
            $question->delete();
        });
    }

    /**
     * Fold a duplicate into the question it repeats. Votes move to the target,
     * except where the voter already voted on the target, so nobody counts twice.
     */
    public static function mergeQuestions(User $moderator, Question $duplicate, Question $target): void
    {
        self::authorize($moderator);

        if ($duplicate->is($target)) {
            throw new InvalidArgumentException('A question cannot be merged into itself.');
        }
        if ($duplicate->archived_at !== null || $target->archived_at !== null) {
            throw new InvalidArgumentException('Only questions still in the queue can be merged.');
        }

        DB::transaction(function () use ($moderator, $duplicate, $target) {
            $alreadyVoted = DB::table('question_votes')->where('question_id', $target->id)->pluck('user_id');

            $moved = DB::table('question_votes')
                ->where('question_id', $duplicate->id)
                ->whereNotIn('user_id', $alreadyVoted)
                ->update(['question_id' => $target->id]);

            ModerationAction::record($moderator, 'question.merged', $target, [
                'duplicate_id' => $duplicate->id,
                'duplicate' => $duplicate->question,
                'votes_moved' => $moved,
            ]);

            $duplicate->delete();
        });
    }

    public static function setTopic(User $moderator, string $topic): Topic
    {
        self::authorize($moderator);

        return DB::transaction(function () use ($moderator, $topic) {
            $new = Topic::set($topic);
            ModerationAction::record($moderator, 'topic.set', $new, ['topic' => $topic]);

            return $new;
        });
    }

    public static function clearTopic(User $moderator): void
    {
        self::authorize($moderator);

        DB::transaction(function () use ($moderator) {
            $current = Topic::current();
            Topic::archiveAll();
            ModerationAction::record($moderator, 'topic.cleared', $current, ['topic' => $current?->topic]);
        });
    }

    private static function authorize(User $moderator): void
    {
        if (! $moderator->isAdminUser() || $moderator->isBanned()) {
            throw new AuthorizationException;
        }
    }
}
