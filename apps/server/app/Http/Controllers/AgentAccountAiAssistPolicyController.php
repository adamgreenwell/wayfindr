<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Models\User;
use App\Support\Ai\AccountAiConnectionPresenter;
use App\Support\Ai\Policy\AiAssistPolicies;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class AgentAccountAiAssistPolicyController
{
    public function __construct(private AiAssistPolicies $policies, private AccountAiConnectionPresenter $presenter) {}

    public function account(Request $request): RedirectResponse|JsonResponse
    {
        return $this->save($request, false);
    }

    public function sites(Request $request): RedirectResponse|JsonResponse
    {
        return $this->save($request, true);
    }

    private function save(Request $request, bool $sites): RedirectResponse|JsonResponse
    {
        $actor = User::query()->find($request->user()?->id);
        abort_unless($actor !== null && $actor->account_id && ! $actor->isDeactivated()
            && $actor->hasAccountPermission(AccountPermission::ManageAiConnections), 403);
        try {
            $rules = ['mode' => ['required', Rule::in(['off', 'manual', 'prepare'])],
                'max_context_characters' => ['nullable', 'integer', 'min:1000', 'max:1000000']];
            foreach ([...AiAssistPolicyResolver::TASKS, 'article_context_consent'] as $task) {
                $rules[$task] = ['nullable', 'boolean'];
            }
            if ($sites) {
                $rules += ['account_policy_token' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
                    'site_ids' => ['required', 'array', 'min:1', 'max:1000'], 'site_ids.*' => ['integer', 'min:1'],
                    'site_tokens' => ['required', 'array', 'max:1000'], 'site_tokens.*' => ['string', 'regex:/\A[a-f0-9]{64}\z/']];
            } else {
                $rules['policy_token'] = ['required', 'regex:/\A[a-f0-9]{64}\z/'];
            }
            // Wildcard rule names are validation paths, never input names.
            // Request::only would synthesize literal '*' children in these arrays.
            $fields = array_filter(array_keys($rules), fn (string $field): bool => ! str_contains($field, '.'));
            $values = Validator::make($request->only($fields), $rules)->validate();
            foreach ([...AiAssistPolicyResolver::TASKS, 'article_context_consent'] as $task) {
                $values[$task] = $request->boolean($task);
            }
            if ($sites) {
                $this->policies->saveSites($actor, (int) $actor->account_id, $values['site_ids'], $values,
                    $values['account_policy_token'], $values['site_tokens']);
            } else {
                $this->policies->saveAccount($actor, (int) $actor->account_id, $values, $values['policy_token']);
            }
            if ($request->expectsJson()) {
                return response()->json(['assist' => $this->presenter->present($actor)]);
            }

            return redirect()->route('dashboard.account.ai-assist.show')->with('status', 'ai_assist.flash.policy_saved');
        } catch (ValidationException $exception) {
            $errors = [];
            foreach (array_keys($exception->errors()) as $field) {
                $key = match (explode('.', $field)[0]) {
                    'policy_token', 'account_policy_token', 'site_tokens' => 'policy_conflict',
                    'site_ids', 'site_scope' => 'site_scope', 'max_context_characters' => 'context_limit',
                    'article_context_consent' => 'article_consent', 'connection_required' => 'connection_required',
                    default => 'invalid_fields',
                };
                $errors[$field] = [__('ai_assist.validation.'.$key)];
            }

            return $this->failure($request, $sites, $errors);
        } catch (AuthorizationException) {
            abort(403);
        } catch (Throwable) {
            return $this->failure($request, $sites, ['policy' => [__('ai_assist.validation.invalid_fields')]]);
        }
    }

    private function failure(Request $request, bool $sites, array $errors): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => __('ai_assist.validation.invalid_fields'), 'errors' => $errors], 422);
        }
        $old = ['_policy_form' => $sites ? 'sites' : 'account'];
        foreach (['mode', 'max_context_characters', 'policy_token', 'account_policy_token',
            ...AiAssistPolicyResolver::TASKS, 'article_context_consent'] as $field) {
            $value = $request->input($field);
            $valid = match ($field) {
                'mode' => in_array($value, ['off', 'manual', 'prepare'], true),
                'max_context_characters' => $value === null || is_int($value)
                    || is_string($value) && preg_match('/\A[0-9]{1,7}\z/', $value) === 1,
                'policy_token', 'account_policy_token' => is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1,
                default => in_array($value, [null, true, false, 0, 1, '0', '1'], true),
            };
            if ($valid && $request->exists($field)) {
                $old[$field] = $value;
            }
        }
        // Required failed authority stays present, even when malformed or
        // missing. An empty sentinel must never fall back to a fresh token.
        $requiredToken = $sites ? 'account_policy_token' : 'policy_token';
        $old[$requiredToken] = is_string($request->input($requiredToken))
            && preg_match('/\A[a-f0-9]{64}\z/', $request->input($requiredToken)) === 1
                ? $request->input($requiredToken) : '';
        // Preserve original reviewed tokens, never stale values with fresh IDs.
        if ($sites) {
            $ids = $request->input('site_ids');
            $tokens = $request->input('site_tokens');
            if (is_array($ids)) {
                $old['site_ids'] = array_slice(array_values(array_filter($ids,
                    fn (mixed $id): bool => (is_int($id) || is_string($id) && ctype_digit($id)) && (int) $id > 0)), 0, 1000);
            }
            $old['site_tokens'] = [];
            foreach ($old['site_ids'] ?? [] as $id) {
                $token = is_array($tokens) ? ($tokens[$id] ?? null) : null;
                $old['site_tokens'][$id] = is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1 ? $token : '';
            }
            unset($old['policy_token']);
        } else {
            unset($old['account_policy_token']);
        }

        return redirect()->route('dashboard.account.ai-assist.show')->withErrors($errors)->withInput($old);
    }
}
