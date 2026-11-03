<?php

namespace App\Jobs\Clips;

use App\Agent\AgentGate;
use App\Clips\AgentClipProduction;
use App\Models\Agent;
use App\Models\ClipRelease;
use App\Models\StreamMarker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
use Throwable;

/** Hash remote or local bytes on the long-job queue before accepting QA evidence. */
class VerifyClipRelease implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    /** @var list<int> */
    public array $backoff = [30, 60];

    public function __construct(public int $releaseId)
    {
        $this->timeout = (int) config('clips.ffmpeg_timeout') + 60;
        $this->onQueue('clips');
        $this->onConnection(FormatClipForShorts::connectionName());
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('clip-release:'.$this->releaseId))->releaseAfter(30)->expireAfter($this->timeout + 60)];
    }

    public function handle(): void
    {
        $release = ClipRelease::find($this->releaseId);
        if ($release === null || $release->status !== 'pending') {
            return;
        }

        AgentGate::ensure();
        $stream = Storage::disk($release->disk)->readStream($release->file_path);
        if (! is_resource($stream)) {
            throw new RuntimeException('The clip artifact could not be read.');
        }
        try {
            $hash = hash_init('sha256');
            $actualBytes = hash_update_stream($hash, $stream);
            $actualHash = hash_final($hash);
        } finally {
            fclose($stream);
        }

        AgentGate::whileAllowed(function () use ($release, $actualHash, $actualBytes) {
            // Match token revocation's lock order: token first, then its release rows.
            $token = PersonalAccessToken::whereKey($release->token_id)->sharedLock()->first();
            $locked = ClipRelease::whereKey($release->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                return;
            }
            $marker = StreamMarker::whereKey($locked->stream_marker_id)->lockForUpdate()->firstOrFail();
            $expirationMinutes = (int) config('sanctum.expiration', 0);
            $authorized = $token !== null
                && $token->tokenable_type === (new Agent)->getMorphClass()
                && $token->tokenable_id === $locked->agent_id
                && ($token->expires_at === null || $token->expires_at->isFuture())
                && ($expirationMinutes === 0 || $token->created_at->gt(now()->subMinutes($expirationMinutes)))
                && $token->can('agent:clips:release');

            if (! $authorized) {
                $locked->update(['status' => 'failed', 'error' => 'The release token expired or was revoked.']);
            } elseif (! AgentClipProduction::rendered($marker) || ! hash_equals($locked->clip_revision, AgentClipProduction::revision($marker))) {
                $locked->update(['status' => 'stale', 'error' => 'The clip changed before verification completed.']);
            } elseif ($actualBytes < 1 || $actualBytes !== $marker->short_bytes || ! hash_equals($locked->artifact_sha256, $actualHash)) {
                $locked->update(['status' => 'failed', 'error' => 'The stored artifact does not match the submitted SHA-256.']);
            } else {
                $locked->update([
                    'status' => $locked->verdict === 'allowed' ? 'released' : 'rejected',
                    'verified_at' => now(),
                    'error' => null,
                ]);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        ClipRelease::whereKey($this->releaseId)->where('status', 'pending')->update([
            'status' => 'failed',
            'error' => 'Artifact verification failed. Submit a new request after resolving storage or agent availability.',
        ]);
    }
}
