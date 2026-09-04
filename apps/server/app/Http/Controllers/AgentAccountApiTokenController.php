<?php

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\OutboundWebhookDelivery;
use App\Models\User;
use App\Support\DatabaseKey;
use App\Support\Sites\SiteManagerCoverage;
use App\Support\Webhooks\OutboundWebhookPermissions;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Issuing and revoking programmatic access to an account (ADR 0018).
 *
 * Restricted to manage-integrations permission, and account-scoped throughout. A token is a standing credential
 * for support transcripts, which is a heavier thing to hand out than any other
 * setting on this page -- so it is issued deliberately, listed with enough to
 * recognise it, and revocable in one click.
 */
class AgentAccountApiTokenController extends Controller
{
    public function __construct(private readonly SiteManagerCoverage $siteManagerCoverage) {}

    public function index(Request $request): View
    {
        $agent = $request->user();
        $account = $agent->account()->firstOrFail();

        // Permission-gated to READ, not merely to change. The list names the sites
        // each token reaches, and an agent whose site access is restricted
        // would otherwise learn the names of sites that 404 for them
        // everywhere else. Filtering the relationship would hide the names and
        // still leak the count, and no non-admin needs this page.
        abort_unless($agent->hasAccountPermission(AccountPermission::ManageIntegrations), 403);

        $visibleSiteIds = $account->sites()->visibleToAgentIncludingArchived($agent)->pluck('id')->all();

        return view('agent.account.api-tokens', [
            'agent' => $agent,
            'account' => $account,
            'tokens' => $account->apiTokens()->with(['createdBy', 'sites'])->orderByDesc('created_at')->get(),
            // Same visibility rule the rest of the account pages follow: an
            // agent cannot restrict a token to a site they cannot themselves
            // see, because the picker would leak the site's name.
            // The picker offers servable sites only: restricting a NEW token
            // to an archived site is not a thing anyone means to do.
            'sites' => $account->sites()->visibleToAgent($agent)->orderBy('name')->get(),
            // The public API groups several support actions behind each coarse
            // token ability. Only offer an ability when this issuer holds
            // every dashboard permission represented by that bundle.
            'grantableAbilities' => $this->grantableAbilities($agent),
            'grantableWebhookEvents' => OutboundWebhookPermissions::grantableEvents($agent),
            // What this admin may be shown of each token's reach. A token can
            // legitimately reach sites its viewer cannot, and naming those
            // would leak exactly what site access hides.
            'visibleSiteIds' => $visibleSiteIds,
            // Shown once, immediately after creation, and never again.
            'issuedToken' => $this->issuedToken($request),
            'webhookEndpoints' => $account->outboundWebhookEndpoints()
                ->with(['createdBy', 'sites'])
                ->orderByDesc('created_at')
                ->get(),
            // A newly site-limited admin must not gain identifiers from an old
            // endpoint's deliveries. The endpoint row remains visible like an
            // API token, but its delivery log follows current site visibility.
            'webhookDeliveries' => OutboundWebhookDelivery::query()
                ->with(['endpoint', 'site'])
                ->whereHas('endpoint', fn ($query) => $query->where('account_id', $account->id))
                ->whereIn('site_id', $visibleSiteIds)
                ->whereIn('event', OutboundWebhookPermissions::grantableEvents($agent))
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(),
            'issuedWebhookSecret' => $this->issuedWebhookSecret($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $agent = $request->user();
        $account = $agent->account()->firstOrFail();

        abort_unless($agent->hasAccountPermission(AccountPermission::ManageIntegrations), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'site_ids' => ['nullable', 'array'],
            'site_ids.*' => ['integer'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => [Rule::in(ApiToken::ABILITIES)],
        ]);

        $abilities = array_values($validated['abilities'] ?? []);
        $requested = $validated['site_ids'] ?? [];
        $askedForSpecificSites = $request->has('site_ids');
        $generated = ApiToken::generate();

        DB::transaction(function () use ($account, $abilities, $generated, $validated, $requested, $askedForSpecificSites, $agent): void {
            $this->siteManagerCoverage->lockAccount((int) $account->id);
            $lockedAgent = User::query()
                ->whereKey($agent->id)
                ->where('account_id', $account->id)
                ->lockForUpdate()
                ->first();

            abort_unless($lockedAgent?->hasAccountPermission(AccountPermission::ManageIntegrations), 403);

            // Managing integrations authorizes the credential lifecycle, not
            // the support work the credential performs. Recheck every bundle
            // against the freshly loaded actor while the role is locked.
            abort_unless(
                collect($abilities)->every(fn (string $ability): bool => in_array(
                    $ability,
                    $this->grantableAbilities($lockedAgent),
                    true,
                )),
                403,
            );

            $token = $account->apiTokens()->create([
                'created_by_id' => $lockedAgent->id,
                'name' => $validated['name'],
                'token_hash' => $generated['hash'],
                'last_four' => $generated['last_four'],
                // Deny by default. A token created with no abilities ticked can
                // authenticate and read nothing, which is a safe thing to have
                // made by accident.
                'abilities' => $abilities,
                'expires_at' => isset($validated['expires_in_days'])
                    ? now()->addDays((int) $validated['expires_in_days'])
                    : null,
            ]);

            // A token is always pinned to the freshly authorized issuer's
            // current site ceiling, including archived sites. It never widens
            // automatically when the account later adds another site.
            $visibleSiteIds = $account->sites()
                ->visibleToAgentIncludingArchived($lockedAgent)
                ->pluck('id');
            $siteIds = $askedForSpecificSites
                ? $visibleSiteIds->intersect($requested)->values()
                : $visibleSiteIds->values();

            $token->sites()->sync($siteIds);
            $token->forceFill(['restricts_sites' => true])->save();

            $this->audit($lockedAgent, $token, 'api_token.created', [
                'name' => $token->name,
                'abilities' => $token->abilities,
                'expires_at' => $token->expires_at?->toJSON(),
                'restricted_site_ids' => $siteIds->all(),
            ]);
        });

        return redirect()
            ->route('dashboard.account.api-tokens.index')
            // Flashed rather than rendered from the model, because the model
            // does not have it: this is the only moment the plaintext exists.
            //
            // ENCRYPTED before it goes anywhere near the session. The default
            // driver is `database` with `SESSION_ENCRYPT=false`, so flashing
            // the plaintext writes a usable bearer credential into the
            // `sessions` table -- recoverable from a database export, and
            // flatly contradicting the hash-only guarantee this page makes.
            // The app key is the same at-rest boundary provider credentials
            // already sit behind.
            ->with('issued_api_token', Crypt::encryptString($generated['plain']))
            // A KEY, not a sentence. This redirects, and the request that
            // renders the flash is a different request from this one -- the
            // agent's language is resolved per request, so a sentence chosen
            // here would be chosen in whatever language THIS request happened
            // to be resolved in.
            ->with('status', $askedForSpecificSites
                ? 'api_tokens.flash.created'
                : 'api_tokens.flash.created_limited');
    }

    /** @return list<string> */
    private function grantableAbilities(User $agent): array
    {
        return collect(ApiToken::ABILITIES)
            ->filter(fn (string $ability): bool => collect($this->permissionsForAbility($ability))
                ->every(fn (AccountPermission $permission): bool => $agent->hasAccountPermission($permission)))
            ->values()
            ->all();
    }

    /** @return list<AccountPermission> */
    private function permissionsForAbility(string $ability): array
    {
        return match ($ability) {
            ApiToken::ABILITY_READ => [
                AccountPermission::ViewConversations,
                AccountPermission::ManageTickets,
            ],
            ApiToken::ABILITY_WRITE => [
                AccountPermission::ViewConversations,
                AccountPermission::ReplyToConversations,
                AccountPermission::ManageConversations,
                AccountPermission::ManageTickets,
                AccountPermission::AssignTickets,
            ],
            default => [],
        };
    }

    /**
     * The id arrives RAW, not model-bound.
     *
     * Type-hinting `ApiToken` would have Laravel resolve it before this method
     * runs, so a non-admin probing ids would get 404 for one that does not
     * exist and 403 for one that does -- the same enumeration oracle the check
     * order below closes, reintroduced a layer above where no reordering inside
     * the controller can reach it.
     */
    public function destroy(Request $request, string $apiToken): RedirectResponse
    {
        $agent = $request->user();
        $account = $agent->account()->firstOrFail();

        // Authority FIRST, so a non-admin learns nothing from which refusal
        // they get -- including whether the id exists at all.
        abort_unless($agent->hasAccountPermission(AccountPermission::ManageIntegrations), 403);

        // Numeric is not the same as a usable key. The route constraint allows
        // any run of digits, and PostgreSQL raises casting a 30-digit value to
        // a bigint -- a 500 where the point was an indistinguishable 404. An
        // id too large to be one is treated exactly like an id that is not
        // there, because it cannot be.
        $apiToken = DatabaseKey::isValid($apiToken)
            ? ApiToken::query()->whereKey($apiToken)->first()
            : null;

        // 404 for a token that does not exist and for one belonging to another
        // account, identically: for somebody who IS an admin here, the id
        // should still not confirm anything.
        abort_if($apiToken === null || (int) $apiToken->account_id !== (int) $account->id, 404);

        // Read, check, write and audit under one row lock, following the
        // lifecycle transitions in `AgentConversationController`.
        //
        // Checking `isRevoked()` on the model loaded above only defeats a
        // SEQUENTIAL retry. Two DELETEs in flight together both read an
        // unrevoked row, both pass the guard, and both write -- stamping the
        // later time over the moment the credential was actually disabled and
        // recording the revocation twice. This account's audit trail is a
        // product feature rather than a debug log, so a duplicate entry is a
        // wrong answer to "who turned this off, and when".
        $alreadyRevoked = DB::transaction(function () use ($account, $agent, $apiToken): bool {
            $this->siteManagerCoverage->lockAccount((int) $account->id);
            $agent = User::query()
                ->whereKey($agent->id)
                ->where('account_id', $account->id)
                ->lockForUpdate()
                ->first();

            abort_unless($agent?->hasAccountPermission(AccountPermission::ManageIntegrations), 403);

            $locked = ApiToken::query()
                ->whereKey($apiToken->getKey())
                ->where('account_id', $account->id)
                ->lockForUpdate()
                ->first();

            // Gone between the check above and the lock. Nothing to revoke and
            // nothing to say about it that the 404 above would not have said.
            if ($locked === null) {
                return true;
            }

            // Decided from the LOCKED row, not the one read before the lock.
            if ($locked->isRevoked()) {
                return true;
            }

            // Revoked, not deleted. The row is the record that this credential
            // existed and when it was last used, which is the part worth
            // keeping after somebody turns it off.
            $locked->forceFill(['revoked_at' => now()])->save();

            // Written while the lock is held, so exactly one of two concurrent
            // revocations records one. ADR 0018 says issuance and revocation
            // are audited, which the first version of this did not honour.
            $this->audit($agent, $locked, 'api_token.revoked', [
                'name' => $locked->name,
                'last_used_at' => $locked->last_used_at?->toJSON(),
            ]);

            return false;
        });

        return redirect()
            ->route('dashboard.account.api-tokens.index')
            ->with('status', $alreadyRevoked
                ? 'api_tokens.flash.already_revoked'
                : 'api_tokens.flash.revoked');
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

    /**
     * The plaintext token to show once, if this request is the one that made it.
     *
     * Forgotten rather than reported when it cannot be decrypted: a rotated app
     * key or a hand-edited session should show the page without its banner, not
     * an error about a credential.
     */
    private function issuedToken(Request $request): ?string
    {
        $flashed = $request->session()->get('issued_api_token');

        if (! is_string($flashed) || $flashed === '') {
            return null;
        }

        try {
            return Crypt::decryptString($flashed);
        } catch (DecryptException) {
            return null;
        }
    }

    private function issuedWebhookSecret(Request $request): ?string
    {
        $flashed = $request->session()->get('issued_webhook_secret');

        if (! is_string($flashed) || $flashed === '') {
            return null;
        }

        try {
            return Crypt::decryptString($flashed);
        } catch (DecryptException) {
            return null;
        }
    }
}
