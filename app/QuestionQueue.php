<?php

namespace App;

use App\Events\QuestionSubmitted;
use App\Exceptions\QuestionRejected;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The rules for submitting and voting on questions, shared by the /vote page
 * and chat commands so that both enforce exactly the same limits.
 *
 * submit() and vote() are the only ways a question or vote is written. Keep
 * it that way: they broadcast QuestionSubmitted and VoteCast, so web and chat
 * submissions and votes show live on every open vote page.
 */
class QuestionQueue
{
    /** Validation rules for a question's text. */
    public const QUESTION_RULES = ['required', 'string', 'min:3', 'max:420'];

    /**
     * Unicode bidirectional controls: LRM, RLM and ALM marks, the embeddings
     * and overrides U+202A to U+202E, and the isolates U+2066 to U+2069. Any
     * of them can make text on the overlays read differently from what
     * moderators see in the queue. Other format characters, such as the
     * zero-width joiner inside emoji, are kept.
     */
    private const BIDI_CONTROLS = '/[\x{200E}\x{200F}\x{061C}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /**
     * The question text as stored: bidi controls removed, then trimmed.
     */
    public static function clean(string $text): string
    {
        return trim((string) preg_replace(self::BIDI_CONTROLS, '', $text));
    }

    /**
     * @param  string|null  $source  where it was asked, for per-segment analytics (#12):
     *                               Question::SOURCE_WEB, or the chat platform (IdentityProvider value)
     *
     * @throws QuestionRejected
     */
    public static function submit(User $user, string $text, ?string $source = null): Question
    {
        $text = self::clean($text);

        $validator = Validator::make(['question' => $text], ['question' => self::QUESTION_RULES]);
        if ($validator->fails()) {
            throw QuestionRejected::invalid($validator->errors()->first('question'));
        }

        // Counting open questions and then inserting is not atomic, so two
        // submissions at once (two !q, or chat and the web) could both pass the
        // cap. Lock the user's row first: a second submission by the same
        // person waits here until the first has inserted and committed, and
        // then counts it. Other users are not blocked.
        $question = DB::transaction(function () use ($user, $text, $source) {
            User::whereKey($user->id)->lockForUpdate()->first();

            if ($user->isBanned()) {
                throw QuestionRejected::banned();
            }

            if (! $user->canSubmitQuestion()) {
                throw QuestionRejected::limitReached();
            }

            return $user->questions()->create(['question' => $text, 'source' => $source]);
        });

        // After the commit, outside the transaction, so viewers are never told
        // about a question that a rollback took back. The event is also
        // ShouldDispatchAfterCommit, which covers callers that wrap submit()
        // in a transaction of their own.
        QuestionSubmitted::dispatch($question->id);

        return $question;
    }

    /**
     * Record the user's vote on a question: 1 for up, -1 for down. One vote per
     * user per question; voting again replaces it. The write is locked and
     * versioned, and broadcast as VoteCast (Question::recordVote).
     *
     * @return array{votes: int, version: int} the new total and its version
     *
     * @throws QuestionRejected
     */
    public static function vote(User $user, Question $question, int $direction): array
    {
        if (! in_array($direction, [1, -1], true)) {
            throw QuestionRejected::invalid('A vote is up or down.');
        }

        if ($user->isBanned()) {
            throw QuestionRejected::banned();
        }

        if ($question->archived_at !== null) {
            throw QuestionRejected::closed();
        }

        return $question->recordVote($user, $direction);
    }
}
