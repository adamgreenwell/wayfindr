<?php

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\ExternalIssueProviderConnection;
use App\Models\User;
use App\Support\ExternalIssueCapability;
use App\Support\ExternalIssueProvider;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AgentExternalIssueProviderConnectionController extends Controller
{
    public function __construct(private readonly SiteManagerCoverage $siteManagerCoverage) {}

    public function store(Request $request): RedirectResponse
    {
        $agent = $request->user();

        abort_unless($agent?->hasAccountPermission(AccountPermission::ManageIntegrations), 403);

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

        DB::transaction(function () use ($account, $agent, $validated): void {
            $this->lockedIntegrationManager($agent, (int) $account->id);

            $account->externalIssueProviderConnections()->create([
                'provider' => $validated['provider'],
                'name' => trim($validated['name']),
                'base_url' => $this->blankToNull($validated['base_url'] ?? null),
                'credentials' => $this->credentials($validated['credential_token'] ?? null, $validated['webhook_secret'] ?? null),
                'capabilities' => ExternalIssueCapability::flags($validated['capabilities'] ?? []),
                'settings' => [],
                'is_enabled' => true,
            ]);
        });

        return $this->redirectAfterUpdate($account, $validated['site_id'] ?? null, $validated['return_to'] ?? null);
    }

    /**
     * Set (or clear) the inbound webhook secret on an existing connection.
     * The create form makes a new connection, so without this an existing
     * connection's inbound sync could never be turned on. Only the webhook
     * secret is editable here; other credentials are preserved.
     */
    public function updateWebhookSecret(Request $request, ExternalIssueProviderConnection $connection): RedirectResponse
    {
        $agent = $request->user();

        abort_unless($agent?->hasAccountPermission(AccountPermission::ManageIntegrations), 403);

        $account = $agent->account()->firstOrFail();
        abort_unless($connection->account_id === $account->id, 404);

        $validated = $request->validate([
            'webhook_secret' => ['nullable', 'string', 'max:4096'],
        ]);

        $secret = trim((string) ($validated['webhook_secret'] ?? ''));

        DB::transaction(function () use ($account, $agent, $connection, $secret): void {
            $this->lockedIntegrationManager($agent, (int) $account->id);
            $lockedConnection = $this->lockedConnection($connection, (int) $account->id);
            $credentials = $lockedConnection->credentials ?? [];

            if ($secret === '') {
                unset($credentials['webhook_secret']);
            } else {
                $credentials['webhook_secret'] = $secret;
            }

            $settings = $lockedConnection->settings ?? [];
            unset($settings['inbound_webhook']);

            $lockedConnection->forceFill([
                'credentials' => $credentials === [] ? null : $credentials,
                'settings' => $settings,
                'last_checked_at' => null,
            ])->save();
        });

        return redirect()
            ->route('dashboard.account.integrations')
            ->with('status', $secret === ''
                ? 'integrations.flash.secret_cleared'
                : 'integrations.flash.secret_saved');
    }

    public function updateCapabilities(Request $request, ExternalIssueProviderConnection $connection): RedirectResponse
    {
        $agent = $request->user();

        abort_unless($agent?->hasAccountPermission(AccountPermission::ManageIntegrations), 403);

        $account = $agent->account()->firstOrFail();
        abort_unless($connection->account_id === $account->id, 404);

        $validated = $request->validate([
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string', Rule::in(ExternalIssueCapability::values())],
        ]);

        DB::transaction(function () use ($account, $agent, $connection, $validated): void {
            $this->lockedIntegrationManager($agent, (int) $account->id);
            $lockedConnection = $this->lockedConnection($connection, (int) $account->id);

            $lockedConnection->forceFill([
                'capabilities' => ExternalIssueCapability::flags($validated['capabilities'] ?? []),
            ])->save();
        });

        return redirect()
            ->route('dashboard.account.integrations')
            ->with('status', 'integrations.flash.capabilities_updated');
    }

    private function redirectAfterUpdate(Account $account, mixed $siteId, ?string $returnTo = null): RedirectResponse
    {
        if ($returnTo === 'integrations') {
            return redirect()
                ->route('dashboard.account.integrations')
                ->with('status', 'integrations.flash.connection_saved');
        }

        if (is_numeric($siteId) && $account->sites()->whereKey((int) $siteId)->exists()) {
            return redirect()
                ->route('dashboard.sites.show', (int) $siteId)
                ->with('status', 'site_settings.flash.connection_saved');
        }

        return redirect()
            ->route('dashboard')
            ->with('status', 'Provider connection saved.');
    }

    private function lockedIntegrationManager(User $agent, int $accountId): User
    {
        $this->siteManagerCoverage->lockAccount($accountId);
        $lockedAgent = User::query()
            ->whereKey($agent->id)
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->first();

        abort_unless($lockedAgent?->hasAccountPermission(AccountPermission::ManageIntegrations), 403);

        return $lockedAgent;
    }

    private function lockedConnection(ExternalIssueProviderConnection $connection, int $accountId): ExternalIssueProviderConnection
    {
        return ExternalIssueProviderConnection::query()
            ->whereKey($connection->id)
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->firstOrFail();
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
