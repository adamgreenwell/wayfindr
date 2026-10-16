<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Models\User;
use App\Support\Ai\AccountAiConnectionPresenter;
use App\Support\Ai\AccountAiConnections;
use App\Support\Ai\AgentCopilotConfiguration;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Usage\AiUsagePolicy;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

final class AgentAccountAiAssistController extends Controller
{
    public function __construct(private readonly AccountAiConnections $connections,
        private readonly AccountAiConnectionPresenter $presenter, private readonly AiUsagePolicy $limits) {}

    public function show(Request $request): View
    {
        return view('agent.account.ai-assist', ['assist' => $this->presenter->present($this->actor($request))]);
    }

    public function propose(Request $request): RedirectResponse|JsonResponse
    {
        return $this->perform($request, function (User $actor) use ($request): void {
            $input = $this->connectionInput($request);
            $this->connections->proposeForRevision($actor, (int) $actor->account_id, $input, $input['expected_revision'], $input['editor_token']);
        }, 'proposed');
    }

    public function test(Request $request): RedirectResponse|JsonResponse
    {
        return $this->perform($request, function (User $actor) use ($request): void {
            $input = $this->connectionInput($request);
            $draft = $this->connections->proposeForRevision($actor, (int) $actor->account_id, $input, $input['expected_revision'], $input['editor_token']);
            $request->attributes->set('ai_assist_proposal_saved', true);
            $this->connections->test($actor, (int) $actor->account_id, $draft->proposed_revision);
        }, 'tested');
    }

    public function discover(Request $request): RedirectResponse|JsonResponse|View
    {
        return $this->perform($request, function (User $actor) use ($request): array {
            $input = $this->connectionInput($request);
            $draft = $this->connections->proposeForRevision($actor, (int) $actor->account_id, $input, $input['expected_revision'], $input['editor_token']);
            $request->attributes->set('ai_assist_proposal_saved', true);

            return $this->connections->discoverModels($actor, (int) $actor->account_id, $draft->proposed_revision) ?? [];
        }, 'discovery_finished', true);
    }

    public function activate(Request $request): RedirectResponse|JsonResponse
    {
        return $this->perform($request, function (User $actor) use ($request): void {
            $input = $this->connectionInput($request);
            // Comparing every submitted processing field first invalidates the
            // test if the browser changed one. Never activate the new revision.
            $this->connections->proposeForRevision($actor, (int) $actor->account_id, $input, $input['expected_revision'], $input['editor_token']);
            $request->attributes->set('ai_assist_proposal_saved', true);
            $this->connections->activate($actor, (int) $actor->account_id, $input['expected_revision'] ?? '');
        }, 'activated');
    }

    public function enabled(Request $request): RedirectResponse|JsonResponse
    {
        return $this->perform($request, function (User $actor) use ($request): void {
            $input = Validator::make($request->only(['enabled', 'editor_token']), [
                'enabled' => ['required', 'boolean'],
                'editor_token' => [Rule::requiredIf($request->boolean('enabled')), 'nullable', 'regex:/\A[a-f0-9]{64}\z/'],
            ])->validate();
            $this->connections->setEnabledForToken($actor, (int) $actor->account_id, $request->boolean('enabled'), $input['editor_token'] ?? null);
        }, $request->boolean('enabled') ? 'enabled' : 'disabled');
    }

    public function disconnect(Request $request): RedirectResponse|JsonResponse
    {
        return $this->perform($request, function (User $actor) use ($request): void {
            $input = Validator::make($request->only(['expected_revision', 'editor_token']), [
                'expected_revision' => ['present', 'nullable', 'uuid'], 'editor_token' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
            ])->validate();
            $this->connections->disconnectForRevision($actor, (int) $actor->account_id, $input['expected_revision'], $input['editor_token']);
        }, 'disconnected');
    }

    public function discard(Request $request): RedirectResponse|JsonResponse
    {
        return $this->perform($request, function (User $actor) use ($request): void {
            $input = Validator::make($request->only(['expected_revision', 'editor_token']), [
                'expected_revision' => ['present', 'nullable', 'uuid'], 'editor_token' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
            ])->validate();
            $this->connections->discard($actor, (int) $actor->account_id, $input['expected_revision'], $input['editor_token']);
        }, 'discarded');
    }

    public function limits(Request $request): RedirectResponse|JsonResponse
    {
        return $this->perform($request, function (User $actor) use ($request): void {
            $fields = ['daily_requests', 'daily_tokens', 'concurrency', 'max_output_tokens'];
            $input = Validator::make($request->only($fields), array_fill_keys($fields, ['present', 'nullable', 'integer', 'min:0']))->validate();
            $this->limits->setAccountLimits($actor, (int) $actor->account_id, $input);
        }, 'limits_saved');
    }

    private function actor(Request $request): User
    {
        $actor = User::query()->find($request->user()?->id);
        abort_unless($actor !== null && $actor->account_id && ! $actor->isDeactivated()
            && $actor->hasAccountPermission(AccountPermission::ManageAiConnections), 403);

        return $actor;
    }

    private function connectionInput(Request $request): array
    {
        $rules = [
            'expected_revision' => ['present', 'nullable', 'uuid'],
            'editor_token' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
            'source' => ['required', Rule::in(['installation', 'account'])],
            'name' => ['nullable', 'string', 'max:100'],
            'provider' => ['nullable', Rule::in(AgentCopilotConfiguration::PROVIDERS)],
            'model' => ['nullable', 'string', 'max:255'],
            'endpoint' => ['nullable', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail): void {
                if (! $this->safeEndpoint($value)) {
                    $fail(__('ai_assist.validation.invalid_endpoint'));
                }
            }],
            'openrouter_provider' => ['nullable', 'string', 'max:100'],
            'api_key' => ['nullable', 'string', 'max:8192'],
            'clear_api_key' => ['nullable', 'boolean'],
        ];
        $input = Validator::make($request->only(array_keys($rules)), $rules)->validate();
        if ($request->boolean('clear_api_key') && trim((string) ($input['api_key'] ?? '')) !== '') {
            throw ValidationException::withMessages(['api_key' => __('ai_assist.validation.key_conflict')]);
        }

        return $input;
    }

    private function safeEndpoint(mixed $endpoint): bool
    {
        if ($endpoint === null || $endpoint === '') {
            return true;
        }
        if (! is_string($endpoint) || preg_match('/[\x00-\x20\x7f]/', $endpoint) === 1) {
            return false;
        }
        $parts = parse_url($endpoint);

        return $parts !== false && in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)
            && ($parts['host'] ?? '') !== ''
            && array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) === [];
    }

    private function perform(Request $request, Closure $operation, string $status, bool $discovery = false): RedirectResponse|JsonResponse|View
    {
        $actor = $this->actor($request);
        try {
            $models = $operation($actor);
            if ($request->expectsJson() || $discovery) {
                $assist = $this->presenter->present($actor);
                if ($discovery) {
                    $assist['models'] = $models;
                }

                return $request->expectsJson()
                    ? response()->json(['assist' => $assist, ...($discovery ? ['models' => $models] : [])])
                    : view('agent.account.ai-assist', ['assist' => $assist]);
            }

            return redirect()->route('dashboard.account.ai-assist.show')->with('status', 'ai_assist.flash.'.$status);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach (array_keys($exception->errors()) as $field) {
                $key = match ($field) {
                    'expected_revision', 'editor_token' => 'stale_edit', 'endpoint' => 'invalid_endpoint',
                    'api_key' => $request->boolean('clear_api_key') && filled($request->input('api_key')) ? 'key_conflict' : 'invalid_fields',
                    default => 'invalid_fields',
                };
                $errors[$field] = [__('ai_assist.validation.'.$key)];
            }

            return $this->failure($request, $errors);
        } catch (AiNetworkException $exception) {
            $code = AccountAiConnectionPresenter::failureCode($exception->reason);

            return $this->failure($request, ['connection' => [__('ai_assist.failures.'.$code)]]);
        } catch (AuthorizationException) {
            abort(403);
        } catch (Throwable) {
            return $this->failure($request, ['connection' => [__('ai_assist.failures.provider_unavailable')]]);
        }
    }

    private function failure(Request $request, array $errors): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => __('ai_assist.validation.invalid_fields'), 'errors' => $errors], 422);
        }
        if ($request->attributes->get('ai_assist_proposal_saved') === true) {
            // The action saved a draft before failing. Reload its current
            // fields and revision together, never mix old fields with a new ID.
            return redirect()->route('dashboard.account.ai-assist.show')->withErrors($errors)->withInput([]);
        }
        $old = $request->only(['source', 'name', 'provider', 'model', 'openrouter_provider', 'expected_revision', 'editor_token', 'clear_api_key',
            'daily_requests', 'daily_tokens', 'concurrency', 'max_output_tokens']);
        foreach ($old as $field => $value) {
            $valid = match ($field) {
                'clear_api_key' => in_array($value, [null, true, false, 0, 1, '0', '1'], true),
                'daily_requests', 'daily_tokens', 'concurrency', 'max_output_tokens' => $value === null || is_int($value)
                    || is_string($value) && preg_match('/\A-?[0-9]{1,12}\z/', $value) === 1,
                'expected_revision' => $value === null || is_string($value) && Str::isUuid($value),
                'editor_token' => is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1,
                default => $value === null || is_string($value),
            };
            if (! $valid) {
                unset($old[$field]);
            }
        }
        // Never flash a credential, including one embedded in an invalid URL.
        if ($this->safeEndpoint($request->input('endpoint'))) {
            $old['endpoint'] = $request->input('endpoint');
        }

        return redirect()->route('dashboard.account.ai-assist.show')->withErrors($errors)->withInput($old);
    }
}
