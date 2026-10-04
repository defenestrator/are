<?php

namespace App;

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
 * it that way: realtime broadcasting (#48) hooks in here, once, for web and
 * chat alike.
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
     * @throws QuestionRejected
     */
    public static function submit(User $user, string $text): Question
    {
        $text = self::clean($text);

        $validator = Validator::make(['question' => $text], ['question' => self::QUESTION_RULES]);
        if ($validator->fails()) {
            throw QuestionRejected::invalid($validator->errors()->first('question'));
        }

        if ($user->isBanned()) {
            throw QuestionRejected::banned();
        }

        if (! $user->canSubmitQuestion()) {
            throw QuestionRejected::limitReached();
        }

        return $user->questions()->create(['question' => $text]);
    }

    /**
     * Record the user's vote on a question: 1 for up, -1 for down. One vote per
     * user per question; voting again replaces it.
     *
     * @throws QuestionRejected
     */
    public static function vote(User $user, Question $question, int $direction): void
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

        DB::table('question_votes')->updateOrInsert(
            ['question_id' => $question->id, 'user_id' => $user->id],
            ['count' => $direction],
        );
    }
}
