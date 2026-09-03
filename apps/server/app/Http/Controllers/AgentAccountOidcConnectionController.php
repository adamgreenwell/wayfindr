<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\OidcConnection;
use App\Models\User;
use App\Support\Auth\Oidc\OidcHttpClientFactory;
use App\Support\Auth\PendingTwoFactorChallenge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class AgentAccountOidcConnectionController extends Controller
{
    public function update(Request $request, OidcHttpClientFactory $httpClients): RedirectResponse
    {
        $agent = $request->user();
        abort_unless($agent?->account_id && $agent->isAdmin(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'issuer_url' => ['required', 'string', 'max:2048'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:4096'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        $issuerUrl = trim($validated['issuer_url']);
        $issuerParts = parse_url($issuerUrl);
        $isEnabled = (bool) ($validated['is_enabled'] ?? false);

        try {
            if (! is_array($issuerParts) || isset($issuerParts['query'])) {
                throw new InvalidArgumentException('OIDC issuers cannot contain a query.');
            }

            $httpClients->assertValidHttpsUrl($issuerUrl);

            if ($isEnabled) {
                $httpClients->assertAllowed($issuerUrl);
            }
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'issuer_url' => __('oidc.settings.public_https_required'),
            ]);
        }

        $credentialFingerprint = PendingTwoFactorChallenge::credentialFingerprint($agent);

        DB::transaction(function () use ($agent, $validated, $issuerUrl, $isEnabled, $credentialFingerprint): void {
            $lockedAgent = User::query()->lockForUpdate()->findOrFail($agent->id);

            abort_unless(
                ! $lockedAgent->isDeactivated()
                && $lockedAgent->isAdmin()
                && (int) $lockedAgent->account_id === (int) $agent->account_id
                && hash_equals(
                    $credentialFingerprint,
                    PendingTwoFactorChallenge::credentialFingerprint($lockedAgent),
                ),
                403,
            );

            $account = Account::query()->lockForUpdate()->findOrFail($lockedAgent->account_id);
            $connection = OidcConnection::query()
                ->where('account_id', $account->id)
                ->lockForUpdate()
                ->first();
            $replacementSecret = $validated['client_secret'] ?? null;

            if ($connection === null && (! is_string($replacementSecret) || $replacementSecret === '')) {
                throw ValidationException::withMessages([
                    'client_secret' => __('oidc.settings.secret_required'),
                ]);
            }

            $values = [
                'configuration_version' => (string) Str::uuid(),
                'name' => trim($validated['name']),
                'issuer_url' => $issuerUrl,
                'client_id' => trim($validated['client_id']),
                'is_enabled' => $isEnabled,
            ];
            $identityLinksCleared = 0;

            if (is_string($replacementSecret) && $replacementSecret !== '') {
                $values['client_secret'] = $replacementSecret;
            }

            if ($connection === null) {
                $connection = new OidcConnection([
                    ...$values,
                    'public_id' => (string) Str::uuid(),
                ]);
                $connection->account()->associate($account);
                $connection->save();
            } else {
                // OIDC subjects are scoped to the issuer and may also be
                // pairwise per client. Carrying a binding across either
                // boundary could let the new authority reuse an opaque value
                // that belonged to somebody else under the old one.
                if ($connection->issuer_url !== $issuerUrl || $connection->client_id !== $values['client_id']) {
                    $identityLinksCleared = $connection->identities()->delete();
                }

                $connection->update($values);
            }

            AuditEvent::query()->create([
                'account_id' => $account->id,
                'actor_type' => $lockedAgent->getMorphClass(),
                'actor_id' => $lockedAgent->id,
                'subject_type' => $connection->getMorphClass(),
                'subject_id' => $connection->id,
                'action' => 'account.oidc_connection_updated',
                'metadata' => [
                    'enabled' => $connection->is_enabled,
                    'name' => $connection->name,
                    'identity_links_cleared' => $identityLinksCleared,
                ],
                'occurred_at' => now(),
            ]);
        });

        return redirect()
            ->route('dashboard.account.security.show')
            ->with('status', 'oidc.flash.connection_updated');
    }
}
