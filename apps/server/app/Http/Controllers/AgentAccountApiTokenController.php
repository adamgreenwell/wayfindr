<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
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

        return view('agent.account.api-tokens', [
            'agent' => $agent,
            'account' => $account,
            'tokens' => $account->apiTokens()->with(['createdBy', 'sites'])->orderByDesc('created_at')->get(),
            // Same visibility rule the rest of the account pages follow: an
            // agent cannot restrict a token to a site they cannot themselves
            // see, because the picker would leak the site's name.
            'sites' => $account->sites()->visibleToAgent($agent)->orderBy('name')->get(),
            'canManageTokens' => $agent->isAdmin(),
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

        // Intersected with what this agent can see, so a site id typed into the
        // form cannot restrict-and-thereby-reach anything they could not.
        $siteIds = $account->sites()
            ->visibleToAgent($agent)
            ->whereIn('id', $validated['site_ids'] ?? [])
            ->pluck('id');

        $token->sites()->sync($siteIds);

        return redirect()
            ->route('dashboard.account.api-tokens.index')
            // Flashed rather than rendered from the model, because the model
            // does not have it: this is the only moment the plaintext exists.
            ->with('issued_api_token', $generated['plain'])
            ->with('status', 'API token created. Copy it now — it cannot be shown again.');
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

        return redirect()
            ->route('dashboard.account.api-tokens.index')
            ->with('status', 'API token revoked.');
    }
}
