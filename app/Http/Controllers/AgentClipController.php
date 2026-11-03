<?php

namespace App\Http\Controllers;

use App\Clips\AgentClipProduction;
use App\Clips\StreamMarkerStatus;
use App\Http\Requests\RenderAgentClipRequest;
use App\Http\Requests\StoreClipReleaseRequest;
use App\Models\Agent;
use App\Models\ClipRelease;
use App\Models\StreamMarker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AgentClipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $after = max(0, (int) $request->query('after', 0));
        $clips = StreamMarker::where('status', StreamMarkerStatus::ClipReady)
            ->whereNull('files_pruned_at')->where('id', '>', $after)
            ->orderBy('id')->limit(50)->get();

        return response()->json(['clips' => $clips->map(fn (StreamMarker $marker) => self::clip($marker)), 'next_after' => $clips->last()?->id]);
    }

    public function show(StreamMarker $marker): JsonResponse
    {
        return response()->json(self::clip($marker));
    }

    public function file(Request $request, StreamMarker $marker, string $variant, ClipFileController $files): Response
    {
        abort_if($marker->files_pruned_at !== null, 404);
        AgentClipProduction::expectRevision($marker, (string) $request->query('revision', ''));
        if ($variant === 'short') {
            abort_unless(AgentClipProduction::rendered($marker), 409, 'The current clip has no completed render.');
        }

        return $files($marker, $variant);
    }

    public function render(RenderAgentClipRequest $request, StreamMarker $marker): JsonResponse
    {
        $agent = Agent::fromToken();
        abort_unless($agent !== null, 403);
        $rendered = AgentClipProduction::render($marker, $agent, $request->validated());

        return response()->json(self::clip($rendered), 202);
    }

    public function release(StoreClipReleaseRequest $request, StreamMarker $marker): JsonResponse
    {
        $agent = Agent::fromToken();
        $token = $agent?->currentAccessToken();
        abort_unless($agent !== null && $token instanceof PersonalAccessToken, 403);
        $release = AgentClipProduction::release($marker, $agent, $token, $request->validated());

        return response()->json(self::releaseData($release, $marker->refresh()), $release->status === 'pending' ? 202 : 200);
    }

    public function releaseStatus(StreamMarker $marker, ClipRelease $release): JsonResponse
    {
        abort_unless($release->stream_marker_id === $marker->id, 404);

        return response()->json(self::releaseData($release, $marker));
    }

    /** @return array<string, mixed> */
    private static function clip(StreamMarker $marker): array
    {
        $revision = AgentClipProduction::revision($marker);
        $files = [];
        foreach (['landscape', 'portrait', 'short'] as $variant) {
            if ($marker->filePath($variant) !== null && $marker->files_pruned_at === null) {
                $files[$variant] = route('agent.clips.file', ['marker' => $marker, 'variant' => $variant, 'revision' => $revision]);
            }
        }

        return [
            'id' => $marker->id,
            'clip_revision' => $revision,
            'status' => $marker->status->value,
            'review_status' => $marker->review_status->value,
            'title' => $marker->title,
            'duration_seconds' => $marker->clipDuration(),
            'trim_start' => $marker->trim_start_seconds,
            'trim_end' => $marker->trim_end_seconds,
            'render_signature' => $marker->short_signature,
            'render_current' => AgentClipProduction::rendered($marker),
            'files' => $files,
            'format_error' => $marker->format_error,
        ];
    }

    /** @return array<string, mixed> */
    private static function releaseData(ClipRelease $release, StreamMarker $marker): array
    {
        $current = AgentClipProduction::rendered($marker) && hash_equals($release->clip_revision, AgentClipProduction::revision($marker));

        return [
            'id' => $release->id,
            'request_id' => $release->request_id,
            'status' => $current ? $release->status : 'stale',
            'verification_status' => $release->status,
            'clip_revision' => $release->clip_revision,
            'artifact_sha256' => $release->artifact_sha256,
            'policy_revision' => $release->policy_revision,
            'verdict' => $release->verdict,
            'checks' => $release->checks,
            'agent_id' => $release->agent_id,
            'verified_at' => $release->verified_at?->toISOString(),
            'error' => $release->error,
            'status_url' => route('agent.clips.release-status', ['marker' => $marker, 'release' => $release]),
        ];
    }
}
