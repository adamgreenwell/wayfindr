<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Support\Ai\AgentCopilotConfiguration;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Settings\OperatorSettings;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Configure the optional provider-neutral agent copilot boundary. */
final class OperatorAiSettingsController extends Controller
{
    public function edit(
        Request $request,
        OperatorSettings $settings,
        AgentCopilotConfiguration $configuration,
    ): View {
        $provider = strtolower(trim((string) $settings->effective('ai.provider')));

        return view('operator.settings.ai', [
            'operator' => $request->user(),
            'provider' => $provider,
            'providers' => AgentCopilotConfiguration::PROVIDERS,
            // Preserve an env-provided future/third-party SDK driver so simply
            // opening and saving the form cannot silently switch providers.
            'externalProvider' => $provider !== '' && ! in_array($provider, AgentCopilotConfiguration::PROVIDERS, true)
                ? $provider
                : null,
            'model' => (string) $settings->effective('ai.model'),
            'endpoint' => (string) $settings->effective('ai.endpoint'),
            'apiKeyIsSet' => $settings->effectiveSecretStatus('ai.api_key') === 'set',
            'apiKeyUnreadable' => $settings->secretStatus('ai.api_key') === 'unreadable',
            'assessment' => $configuration->assessment(),
        ]);
    }

    public function update(
        Request $request,
        OperatorSettings $settings,
        AgentCopilotConfiguration $configuration,
    ): RedirectResponse {
        $currentProvider = strtolower(trim((string) $settings->effective('ai.provider')));
        $allowedProviders = array_values(array_unique(array_filter([
            ...AgentCopilotConfiguration::PROVIDERS,
            $currentProvider,
        ])));

        $validated = $request->validate([
            'provider' => ['nullable', Rule::in($allowedProviders)],
            'model' => ['nullable', 'string', 'max:255'],
            'endpoint' => [
                'bail',
                'nullable',
                'url:http,https',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || $value === '') {
                        return;
                    }

                    $parts = parse_url($value);

                    // Endpoints are rendered back to operators and stored as
                    // ordinary settings. Refuse URL components that commonly
                    // carry credentials instead of persisting them as plaintext.
                    if (! is_array($parts) || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []) {
                        $fail(__('operator.ai.validation.endpoint_secrets'));
                    }
                },
            ],
            // Registered with dontFlash in bootstrap/app.php. This value is
            // write-only and must never appear in old input or audit metadata.
            'api_key' => ['nullable', 'string', 'max:4096'],
            'clear_api_key' => ['nullable', 'boolean'],
        ]);

        $provider = strtolower(trim((string) ($validated['provider'] ?? '')));
        $model = trim((string) ($validated['model'] ?? ''));
        $endpoint = rtrim(trim((string) ($validated['endpoint'] ?? '')), '/');
        $apiKey = trim((string) ($validated['api_key'] ?? ''));
        $apiKeyProvided = $apiKey !== '';
        $clearApiKey = (bool) ($validated['clear_api_key'] ?? false);

        if ($apiKeyProvided && $clearApiKey) {
            throw ValidationException::withMessages([
                'api_key' => __('operator.ai.validation.clear_conflict'),
            ]);
        }

        $currentKeyStatus = $settings->effectiveSecretStatus('ai.api_key');

        if ($provider !== '' && $currentKeyStatus === 'unreadable' && ! $apiKeyProvided && ! $clearApiKey) {
            throw ValidationException::withMessages([
                'api_key' => __('operator.ai.validation.unreadable'),
            ]);
        }

        $hasApiKey = $apiKeyProvided || (! $clearApiKey && $currentKeyStatus === 'set');
        $assessment = $configuration->assessValues($provider, $model, $endpoint, $hasApiKey);

        if ($assessment['status'] === 'incomplete') {
            $field = in_array('model', $assessment['missing'], true)
                ? 'model'
                : (in_array('endpoint', $assessment['missing'], true) || in_array('valid_endpoint', $assessment['missing'], true)
                    ? 'endpoint'
                    : 'api_key');

            throw ValidationException::withMessages([
                $field => __('operator.ai.validation.'.$field),
            ]);
        }

        $agent = $request->user();

        DB::transaction(function () use (
            $agent,
            $apiKey,
            $apiKeyProvided,
            $assessment,
            $clearApiKey,
            $endpoint,
            $model,
            $provider,
            $settings,
        ): void {
            $settings->set('ai.provider', $provider);
            $settings->set('ai.model', $model);
            $settings->set('ai.endpoint', $endpoint);

            if ($clearApiKey) {
                $settings->set('ai.api_key', '');
            } elseif ($apiKeyProvided) {
                $settings->set('ai.api_key', $apiKey);
            }

            AuditEvent::query()->create([
                'account_id' => null,
                'actor_type' => $agent->getMorphClass(),
                'actor_id' => $agent->id,
                'action' => 'operator_settings.ai.updated',
                'metadata' => [
                    'provider' => $provider === '' ? 'none' : $provider,
                    'model' => $model === '' ? 'none' : $model,
                    'endpoint_configured' => $endpoint !== '',
                    'status' => $assessment['status'],
                    'api_key_changed' => $clearApiKey ? 'cleared' : ($apiKeyProvided ? 'updated' : 'unchanged'),
                ],
                'occurred_at' => now(),
            ]);
        });

        return redirect()
            ->route('operator.settings.ai.edit')
            ->with('status', 'operator.ai.flash.saved');
    }

    public function test(AgentCopilotProvider $copilot): RedirectResponse
    {
        try {
            $result = $copilot->probe();
        } catch (Throwable $exception) {
            // Provider exceptions can contain request URLs, account ids, or
            // echoed credentials. Keep both the operator response and host log
            // diagnostic metadata-only: never serialize the exception message.
            Log::warning('Agent copilot synthetic provider test failed.', [
                'exception_type' => $exception::class,
                'provider' => (string) config('ai.providers.wayfindr.driver'),
                'model' => (string) config('ai.providers.wayfindr.models.text.default'),
            ]);

            return redirect()
                ->route('operator.settings.ai.edit')
                ->with('error', 'operator.ai.flash.failed');
        }

        return redirect()
            ->route('operator.settings.ai.edit')
            ->with('status', [
                'key' => 'operator.ai.flash.connected',
                'parameters' => [
                    'provider' => $result->provider,
                    'model' => $result->model,
                ],
            ]);
    }
}
