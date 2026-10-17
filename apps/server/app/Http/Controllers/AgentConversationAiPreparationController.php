<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Conversation;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Observability\AssistFeedback;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use App\Support\Ai\Preparation\AiAssistPreparation;
use App\Support\Conversations\ConversationReturnPath;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Authenticated commands are separate from read-only rendering/status/Use. */
final class AgentConversationAiPreparationController extends Controller
{
    public const TASKS = ['summary' => 'conversation_summary', 'reply' => 'conversation_reply_draft',
        'knowledge' => 'conversation_knowledge_suggestion', 'ticket' => 'conversation_ticket_suggestion'];

    public function show(Request $request, string $supportCode, string $task): JsonResponse
    {
        return $this->json(fn () => app(AiAssistPreparation::class)->read($request->user(),
            $this->conversation($request, $supportCode), self::TASKS[$task]));
    }

    public function store(Request $request, string $supportCode, string $task): JsonResponse
    {
        abort_unless($request->expectsJson(), 406);
        $this->assertInteractive($request);
        $input = $request->validate(['action' => ['required', 'in:open,initial,refresh'],
            'workspace' => ['required', 'in:conversation,conversion'],
            'draft' => ['prohibited'], 'prompt' => ['prohibited'], 'messages' => ['prohibited']]);

        return $this->json(fn () => app(AiAssistPreparation::class)->ensure($request->user(),
            $this->conversation($request, $supportCode), self::TASKS[$task], $input['action'], $input['workspace']));
    }

    public function use(Request $request, string $supportCode, string $task): JsonResponse
    {
        abort_unless($request->expectsJson(), 406);
        $input = $request->validate(['generation' => ['required', 'uuid']]);

        return $this->json(function () use ($request, $supportCode, $task, $input): array {
            $conversation = $this->conversation($request, $supportCode);
            $state = app(AiAssistPreparation::class)->use($request->user(), $conversation, self::TASKS[$task], $input['generation']);

            return $state + ['feedback_receipt' => app(AssistFeedback::class)->receipt($request->user(), $conversation, self::TASKS[$task], $input['generation'])];
        });
    }

    /** Native Manual forms join the same coordinator, preserving return context. */
    public function nativeStore(Request $request, Conversation $conversation, string $purpose, ConversationReturnPath $returnPath): RedirectResponse
    {
        $this->assertInteractive($request);
        $input = $request->validate(['assist_action' => ['sometimes', 'in:initial,refresh']]);
        try {
            $state = app(AiAssistPreparation::class)->ensure($request->user(), $conversation, $purpose,
                $input['assist_action'] ?? 'refresh', $purpose === 'conversation_ticket_suggestion' ? 'conversion' : 'conversation');
        } catch (AuthorizationException) {
            abort(404);
        } catch (AiNetworkException $exception) {
            abort_if(in_array($exception->reason, ['access_revoked', 'connection_changed'], true), 404);
            $state = ['queued' => false, 'state' => 'unavailable'];
        }

        $prefix = match ($purpose) {
            'conversation_summary' => 'ai_summary', 'conversation_reply_draft' => 'ai_reply_draft',
            'conversation_knowledge_suggestion' => 'ai_knowledge_suggestion', 'conversation_ticket_suggestion' => 'ai_ticket_suggestion',
        };
        $status = $state['queued'] ? 'conversations.flash.'.$prefix.'_queued'
            : ($state['state'] === 'preparing' ? 'conversations.flash.'.$prefix.'_pending'
                : ($state['state'] === 'unavailable' ? 'conversations.flash.'.$prefix.'_failed' : 'ai_assist.preparation.reused'));

        return redirect()->route('dashboard.conversations.show', $returnPath->routeParameters($conversation, $request))->with('status', $status);
    }

    public function nativeShow(Request $request, Conversation $conversation, string $purpose): Response
    {
        abort_unless(app(AiAssistPolicyResolver::class)->forConversation($conversation)->allows($purpose), 404);

        if ($purpose === 'conversation_ticket_suggestion' && $conversation->tickets()->exists()) {
            return response('', 204)->header('Cache-Control', 'no-store, private');
        }

        // The component reads current viewer authority, never the requester's cache.
        return response()->view('agent.conversations.partials.ai-assist-task', [
            'conversation' => $conversation, 'assistPurpose' => $purpose,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function nativeUse(Request $request, Conversation $conversation, string $purpose): JsonResponse
    {
        return $this->json(function () use ($request, $conversation, $purpose): array {
            $state = app(AiAssistPreparation::class)->use($request->user(), $conversation, $purpose, $request->string('generation')->toString());
            $output = $state['output'];
            if ($purpose === 'conversation_knowledge_suggestion') {
                abort_unless(Article::query()->whereKey($request->input('article_id'))
                    ->where('account_id', $conversation->site->account_id)->published()->exists(), 404);
                $article = collect($output['articles'])->firstWhere('id', (int) $request->input('article_id'));
                if ($article === null || ! hash_equals($article['revision'], (string) $request->input('article_revision'))) {
                    throw new AiNetworkException('context_changed');
                }
                $output = ['article_id' => $article['id'], 'article_revision' => $article['revision'], 'text' => $article['snippet']];
            }

            return ['generation' => $state['generation'], 'policy_revision' => $state['policy_revision'], 'feedback_receipt' => app(AssistFeedback::class)->receipt($request->user(), $conversation, $purpose, $state['generation']), ...$output];
        });
    }

    private function assertInteractive(Request $request): void
    {
        $intent = strtolower($request->header('Purpose', '').' '.$request->header('Sec-Purpose', ''));
        abort_if(str_contains($intent, 'prefetch') || str_contains($intent, 'prerender'), 409);
    }

    private function conversation(Request $request, string $supportCode): Conversation
    {
        $conversation = Conversation::query()->with('site.account')->where('support_code', $supportCode)->firstOrFail();

        return $conversation;
    }

    private function json(\Closure $operation): JsonResponse
    {
        try {
            return response()->json($operation())->header('Cache-Control', 'no-store, private');
        } catch (AuthorizationException) {
            abort(404);
        } catch (AiNetworkException $exception) {
            abort_if($exception->reason === 'access_revoked', 404);

            return response()->json(['state' => 'unavailable', 'output' => null, 'can_use' => false],
                $exception->reason === 'context_changed' || $exception->reason === 'execution_identity_invalid' ? 409 : 503)
                ->header('Cache-Control', 'no-store, private');
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable) {
            return response()->json(['state' => 'unavailable', 'output' => null, 'can_use' => false], 503)
                ->header('Cache-Control', 'no-store, private');
        }
    }
}
