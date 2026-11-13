<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Observability\AssistFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class AgentAssistFeedbackController extends Controller
{
    public function store(Request $request, string $supportCode, string $task, AssistFeedback $feedback): JsonResponse
    {
        abort_unless($request->expectsJson() && $request->isJson(), 406);
        abort_if(strlen($request->getContent()) > 4096, 413);
        $intent = strtolower($request->header('Purpose', '').' '.$request->header('Sec-Purpose', ''));
        abort_if(str_contains($intent, 'prefetch') || str_contains($intent, 'prerender'), 409);
        $input = $request->json()->all();
        $fields = ['generation', 'event', 'receipt', 'usefulness', 'correction', 'expected_revision'];
        abort_if(array_diff(array_keys($input), $fields) !== [], 422);
        $input = Validator::make($input, [
            'generation' => ['required', 'uuid'], 'event' => ['required', Rule::in(['viewed', 'used', 'rejected', 'assessment'])],
            'receipt' => ['required_if:event,used', 'prohibited_unless:event,used', 'string', 'max:2048'],
            'usefulness' => ['required_if:event,assessment', 'prohibited_unless:event,assessment', Rule::in(['useful', 'not_useful', 'unsure'])],
            'correction' => ['required_if:event,assessment', 'prohibited_unless:event,assessment', Rule::in(['none', 'minor', 'material', 'unassessed'])],
            'expected_revision' => ['required_if:event,assessment', 'prohibited_unless:event,assessment', 'integer', 'min:0', 'max:2147483646'],
        ])->validate();
        if (isset($input['expected_revision'])) {
            $input['expected_revision'] = (int) $input['expected_revision'];
        }
        abort_unless($request->user()?->account_id, 404);
        abort_if($task === 'summary' && $input['event'] === 'used', 422);
        $conversation = Conversation::query()->where('support_code', $supportCode)->firstOrFail();
        $purpose = $task === 'refinement' ? AssistFeedback::PRIVATE_PURPOSE : AgentConversationAiPreparationController::TASKS[$task];
        try {
            return response()->json($feedback->record($request->user(), $conversation, $purpose, $input))->header('Cache-Control', 'no-store, private');
        } catch (AiNetworkException $exception) {
            abort($exception->reason === 'access_revoked' ? 404 : 409);
        }
    }
}
