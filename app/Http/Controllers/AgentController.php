<?php

namespace App\Http\Controllers;

use App\Agent\AgentGate;
use App\Agent\AvatarDriver;
use App\ControlBus\ControlBus;
use App\Models\Agent;
use App\Models\AgentClaim;
use App\Models\Question;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * /api/agent (#10): what an Orkestera-driven VTuber can do. Every route sits
 * behind the request log, a Sanctum agent token with the right ability, and
 * the kill-switch gate; each action checks the gate again right before it
 * has an effect.
 */
class AgentController extends Controller
{
    /**
     * GET /api/agent/queue: the next questions, most votes first. Questions
     * another agent claimed, or that were answered, are left out; this
     * agent's own open claims are included and marked.
     */
    public function queue(Request $request): JsonResponse
    {
        $agent = self::agent($request);
        $limit = min(max(1, (int) $request->query('limit', '10')), (int) config('agent.queue_limit'));

        $questions = Question::getSortedQuestions((int) config('agent.queue_limit'));
        $claims = AgentClaim::whereIn('question_id', $questions->pluck('id'))->get()->keyBy('question_id');

        $items = $questions
            ->filter(function (Question $question) use ($claims, $agent) {
                $claim = $claims->get($question->id);

                return $claim === null || ($claim->agent_id === $agent->id && $claim->answered_at === null);
            })
            ->take($limit)
            ->map(fn (Question $question) => [
                'id' => $question->id,
                'question' => $question->question,
                // getSortedQuestions() selects the vote total as `votes`.
                'votes' => (int) $question->getAttribute('votes'),
                'author' => $question->user?->name,
                'claimed_by_me' => $claims->has($question->id),
            ])
            ->values();

        return response()->json(['questions' => $items]);
    }

    /**
     * POST /api/agent/questions/{question}/claim {"reason": "..."}: take a
     * question, saying why, so moderators can see it on /agent.
     */
    public function claim(Request $request, Question $question): JsonResponse
    {
        $agent = self::agent($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        if ($question->archived_at !== null) {
            return response()->json(['message' => 'That question is no longer in the queue.'], 422);
        }

        AgentGate::ensure();

        try {
            $claim = DB::transaction(function () use ($agent, $question, $data) {
                $existing = AgentClaim::where('question_id', $question->id)->lockForUpdate()->first();
                if ($existing !== null) {
                    return $existing;
                }

                return AgentClaim::create([
                    'agent_id' => $agent->id,
                    'question_id' => $question->id,
                    'question_text' => Str::limit($question->question, 497),
                    'reason' => $data['reason'],
                    'claimed_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $claim = AgentClaim::where('question_id', $question->id)->firstOrFail();
        }

        if ($claim->agent_id !== $agent->id || $claim->answered_at !== null) {
            return response()->json(['message' => 'That question is already claimed or answered.'], 409);
        }

        return response()->json(['claim' => $claim->payload()], $claim->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * POST /api/agent/questions/{question}/answer: store what the agent said
     * and the Orkestera workflow's moderation verdict on it.
     * {"answer": "...", "moderation": {"verdict": "allowed|flagged|blocked", ...}}
     */
    public function answer(Request $request, Question $question): JsonResponse
    {
        $agent = self::agent($request);
        $data = $request->validate([
            'answer' => ['required', 'string', 'max:5000'],
            'moderation' => ['required', 'array'],
            'moderation.verdict' => ['required', Rule::in(AgentClaim::VERDICTS)],
            'moderation.categories' => ['sometimes', 'array', 'max:20'],
            'moderation.categories.*' => ['string', 'max:64'],
            'moderation.model' => ['sometimes', 'nullable', 'string', 'max:128'],
            'moderation.notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $claim = AgentClaim::where('question_id', $question->id)->where('agent_id', $agent->id)->first();
        if ($claim === null) {
            return response()->json(['message' => 'Claim the question before answering it.'], 409);
        }
        if ($claim->answered_at !== null) {
            return response()->json(['message' => 'That question was already answered.'], 409);
        }

        AgentGate::ensure();

        $claim->update([
            'answer' => $data['answer'],
            'moderation_verdict' => $data['moderation']['verdict'],
            'moderation' => $data['moderation'],
            'answered_at' => now(),
        ]);

        return response()->json(['claim' => $claim->payload()], 201);
    }

    /**
     * POST /api/agent/expression {"expression": "happy"}: forwarded to
     * VTube Studio or Warudo (config agent.avatar).
     */
    public function expression(Request $request, AvatarDriver $avatar): JsonResponse
    {
        $data = $request->validate(['expression' => ['required', 'string', Rule::in(AvatarDriver::expressions())]]);

        AgentGate::ensure();

        try {
            $avatar->express($data['expression']);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json(['message' => 'The avatar app did not take the expression.'], 502);
        }

        return response()->json(['expression' => $data['expression'], 'sent' => true]);
    }

    /**
     * POST /api/agent/bus/actions {"action": "task Write the README"}: act
     * through the Chat Control Bus, exactly as "!do <action>" from chat, as
     * one participant. Free text still waits for a moderator.
     */
    public function busAction(Request $request, ControlBus $bus): JsonResponse
    {
        $agent = self::agent($request);
        $data = $request->validate(['action' => ['required', 'string', 'max:300']]);

        AgentGate::ensure();

        $submission = $bus->submit($agent->user, null, (string) Str::uuid(), $data['action'], $agent->id);

        return response()->json([
            'accepted' => $submission->accepted(),
            'status' => $submission->ballot->status->value,
            'reason' => $submission->reason,
            'ballot_id' => $submission->ballot->id,
        ], $submission->accepted() ? 202 : 422);
    }

    private static function agent(Request $request): Agent
    {
        // agent.only has already refused anything but an agent's token.
        return Agent::fromToken() ?? abort(403);
    }
}
