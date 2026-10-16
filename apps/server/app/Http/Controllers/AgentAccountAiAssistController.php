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
        $actor = $this->actor($request);
        $assist = $this->presenter->present($actor);
        $assist['models'] = $this->matchingModels($actor, $assist, $request->session()->pull('ai_assist_discovery_models'));

        return view('agent.account.ai-assist', ['assist' => $assist]);
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

    public function discover(Request $request): RedirectResponse|JsonResponse
    {
        return $this->perform($request, function (User $actor) use ($request): array {
            $input = $this->connectionInput($request);
            $draft = $this->connections->proposeForRevision($actor, (int) $actor->account_id, $input, $input['expected_revision'], $input['editor_token']);
            $request->attributes->set('ai_assist_proposal_saved', true);
            // Bind suggestions to the proposal actually dispatched, never to
            // a newer processor that another manager may save before redirect.
            $request->attributes->set('ai_assist_discovery_scope', [
                'account_id' => (int) $actor->account_id, 'proposed_revision' => $draft->proposed_revision,
                'editor_token' => AccountAiConnections::editorToken((int) $actor->account_id, $draft),
            ]);

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
            $input = Validator::make($request->only([...$fields, 'limits_token']), [
                ...array_fill_keys($fields, ['present', 'nullable', 'integer', 'min:0']),
                'limits_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            ])->validate();
            $this->limits->setAccountLimits($actor, (int) $actor->account_id, array_intersect_key($input, array_flip($fields)), $input['limits_token']);
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

    private function perform(Request $request, Closure $operation, string $status, bool $discovery = false): RedirectResponse|JsonResponse
    {
        $actor = $this->actor($request);
        try {
            $models = $operation($actor);
            $receipt = $discovery ? [
                ...$request->attributes->get('ai_assist_discovery_scope'), 'models' => $this->boundedModels($models),
            ] : null;
            if ($request->expectsJson()) {
                $assist = $this->presenter->present($actor);
                if ($discovery) {
                    $models = $this->matchingModels($actor, $assist, $receipt);
                    $assist['models'] = $models;
                }

                return response()->json(['assist' => $assist, ...($discovery ? ['models' => $models] : [])]);
            }

            $response = redirect()->route('dashboard.account.ai-assist.show')->with('status', 'ai_assist.flash.'.$status);

            return $discovery ? $response->with('ai_assist_discovery_models', $receipt) : $response;
        } catch (ValidationException $exception) {
            $errors = [];
            foreach (array_keys($exception->errors()) as $field) {
                $key = match ($field) {
                    'expected_revision', 'editor_token' => 'stale_edit', 'limits_token' => 'limits_conflict', 'endpoint' => 'invalid_endpoint',
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

    /** Optional browser suggestions are bounded and expire with their reviewed scope. */
    private function matchingModels(User $actor, array $assist, mixed $receipt): array
    {
        if (! is_array($receipt) || ($receipt['account_id'] ?? null) !== (int) $actor->account_id
            || ($receipt['proposed_revision'] ?? null) !== $assist['form']['expected_revision']
            || ! is_string($receipt['editor_token'] ?? null)
            || ! hash_equals($assist['form']['editor_token'], $receipt['editor_token'])) {
            return [];
        }

        return $this->boundedModels($receipt['models'] ?? null);
    }

    private function boundedModels(mixed $models): array
    {
        if (! is_array($models) || ! array_is_list($models)) {
            return [];
        }
        $bounded = [];
        foreach (array_slice($models, 0, 100) as $model) {
            if (is_string($model) && strlen($model) <= 255 && preg_match('/\A[^\x00-\x20\x7f]+\z/u', $model) === 1
                && ! in_array($model, $bounded, true)) {
                $bounded[] = $model;
            }
        }

        return $bounded;
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
        $connectionEdit = $request->routeIs(
            'dashboard.account.ai-assist.propose', 'dashboard.account.ai-assist.test',
            'dashboard.account.ai-assist.discover', 'dashboard.account.ai-assist.activate',
        );
        // Errors belong to the submitted form. Limits cannot replace connection
        // fields, and management actions preserve only their reviewed authority.
        $old = $request->only(match (true) {
            $connectionEdit => ['source', 'name', 'provider', 'model', 'openrouter_provider', 'expected_revision', 'editor_token', 'clear_api_key'],
            $request->routeIs('dashboard.account.ai-assist.limits') => ['daily_requests', 'daily_tokens', 'concurrency', 'max_output_tokens', 'limits_token'],
            default => ['expected_revision', 'editor_token'],
        });
        foreach ($old as $field => $value) {
            $valid = match ($field) {
                'clear_api_key' => in_array($value, [null, true, false, 0, 1, '0', '1'], true),
                'daily_requests', 'daily_tokens', 'concurrency', 'max_output_tokens' => $value === null || is_int($value)
                    || is_string($value) && preg_match('/\A-?[0-9]{1,12}\z/', $value) === 1,
                'expected_revision' => $value === null || is_string($value) && Str::isUuid($value),
                'editor_token', 'limits_token' => is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1,
                default => $value === null || is_string($value),
            };
            if (! $valid) {
                unset($old[$field]);
            }
        }
        if ($request->routeIs('dashboard.account.ai-assist.limits')) {
            // A missing/malformed token is failed authority, not permission to
            // combine old numeric values with a fresh token on the redirect.
            $old['limits_token'] ??= '';
        }
        // Never flash a credential, including one embedded in an invalid URL.
        if ($connectionEdit && $request->has('endpoint') && $this->safeEndpoint($request->input('endpoint'))) {
            $old['endpoint'] = $request->input('endpoint');
        }

        if ($connectionEdit && $request->input('source') === 'account') {
            $old['_ai_assist_redacted_endpoint_edit'] = $request->has('endpoint') && ! $this->safeEndpoint($request->input('endpoint'));
            $key = $request->input('api_key');
            // Retain only the fact that a credential edit failed, never its
            // value or a digest. Blank replacements keep the tested credential.
            $old['_ai_assist_credential_edit'] = $request->boolean('clear_api_key')
                || ($key !== null && (! is_string($key) || trim($key) !== ''));
        }

        return redirect()->route('dashboard.account.ai-assist.show')->withErrors($errors)->withInput($old);
    }
}
