<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\ExternalIssueCapability;
use App\Support\ExternalIssueProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgentExternalIssueProviderConnectionController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $agent = $request->user();

        abort_unless($agent?->isAdmin(), 403);

        $account = $agent->account()->firstOrFail();

        $validated = $request->validate([
            'return_to' => ['nullable', 'string', Rule::in(['integrations'])],
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')->where('account_id', $account->id)],
            'provider' => ['required', 'string', Rule::in(ExternalIssueProvider::values())],
            'name' => ['required', 'string', 'max:255'],
            'base_url' => ['nullable', 'url', 'max:2048'],
            'credential_token' => ['nullable', 'string', 'max:4096'],
            'webhook_secret' => ['nullable', 'string', 'max:4096'],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string', Rule::in(ExternalIssueCapability::values())],
        ]);

        $account->externalIssueProviderConnections()->create([
            'provider' => $validated['provider'],
            'name' => trim($validated['name']),
            'base_url' => $this->blankToNull($validated['base_url'] ?? null),
            'credentials' => $this->credentials($validated['credential_token'] ?? null, $validated['webhook_secret'] ?? null),
            'capabilities' => ExternalIssueCapability::flags($validated['capabilities'] ?? []),
            'settings' => [],
            'is_enabled' => true,
        ]);

        return $this->redirectAfterUpdate($account, $validated['site_id'] ?? null, 'Provider connection saved.', $validated['return_to'] ?? null);
    }

    private function redirectAfterUpdate(Account $account, mixed $siteId, string $status, ?string $returnTo = null): RedirectResponse
    {
        if ($returnTo === 'integrations') {
            return redirect()
                ->route('dashboard.account.integrations')
                ->with('status', $status);
        }

        if (is_numeric($siteId) && $account->sites()->whereKey((int) $siteId)->exists()) {
            return redirect()
                ->route('dashboard.sites.show', (int) $siteId)
                ->with('status', $status);
        }

        return redirect()
            ->route('dashboard')
            ->with('status', $status);
    }

    /**
     * @return array<string, string>|null
     */
    private function credentials(?string $token, ?string $webhookSecret = null): ?array
    {
        $credentials = [];
        $token = trim((string) $token);
        $webhookSecret = trim((string) $webhookSecret);

        if ($token !== '') {
            $credentials['token'] = $token;
        }

        if ($webhookSecret !== '') {
            $credentials['webhook_secret'] = $webhookSecret;
        }

        return $credentials === [] ? null : $credentials;
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
