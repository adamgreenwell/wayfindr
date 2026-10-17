<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Refinement\DraftRefinementAction;
use App\Support\Ai\Refinement\DraftRefinementInput;
use App\Support\Ai\Refinement\DraftRefinements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Throwable;

/** JSON only: never redirect with old input or put unsent text in a URL/session. */
final class AgentConversationDraftRefinementController extends Controller
{
    public function store(Request $request, string $supportCode, DraftRefinements $refinements): JsonResponse
    {
        return $this->run($request, $supportCode, function (Conversation $conversation) use ($request, $refinements): array {
            $data = $this->json($request, ['refinement_draft', 'draft_revision', 'action', 'tone', 'language']);
            if (! is_string($data['refinement_draft'] ?? null) || ! is_string($data['draft_revision'] ?? null)
                || ! is_string($data['action'] ?? null) || ($action = DraftRefinementAction::tryFrom($data['action'])) === null
                || (isset($data['tone']) && ! is_string($data['tone'])) || (isset($data['language']) && ! is_string($data['language']))) {
                throw new InvalidArgumentException;
            }
            $input = new DraftRefinementInput($data['refinement_draft'], $data['draft_revision'], $action, $data['tone'] ?? null, $data['language'] ?? null);

            return $refinements->request($request->user(), $conversation, $input);
        }, 202);
    }

    public function show(Request $request, string $supportCode, DraftRefinements $refinements): JsonResponse
    {
        return $this->run($request, $supportCode, function (Conversation $conversation) use ($request, $refinements): array {
            [$generation, $revision] = $this->identity($request->query());

            return $refinements->show($request->user(), $conversation, $generation, $revision);
        });
    }

    public function apply(Request $request, string $supportCode, DraftRefinements $refinements): JsonResponse
    {
        return $this->run($request, $supportCode, function (Conversation $conversation) use ($request, $refinements): array {
            [$generation, $revision] = $this->identity($this->json($request, ['generation', 'draft_revision']));

            return $refinements->show($request->user(), $conversation, $generation, $revision, true);
        });
    }

    public function cancel(Request $request, string $supportCode, DraftRefinements $refinements): JsonResponse
    {
        return $this->run($request, $supportCode, function (Conversation $conversation) use ($request, $refinements): array {
            [$generation] = $this->identity($this->json($request, ['generation', 'draft_revision']));
            $refinements->cancel($request->user(), $conversation, $generation);

            return ['state' => 'cancelled'];
        });
    }

    private function run(Request $request, string $supportCode, callable $action, int $status = 200): JsonResponse
    {
        abort_unless($request->expectsJson(), 406);
        $conversation = Conversation::query()->with('site')->where('support_code', $supportCode)->firstOrFail();
        abort_unless(Gate::forUser($request->user())->allows('reply', $conversation), 404);
        try {
            return $this->response($action($conversation), $status);
        } catch (InvalidArgumentException|JsonException) {
            return $this->response(['state' => 'unavailable', 'failure_code' => 'invalid_input'], 422);
        } catch (AiNetworkException $exception) {
            $code = in_array($exception->reason, ['usage_retry_cooldown', 'usage_request_limit', 'usage_token_limit', 'usage_concurrency_limit'], true) ? 429 : 409;

            return $this->response(['state' => 'unavailable', 'failure_code' => $code === 429 ? $exception->reason : 'context_changed'], $code)
                ->header('Retry-After', (string) max(1, $exception->retryAfterSeconds ?? 60));
        } catch (Throwable) {
            return $this->response(['state' => 'unavailable', 'failure_code' => 'provider_failed'], 503);
        }
    }

    private function response(array $data, int $status): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    /** Read the bounded raw JSON so global TrimStrings never changes the exact original. */
    private function json(Request $request, array $allowed): array
    {
        if (! $request->isJson() || strlen($request->getContent()) > 65536) {
            throw new InvalidArgumentException;
        }
        $data = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_is_list($data) || array_diff(array_keys($data), $allowed) !== []) {
            throw new InvalidArgumentException;
        }

        return $data;
    }

    private function identity(array $data): array
    {
        if (! is_string($data['generation'] ?? null) || ! Str::isUuid($data['generation'])
            || ! is_string($data['draft_revision'] ?? null) || ! Str::isUuid($data['draft_revision'])) {
            throw new InvalidArgumentException;
        }

        return [$data['generation'], $data['draft_revision']];
    }
}
