<?php

use App\Clips\AgentClipProduction;
use App\Clips\ClipReview;
use App\Clips\ClipReviewStatus;
use App\Jobs\Clips\FormatClipForShorts;
use App\Jobs\Clips\VerifyClipRelease;
use App\Models\Agent;
use App\Models\ClipRelease;
use App\Models\StreamMarker;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    config(['clips.disk' => 'local', 'clips.vertical_mode' => 'crop', 'agent.enabled' => true, 'bus.enabled' => true]);
});

function productionToken(array $abilities = Agent::CLIP_ABILITIES): string
{
    return Agent::named('producer')->createToken('production', $abilities, now()->addDay())->plainTextToken;
}

function productionClip(bool $rendered = false): StreamMarker
{
    $marker = StreamMarker::factory()->ready()->create([
        'title' => 'A useful demonstration',
        'clip_duration_seconds' => 30,
        'landscape_file_path' => 'private/source.mp4',
        'review_status' => $rendered ? ClipReviewStatus::Approved : ClipReviewStatus::Pending,
    ]);
    Storage::disk('local')->put('private/source.mp4', 'source bytes');
    if ($rendered) {
        Storage::disk('local')->put('private/render.mp4', 'rendered bytes');
        $marker->update([
            'short_file_path' => 'private/render.mp4',
            'short_signature' => FormatClipForShorts::signature($marker, FormatClipForShorts::source($marker)),
            'short_bytes' => strlen('rendered bytes'),
            'formatted_at' => now(),
        ]);
    }

    return $marker->refresh();
}

function releasePayload(StreamMarker $marker): array
{
    return [
        'request_id' => (string) Str::uuid(),
        'clip_revision' => AgentClipProduction::revision($marker),
        'artifact_sha256' => hash('sha256', 'rendered bytes'),
        'policy_revision' => 'marketing-v1',
        'verdict' => 'allowed',
        'checks' => [['name' => 'captions', 'passed' => true, 'evidence' => 'Captions matched the source transcript.']],
    ];
}

function freshProductionRequest(): mixed
{
    app('auth')->forgetGuards();

    return test();
}

test('clip abilities are opt-in and regular user tokens cannot access clips', function () {
    $marker = productionClip();
    $this->getJson('/api/agent/clips')->assertUnauthorized();
    $token = productionToken(Agent::ABILITIES);
    freshProductionRequest()->withToken($token)->getJson('/api/agent/clips')->assertForbidden();
    $userToken = User::factory()->create()->createToken('user', Agent::CLIP_ABILITIES)->plainTextToken;
    freshProductionRequest()->withToken($userToken)->getJson('/api/agent/clips')->assertForbidden();
    freshProductionRequest()->withToken(productionToken(['agent:clips:read']))->getJson('/api/agent/clips')
        ->assertOk()->assertJsonPath('clips.0.id', $marker->id)->assertDontSee('private/source.mp4');
});

test('token command accepts explicit production abilities and preserves show defaults', function () {
    $this->artisan('agent:token', ['name' => 'producer', '--ability' => ['agent:clips:read', 'agent:clips:release']])->assertSuccessful();
    expect(Agent::where('name', 'producer')->firstOrFail()->tokens()->firstOrFail()->abilities)
        ->toBe(['agent:clips:read', 'agent:clips:release']);
    $this->artisan('agent:token', ['name' => 'show'])->assertSuccessful();
    expect(Agent::where('name', 'show')->firstOrFail()->tokens()->firstOrFail()->abilities)->toBe(Agent::ABILITIES);
});

test('agent formats a ready clip through its auditable service user', function () {
    $marker = productionClip();
    $token = productionToken();
    $this->withToken($token)->postJson("/api/agent/clips/{$marker->id}/render", [
        'clip_revision' => AgentClipProduction::revision($marker), 'title' => 'Edited title', 'trim_start' => 2, 'trim_end' => 20,
    ])->assertAccepted()->assertJsonPath('review_status', 'approved');
    $marker->refresh();
    expect($marker->title)->toBe('Edited title')
        ->and($marker->reviewed_by_user_id)->toBe(Agent::where('name', 'producer')->firstOrFail()->user_id)
        ->and($marker->decisions()->count())->toBe(2);
    Queue::assertPushed(FormatClipForShorts::class, fn ($job) => $job->markerId === $marker->id);
});

test('stale render requests and invalid cuts cannot mutate clips', function () {
    $marker = productionClip();
    $data = ['clip_revision' => AgentClipProduction::revision($marker), 'title' => 'New title', 'trim_start' => 0, 'trim_end' => 30];
    $marker->update(['title' => 'Human edit']);
    $token = productionToken();
    $this->withToken($token)->postJson("/api/agent/clips/{$marker->id}/render", $data)->assertConflict();
    $data['clip_revision'] = AgentClipProduction::revision($marker);
    $data['trim_end'] = 50;
    freshProductionRequest()->withToken($token)->postJson("/api/agent/clips/{$marker->id}/render", $data)->assertUnprocessable();
    expect($marker->fresh()->title)->toBe('Human edit')->and($marker->decisions()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('protected media needs a scoped token and current revision', function () {
    $marker = productionClip(true);
    $url = "/api/agent/clips/{$marker->id}/files/short?revision=".AgentClipProduction::revision($marker);
    $this->get($url, ['Accept' => 'application/json'])->assertUnauthorized();
    $token = productionToken(['agent:clips:read']);
    $response = freshProductionRequest()->withToken($token)->get($url);
    $response->assertOk()->assertHeader('Content-Type', 'video/mp4');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $marker->update(['title' => 'Changed']);
    freshProductionRequest()->withToken($token)->get($url, ['Accept' => 'application/json'])->assertConflict();
});

test('release verifies stored bytes asynchronously and retries are idempotent', function () {
    $marker = productionClip(true);
    $payload = releasePayload($marker);
    $token = productionToken();
    $url = "/api/agent/clips/{$marker->id}/releases";
    $response = $this->withToken($token)->postJson($url, $payload)->assertAccepted()->assertJsonPath('status', 'pending');
    $id = $response->json('id');
    Queue::assertPushed(VerifyClipRelease::class, fn ($job) => $job->releaseId === $id);
    (new VerifyClipRelease($id))->handle();
    freshProductionRequest()->withToken($token)->postJson($url, $payload)->assertOk()->assertJsonPath('id', $id)->assertJsonPath('status', 'released');
    expect(ClipRelease::count())->toBe(1)->and(ClipRelease::first()->verified_at)->not->toBeNull();
    $payload['policy_revision'] = 'different';
    freshProductionRequest()->withToken($token)->postJson($url, $payload)->assertConflict();
});

test('overwritten artifact bytes cannot receive a passing release', function () {
    $marker = productionClip(true);
    $response = $this->withToken(productionToken())->postJson("/api/agent/clips/{$marker->id}/releases", releasePayload($marker))->assertAccepted();
    Storage::disk('local')->put('private/render.mp4', 'overwritten bytes');
    (new VerifyClipRelease($response->json('id')))->handle();
    expect(ClipRelease::first()->status)->toBe('failed')->and(ClipRelease::first()->verified_at)->toBeNull();
});

test('edit during verification makes the release stale', function () {
    $marker = productionClip(true);
    $response = $this->withToken(productionToken())->postJson("/api/agent/clips/{$marker->id}/releases", releasePayload($marker))->assertAccepted();
    ClipReview::edit($marker, User::factory()->create(), 'Changed by moderator', 0, 20);
    (new VerifyClipRelease($response->json('id')))->handle();
    expect(ClipRelease::first()->status)->toBe('stale');
});

test('a previously verified release is reported stale after editorial or storage changes', function (string $change) {
    $marker = productionClip(true);
    $token = productionToken();
    $response = $this->withToken($token)->postJson("/api/agent/clips/{$marker->id}/releases", releasePayload($marker))->assertAccepted();
    $id = $response->json('id');
    (new VerifyClipRelease($id))->handle();
    match ($change) {
        'title' => $marker->update(['title' => 'Changed title']),
        'prune' => $marker->update(['files_pruned_at' => now()]),
        'recipe' => config(['clips.vertical_mode' => 'blur']),
    };
    freshProductionRequest()->withToken($token)->getJson("/api/agent/clips/{$marker->id}/releases/{$id}")
        ->assertOk()->assertJsonPath('status', 'stale')->assertJsonPath('verification_status', 'released');
})->with(['title', 'prune', 'recipe']);

test('a queued release respects token revocation', function () {
    $marker = productionClip(true);
    $response = $this->withToken(productionToken())->postJson("/api/agent/clips/{$marker->id}/releases", releasePayload($marker))->assertAccepted();
    Agent::where('name', 'producer')->firstOrFail()->tokens()->delete();
    (new VerifyClipRelease($response->json('id')))->handle();
    expect(ClipRelease::first()->status)->toBe('failed');
});

test('agent gate refuses clip requests and queued releases while disabled', function () {
    $marker = productionClip(true);
    $token = productionToken();
    $response = $this->withToken($token)->postJson("/api/agent/clips/{$marker->id}/releases", releasePayload($marker))->assertAccepted();
    config(['agent.enabled' => false]);
    freshProductionRequest()->withToken($token)->getJson('/api/agent/clips')->assertStatus(423);
    expect(fn () => (new VerifyClipRelease($response->json('id')))->handle())->toThrow(HttpException::class);
    expect(ClipRelease::first()->status)->toBe('pending');
});

test('an old formatted file cannot be released after its recipe changed', function () {
    $marker = productionClip(true);
    $marker->update(['trim_start_seconds' => 5, 'trim_end_seconds' => 20]);
    $this->withToken(productionToken())->postJson("/api/agent/clips/{$marker->id}/releases", releasePayload($marker))->assertConflict();
    expect(ClipRelease::count())->toBe(0);
});

test('a failed validation check cannot authorize an allowed verdict', function () {
    $marker = productionClip(true);
    $data = releasePayload($marker);
    $data['checks'][0]['passed'] = false;
    $this->withToken(productionToken())->postJson("/api/agent/clips/{$marker->id}/releases", $data)->assertUnprocessable();
    expect(ClipRelease::count())->toBe(0);
});

test('unsafe long-job queue configuration refuses verification without recording a pending job', function () {
    $marker = productionClip(true);
    config(['clips.format_connection' => 'database', 'queue.connections.database.retry_after' => 10]);
    $this->withToken(productionToken())->postJson("/api/agent/clips/{$marker->id}/releases", releasePayload($marker))->assertStatus(503);
    expect(ClipRelease::count())->toBe(0);
});

test('queued release honors Sanctum global expiration even with a later token expiry', function () {
    config(['sanctum.expiration' => 60]);
    $marker = productionClip(true);
    $response = $this->withToken(productionToken())->postJson("/api/agent/clips/{$marker->id}/releases", releasePayload($marker))->assertAccepted();
    $this->travel(61)->minutes();
    (new VerifyClipRelease($response->json('id')))->handle();
    expect(ClipRelease::first()->status)->toBe('failed');
});

test('empty replacement artifact cannot be released even with its matching hash', function () {
    $marker = productionClip(true);
    $payload = releasePayload($marker);
    $payload['artifact_sha256'] = hash('sha256', '');
    Storage::disk('local')->put('private/render.mp4', '');
    $response = $this->withToken(productionToken())->postJson("/api/agent/clips/{$marker->id}/releases", $payload)->assertAccepted();
    (new VerifyClipRelease($response->json('id')))->handle();
    expect(ClipRelease::first()->status)->toBe('failed');
});
