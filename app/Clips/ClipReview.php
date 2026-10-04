<?php

namespace App\Clips;

use App\Models\ClipDecision;
use App\Models\StreamMarker;
use App\Models\User;
use App\QuestionQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The approval queue's decisions (#11, slice 2): approve, reject, or edit the
 * title and trim. Each decision updates the clip's current review state and
 * is kept in clip_decisions. Approving publishes nothing; a later slice
 * uploads approved clips.
 *
 * Callers authorise first (the moderate gate). These methods check only that
 * the clip can be reviewed and that the edit is valid.
 */
class ClipReview
{
    public const TITLE_MAX = 100;

    /** The shortest trimmed clip, matching Twitch's own 5 s minimum. */
    public const MIN_TRIMMED_SECONDS = 5;

    /**
     * @throws ValidationException
     */
    public static function approve(StreamMarker $marker, User $by): void
    {
        self::ensureReviewable($marker);

        self::record($marker, $by, ClipDecisionKind::Approved, ['review_status' => ClipReviewStatus::Approved]);
    }

    /**
     * @throws ValidationException
     */
    public static function reject(StreamMarker $marker, User $by, string $note = ''): void
    {
        self::ensureReviewable($marker);

        $note = QuestionQueue::clean($note);
        Validator::make(['note' => $note], ['note' => ['nullable', 'string', 'max:500']])->validate();

        self::record($marker, $by, ClipDecisionKind::Rejected, ['review_status' => ClipReviewStatus::Rejected], $note);
    }

    /**
     * Set the title and the trim (in and out points, in seconds from the
     * clip's start, to 0.1 s). An approved clip goes back to review, because
     * what was approved has changed.
     *
     * @throws ValidationException
     */
    public static function edit(StreamMarker $marker, User $by, string $title, float|int|string|null $trimStart, float|int|string|null $trimEnd): void
    {
        self::ensureReviewable($marker);

        $duration = $marker->clipDuration();
        $data = ['title' => QuestionQueue::clean($title), 'trim_start' => $trimStart, 'trim_end' => $trimEnd];

        Validator::make($data, [
            'title' => ['required', 'string', 'max:'.self::TITLE_MAX],
            'trim_start' => ['required', 'numeric', 'min:0', 'max:'.$duration],
            'trim_end' => ['required', 'numeric', 'gt:trim_start', 'max:'.$duration],
        ], [
            'trim_end.gt' => 'The out point must be after the in point.',
            'trim_end.max' => 'The out point must be within the clip, which is '.$duration.' s long.',
        ])->after(function ($validator) use ($data) {
            if (is_numeric($data['trim_start']) && is_numeric($data['trim_end'])
                && round((float) $data['trim_end'] - (float) $data['trim_start'], 1) < self::MIN_TRIMMED_SECONDS) {
                $validator->errors()->add('trim_end', 'The trimmed clip must be at least '.self::MIN_TRIMMED_SECONDS.' s long.');
            }
        })->validate();

        $changes = [
            'title' => $data['title'],
            'trim_start_seconds' => round((float) $trimStart, 1),
            'trim_end_seconds' => round((float) $trimEnd, 1),
        ];
        if ($marker->review_status === ClipReviewStatus::Approved) {
            $changes['review_status'] = ClipReviewStatus::Pending;
        }

        self::record($marker, $by, ClipDecisionKind::Edited, $changes);
    }

    /**
     * Only a clip that exists can be reviewed.
     *
     * @throws ValidationException
     */
    private static function ensureReviewable(StreamMarker $marker): void
    {
        if ($marker->status !== StreamMarkerStatus::ClipReady) {
            throw ValidationException::withMessages(['clip' => 'Only a clip that Twitch has finished can be reviewed.']);
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private static function record(StreamMarker $marker, User $by, ClipDecisionKind $kind, array $changes, ?string $note = null): void
    {
        DB::transaction(function () use ($marker, $by, $kind, $changes, $note) {
            $marker->update($changes + ['reviewed_by_user_id' => $by->id, 'reviewed_at' => now()]);

            ClipDecision::create([
                'stream_marker_id' => $marker->id,
                'user_id' => $by->id,
                'decision' => $kind,
                'title' => $marker->title,
                'trim_start_seconds' => $marker->trim_start_seconds,
                'trim_end_seconds' => $marker->trim_end_seconds,
                'note' => $note !== '' ? $note : null,
            ]);
        });
    }
}
