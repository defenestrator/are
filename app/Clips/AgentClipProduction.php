<?php

namespace App\Clips;

use App\Agent\AgentGate;
use App\Jobs\Clips\FormatClipForShorts;
use App\Jobs\Clips\VerifyClipRelease;
use App\Models\Agent;
use App\Models\ClipRelease;
use App\Models\StreamMarker;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class AgentClipProduction
{
    /** A revision changes when editorial state, source selection or output changes. */
    public static function revision(StreamMarker $marker): string
    {
        return hash('sha256', json_encode([
            $marker->id, $marker->status, $marker->review_status, $marker->title,
            $marker->trim_start_seconds, $marker->trim_end_seconds,
            $marker->landscape_file_path, $marker->portrait_file_path,
            $marker->short_file_path, $marker->short_signature, $marker->short_bytes,
            $marker->formatted_at?->toISOString(), $marker->files_pruned_at?->toISOString(),
            config('clips.disk'), config('clips.vertical_mode'), FormatClipForShorts::RECIPE,
        ], JSON_THROW_ON_ERROR));
    }

    public static function rendered(StreamMarker $marker): bool
    {
        $source = FormatClipForShorts::source($marker);

        return FormatClipForShorts::wanted($marker)
            && $source !== null
            && $marker->short_file_path !== null
            && $marker->short_signature === FormatClipForShorts::signature($marker, $source);
    }

    /** @param array<string, mixed> $data */
    public static function render(StreamMarker $marker, Agent $agent, array $data): StreamMarker
    {
        $agent->loadMissing('user');

        return AgentGate::whileAllowed(function () use ($marker, $agent, $data) {
            $locked = StreamMarker::whereKey($marker->id)->lockForUpdate()->firstOrFail();
            self::expectRevision($locked, $data['clip_revision']);
            abort_if($locked->files_pruned_at !== null, 409, 'The clip files have been pruned.');
            ClipReview::edit($locked, $agent->user, $data['title'], $data['trim_start'], $data['trim_end']);
            ClipReview::approve($locked, $agent->user, queueAfterCommit: true);

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public static function release(StreamMarker $marker, Agent $agent, PersonalAccessToken $token, array $data): ClipRelease
    {
        return AgentGate::whileAllowed(function () use ($marker, $agent, $token, $data) {
            // Serialize release requests per agent, including the first idempotency key.
            Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $requestHash = hash('sha256', json_encode([$marker->id, $data], JSON_THROW_ON_ERROR));
            $existing = ClipRelease::where('agent_id', $agent->id)->where('request_id', $data['request_id'])->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->request_hash, $requestHash), 409, 'The request ID already identifies a different release.');
                self::queueVerification($existing);

                return $existing;
            }

            $locked = StreamMarker::whereKey($marker->id)->lockForUpdate()->firstOrFail();
            self::expectRevision($locked, $data['clip_revision']);
            abort_unless(self::rendered($locked), 409, 'The current clip has no completed render.');
            $release = ClipRelease::create($data + [
                'stream_marker_id' => $locked->id,
                'agent_id' => $agent->id,
                'token_id' => $token->id,
                'request_hash' => $requestHash,
                'disk' => config('clips.disk'),
                'file_path' => $locked->short_file_path,
                'render_signature' => $locked->short_signature,
                'status' => 'pending',
            ]);
            self::queueVerification($release);

            return $release;
        });
    }

    public static function expectRevision(StreamMarker $marker, string $revision): void
    {
        abort_unless(hash_equals(self::revision($marker), $revision), 409, 'The clip changed. Fetch its current revision.');
    }

    private static function queueVerification(ClipRelease $release): void
    {
        if ($release->status === 'pending') {
            abort_if(FormatClipForShorts::unsafeConnection() !== null, 503, 'The clips queue connection is not configured for long jobs.');
            DB::afterCommit(fn () => VerifyClipRelease::dispatch($release->id));
        }
    }
}
