<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Issuing and revoking programmatic access to an account (ADR 0018).
 *
 * Admin-only, and account-scoped throughout. A token is a standing credential
 * for support transcripts, which is a heavier thing to hand out than any other
 * setting on this page -- so it is issued deliberately, listed with enough to
 * recognise it, and revocable in one click.
 */
class AgentAccountApiTokenController extends Controller
{
    public function index(Request $request): View
    {
        $agent = $request->user();
        $account = $agent->account()->firstOrFail();

        // Admin-only to READ, not merely to change. The list names the sites
        // each token reaches, and an agent whose site access is restricted
        // would otherwise learn the names of sites that 404 for them
        // everywhere else. Filtering the relationship would hide the names and
        // still leak the count, and no non-admin needs this page.
        abort_unless($agent->isAdmin(), 403);

        return view('agent.account.api-tokens', [
            'agent' => $agent,
            'account' => $account,
            'tokens' => $account->apiTokens()->with(['createdBy', 'sites'])->orderByDesc('created_at')->get(),
            // Same visibility rule the rest of the account pages follow: an
            // agent cannot restrict a token to a site they cannot themselves
            // see, because the picker would leak the site's name.
            'sites' => $account->sites()->visibleToAgent($agent)->orderBy('name')->get(),
            // Shown once, immediately after creation, and never again.
            'issuedToken' => $request->session()->get('issued_api_token'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $agent = $request->user();
        $account = $agent->account()->firstOrFail();

        abort_unless($agent->isAdmin(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'site_ids' => ['nullable', 'array'],
            'site_ids.*' => ['integer'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => [Rule::in(ApiToken::ABILITIES)],
        ]);

        $generated = ApiToken::generate();

        $token = $account->apiTokens()->create([
            'created_by_id' => $agent->id,
            'name' => $validated['name'],
            'token_hash' => $generated['hash'],
            'last_four' => $generated['last_four'],
            // Deny by default. A token created with no abilities ticked can
            // authenticate and read nothing, which is a safe thing to have
            // made by accident.
            'abilities' => array_values($validated['abilities'] ?? []),
            'expires_at' => isset($validated['expires_in_days'])
                ? now()->addDays((int) $validated['expires_in_days'])
                : null,
        ]);

        // **A token can never reach more than the person issuing it.**
        //
        // Site access restricts an admin as well as an agent -- `rbac-waypoints`
        // is explicit that no role bypasses it because a controller checked
        // only `account_id`, and "owner and admin may eventually need elevated
        // views across all sites, but that must be explicit". Issuing a token
        // is not that explicit decision.
        //
        // Without this an admin who cannot see every site could issue an
        // unrestricted token and read, through the API, exactly the
        // conversations the dashboard hides from them.
        $visibleSiteIds = $account->sites()->visibleToAgent($agent)->pluck('id');
        $accountSiteIds = $account->sites()->pluck('id');
        $requested = $validated['site_ids'] ?? [];
        $askedForSpecificSites = $request->has('site_ids');

        if ($askedForSpecificSites) {
            // Intersected, so a site id typed into the form cannot reach
            // anything the issuer could not. An empty result means the token
            // reaches NOTHING -- asking for less must never hand back more,
            // which is what falling through to "unrestricted" would do.
            $siteIds = $visibleSiteIds->intersect($requested)->values();
            $restricted = true;
        } else {
            // Account-wide, but only if the issuer can actually see the whole
            // account. Otherwise the token is pinned to their own reach.
            $restricted = $visibleSiteIds->count() !== $accountSiteIds->count();
            $siteIds = $restricted ? $visibleSiteIds->values() : collect();
        }

        $token->sites()->sync($siteIds);

        // Recorded on the token, not inferred from the rows just synced. If
        // every one of those sites is later purged this token reaches nothing,
        // which is what the operator asked for.
        $token->forceFill(['restricts_sites' => $restricted])->save();

        $this->audit($agent, $token, 'api_token.created', [
            'name' => $token->name,
            'abilities' => $token->abilities,
            'expires_at' => $token->expires_at?->toJSON(),
            'restricted_site_ids' => $siteIds->all(),
        ]);

        return redirect()
            ->route('dashboard.account.api-tokens.index')
            // Flashed rather than rendered from the model, because the model
            // does not have it: this is the only moment the plaintext exists.
            ->with('issued_api_token', $generated['plain'])
            ->with('status', $restricted && ! $askedForSpecificSites
                ? 'API token created, limited to the sites you support. Copy it now — it cannot be shown again.'
                : 'API token created. Copy it now — it cannot be shown again.');
    }

    public function destroy(Request $request, ApiToken $apiToken): RedirectResponse
    {
        $agent = $request->user();
        $account = $agent->account()->firstOrFail();

        // 404 rather than 403 for another account's token: the id should not
        // confirm anything.
        abort_unless((int) $apiToken->account_id === (int) $account->id, 404);
        abort_unless($agent->isAdmin(), 403);

        // Revoked, not deleted. The row is the record that this credential
        // existed and when it was last used, which is the part worth keeping
        // after somebody turns it off.
        $apiToken->forceFill(['revoked_at' => now()])->save();

        // Who turned it off, and when. Revoking a standing credential for
        // support transcripts is exactly the kind of act an account needs to
        // attribute afterwards -- and ADR 0018 says issuance and revocation are
        // audited, which the first version of this did not honour.
        $this->audit($agent, $apiToken, 'api_token.revoked', [
            'name' => $apiToken->name,
            'last_used_at' => $apiToken->last_used_at?->toJSON(),
        ]);

        return redirect()
            ->route('dashboard.account.api-tokens.index')
            ->with('status', 'API token revoked.');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(User $actor, ApiToken $token, string $action, array $metadata): void
    {
        AuditEvent::query()->create([
            'account_id' => $actor->account_id,
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->id,
            'subject_type' => $token->getMorphClass(),
            'subject_id' => $token->id,
            'action' => $action,
            // Never the token or its hash. The audit log is exportable, and a
            // record that a credential existed must not be a copy of it.
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
