<?php

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\BreakGlassGrant;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\CustomRole;
use App\Models\OidcConnection;
use App\Models\OidcIdentity;
use App\Models\OutboundWebhookEndpoint;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\ReaderClock;
use App\Support\SpreadsheetSafeCsv;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentAccountAuditController extends Controller
{
    public function index(Request $request): View
    {
        $agent = $this->accountAdmin($request);
        $account = $agent->account()->firstOrFail();
        $visibleSites = $this->visibleSites($account, $agent);
        $visibleSiteIds = $this->siteIds($visibleSites);
        $canViewConversations = $agent->hasAccountPermission(AccountPermission::ViewConversations);
        $canManageTickets = $agent->hasAccountPermission(AccountPermission::ManageTickets);
        $baseQuery = $this->baseAuditQuery($account, $visibleSiteIds);
        $availableActions = $this->availableActions($baseQuery);
        [$auditAction, $auditSearch, $auditSiteId] = $this->filters($request, $availableActions, $visibleSiteIds);
        $auditQuery = $this->auditQueryParams($auditAction, $auditSearch, $auditSiteId);
        $auditEvents = $this->auditEvents(
            $baseQuery,
            $auditAction,
            $auditSearch,
            $auditSiteId,
            50,
            $canViewConversations,
            $canManageTickets,
        )->map(fn (AuditEvent $event): array => $this->auditItem(
            $event,
            $canViewConversations,
            $canManageTickets,
        ));

        return view('agent.account.audit', [
            'account' => $account,
            'agent' => $agent,
            'auditAction' => $auditAction,
            'auditActions' => $availableActions,
            'auditEvents' => $auditEvents,
            'auditQuery' => $auditQuery,
            'auditSearch' => $auditSearch,
            'auditSiteId' => $auditSiteId,
            'auditSites' => $visibleSites,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $agent = $this->accountAdmin($request);
        $account = $agent->account()->firstOrFail();
        $visibleSiteIds = $this->siteIds($this->visibleSites($account, $agent));
        $canViewConversations = $agent->hasAccountPermission(AccountPermission::ViewConversations);
        $canManageTickets = $agent->hasAccountPermission(AccountPermission::ManageTickets);
        $baseQuery = $this->baseAuditQuery($account, $visibleSiteIds);
        [$auditAction, $auditSearch, $auditSiteId] = $this->filters($request, $this->availableActions($baseQuery), $visibleSiteIds);
        $auditEvents = $this->auditEvents(
            $baseQuery,
            $auditAction,
            $auditSearch,
            $auditSiteId,
            500,
            $canViewConversations,
            $canManageTickets,
        );

        return response()->streamDownload(function () use ($auditEvents, $canViewConversations, $canManageTickets): void {
            $stream = fopen('php://output', 'w');

            if ($stream === false) {
                return;
            }

            fputcsv($stream, ['occurred_at', 'action', 'label', 'actor', 'subject', 'site']);

            foreach ($auditEvents as $event) {
                fputcsv($stream, $this->auditCsvRow($event, $canViewConversations, $canManageTickets));
            }

            fclose($stream);
        }, 'wayfindr-account-audit-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function accountAdmin(Request $request): User
    {
        $agent = $request->user();

        abort_unless($agent?->account_id && $agent->hasAccountPermission(AccountPermission::ViewAudit), 403);

        return $agent;
    }

    /**
     * @return Collection<int, Site>
     */
    private function visibleSites(Account $account, User $agent): Collection
    {
        // Audit records outlive a site being in service, so the filter has to
        // keep offering archived sites.
        return $account->sites()
            ->visibleToAgentIncludingArchived($agent)
            ->orderBy('name')
            ->orderBy('domain')
            ->get();
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array<int, int>
     */
    private function siteIds(Collection $sites): array
    {
        return $sites
            ->pluck('id')
            ->map(fn (int|string $siteId): int => (int) $siteId)
            ->all();
    }

    /**
     * @param  array<int, int>  $visibleSiteIds
     * @return Builder<AuditEvent>
     */
    private function baseAuditQuery(Account $account, array $visibleSiteIds): Builder
    {
        return AuditEvent::query()
            ->with(['actor', 'subject', 'site'])
            ->where('account_id', $account->id)
            ->where(function (Builder $query) use ($visibleSiteIds): void {
                $query->whereNull('site_id');

                if ($visibleSiteIds !== []) {
                    $query->orWhereIn('site_id', $visibleSiteIds);
                }
            });
    }

    /**
     * @param  Builder<AuditEvent>  $baseQuery
     * @return array<string, array{label: string, language: string|null}>
     */
    private function availableActions(Builder $baseQuery): array
    {
        return (clone $baseQuery)
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->filter(fn ($action): bool => is_string($action) && $action !== '')
            ->mapWithKeys(function (string $action): array {
                $key = $this->auditActionKey($action);

                return [$action => Lang::has($key)
                    ? ['label' => __($key), 'language' => null]
                    : ['label' => $action, 'language' => '']];
            })
            ->all();
    }

    /**
     * @param  array<string, array{label: string, language: string|null}>  $availableActions
     * @param  array<int, int>  $visibleSiteIds
     * @return array{0: string, 1: string, 2: int|null}
     */
    private function filters(Request $request, array $availableActions, array $visibleSiteIds): array
    {
        $auditAction = $request->query('audit_action', '');
        $auditAction = is_string($auditAction) && array_key_exists($auditAction, $availableActions)
            ? $auditAction
            : '';
        $auditSearch = $request->query('audit_search', '');
        $auditSearch = is_string($auditSearch)
            ? mb_substr(trim($auditSearch), 0, 120)
            : '';
        $auditSite = $request->query('audit_site', '');
        $auditSiteId = is_string($auditSite) && ctype_digit($auditSite)
            ? (int) $auditSite
            : null;
        $auditSiteId = $auditSiteId !== null && in_array($auditSiteId, $visibleSiteIds, true)
            ? $auditSiteId
            : null;

        return [$auditAction, $auditSearch, $auditSiteId];
    }

    /**
     * @param  Builder<AuditEvent>  $baseQuery
     * @return Collection<int, AuditEvent>
     */
    private function auditEvents(
        Builder $baseQuery,
        string $auditAction,
        string $auditSearch,
        ?int $auditSiteId,
        int $limit,
        bool $canViewConversations,
        bool $canManageTickets,
    ): Collection {
        return $this->applyAuditFilters(
            clone $baseQuery,
            $auditAction,
            $auditSearch,
            $auditSiteId,
            $canViewConversations,
            $canManageTickets,
        )
            ->latest('occurred_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The dashboard presentation is deliberately separate from the CSV row.
     * A screen follows the reader's locale; an export keeps stable headers and
     * a sortable timestamp for whichever spreadsheet or script opens it next.
     *
     * @return array{
     *     occurred_at: string,
     *     action: string,
     *     label: string,
     *     actor: array{prefix: string|null, value: string|null},
     *     subject: array{prefix: string|null, value: string|null},
     *     site: array{prefix: string|null, value: string|null}
     * }
     */
    private function auditItem(AuditEvent $event, bool $canViewConversations, bool $canManageTickets): array
    {
        return [
            'occurred_at' => $event->occurred_at === null ? '' : ReaderClock::dateTime($event->occurred_at),
            'action' => $event->action,
            'label' => $this->translatedAuditLabel($event->action),
            'actor' => $this->auditActorParts($event, $canViewConversations || $canManageTickets),
            'subject' => $this->auditSubjectParts($event, $canViewConversations, $canManageTickets),
            'site' => $event->site
                ? ['prefix' => null, 'value' => $event->site->name]
                : ['prefix' => __('account_audit.references.account'), 'value' => null],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function auditCsvRow(AuditEvent $event, bool $canViewConversations, bool $canManageTickets): array
    {
        // Stable English reference labels and a sortable timestamp on purpose:
        // the file may be read under a different locale from the dashboard that
        // downloaded it, or keyed by a script rather than read by a person.
        return SpreadsheetSafeCsv::row([
            $event->occurred_at === null
                ? ''
                : ReaderClock::moment($event->occurred_at)->toDateTimeString(),
            $event->action,
            $this->auditLabel($event->action),
            $this->auditActor($event, $canViewConversations || $canManageTickets),
            $this->auditSubject($event, $canViewConversations, $canManageTickets),
            $event->site?->name ?? 'Account',
        ]);
    }

    /**
     * @param  Builder<AuditEvent>  $query
     * @return Builder<AuditEvent>
     */
    private function applyAuditFilters(
        Builder $query,
        string $auditAction,
        string $auditSearch,
        ?int $auditSiteId,
        bool $canViewConversations,
        bool $canManageTickets,
    ): Builder {
        return $query
            ->when($auditAction !== '', fn (Builder $query) => $query->where('action', $auditAction))
            ->when($auditSiteId !== null, fn (Builder $query) => $query->where('site_id', $auditSiteId))
            ->when($auditSearch !== '', function (Builder $query) use ($auditSearch, $canViewConversations, $canManageTickets): void {
                $searchPattern = '%'.$auditSearch.'%';

                $query->where(function (Builder $query) use ($searchPattern, $canViewConversations, $canManageTickets): void {
                    $query
                        ->whereLike('action', $searchPattern)
                        ->orWhereHas('site', fn (Builder $query) => $query
                            ->whereLike('name', $searchPattern)
                            ->orWhereLike('domain', $searchPattern))
                        ->orWhereHasMorph('actor', [User::class], fn (Builder $query) => $query
                            ->whereLike('name', $searchPattern)
                            ->orWhereLike('email', $searchPattern))
                        ->orWhereHasMorph('actor', [ApiToken::class], fn (Builder $query) => $query
                            ->whereLike('name', $searchPattern)
                            ->orWhereLike('last_four', $searchPattern))
                        ->orWhereHasMorph('subject', [User::class], fn (Builder $query) => $query
                            ->whereLike('name', $searchPattern)
                            ->orWhereLike('email', $searchPattern))
                        ->orWhereHasMorph('subject', [Site::class], fn (Builder $query) => $query
                            ->whereLike('name', $searchPattern)
                            ->orWhereLike('domain', $searchPattern))
                        ->orWhereHasMorph('subject', [CustomRole::class], fn (Builder $query) => $query
                            ->whereLike('name', $searchPattern))
                        ->orWhereLike('metadata->role_name', $searchPattern)
                        ->orWhereLike('metadata->old_role_name', $searchPattern)
                        ->orWhereLike('metadata->new_role_name', $searchPattern)
                        // The token's name and last four are what the subject
                        // column shows, so the search has to reach them --
                        // otherwise an account with several credentials can see
                        // which one an event concerns but cannot filter to it.
                        ->orWhereHasMorph('subject', [ApiToken::class], fn (Builder $query) => $query
                            ->whereLike('name', $searchPattern)
                            ->orWhereLike('last_four', $searchPattern))
                        ->orWhereHasMorph('subject', [OidcConnection::class], fn (Builder $query) => $query
                            ->whereLike('name', $searchPattern))
                        ->orWhereHasMorph('subject', [OidcIdentity::class], fn (Builder $query) => $query
                            ->whereHas('connection', fn (Builder $query) => $query->whereLike('name', $searchPattern)))
                        ->orWhereLike('metadata->oidc_provider_name', $searchPattern);

                    if ($canViewConversations || $canManageTickets) {
                        $query->orWhereHasMorph('actor', [Visitor::class], fn (Builder $query) => $query
                            ->whereLike('name', $searchPattern)
                            ->orWhereLike('email', $searchPattern)
                            ->orWhereLike('external_id', $searchPattern)
                            ->orWhereLike('anonymous_id', $searchPattern));
                    }

                    if ($canViewConversations) {
                        $query
                            ->orWhereHasMorph('subject', [Conversation::class], fn (Builder $query) => $query
                                ->whereLike('support_code', $searchPattern))
                            ->orWhereHasMorph('subject', [CobrowseSession::class], fn (Builder $query) => $query
                                ->whereHas('conversation', fn (Builder $query) => $query->whereLike('support_code', $searchPattern)));
                    }

                    $breakGlassTypes = [BreakGlassGrant::SCOPE_ACCOUNT, BreakGlassGrant::SCOPE_SITE];

                    if ($canViewConversations) {
                        $breakGlassTypes[] = BreakGlassGrant::SCOPE_CONVERSATION;
                    }

                    if ($canManageTickets) {
                        $breakGlassTypes[] = 'ticket';
                    }

                    // Only search labels this reader may also see. Otherwise
                    // the result count becomes an oracle for a support code,
                    // visitor, or ticket reference hidden in the row itself.
                    $query
                        ->orWhere(function (Builder $query) use ($breakGlassTypes, $searchPattern): void {
                            $query->whereIn('metadata->resource_type', $breakGlassTypes)
                                ->whereLike('metadata->resource_label', $searchPattern);
                        })
                        ->orWhere(function (Builder $query) use ($breakGlassTypes, $searchPattern): void {
                            $query->whereIn('metadata->scope_type', $breakGlassTypes)
                                ->whereLike('metadata->scope_label', $searchPattern);
                        });

                    if ($canViewConversations && $canManageTickets) {
                        // Full support readers retain search for legacy or
                        // provider-defined reference types that predate the
                        // explicit conversation/ticket taxonomy above.
                        $query
                            ->orWhereLike('metadata->resource_label', $searchPattern)
                            ->orWhereLike('metadata->scope_label', $searchPattern);
                    }
                });
            });
    }

    /**
     * @return array<string, string>
     */
    private function auditQueryParams(string $auditAction, string $auditSearch, ?int $auditSiteId): array
    {
        return array_filter([
            'audit_action' => $auditAction,
            'audit_search' => $auditSearch,
            'audit_site' => $auditSiteId !== null ? (string) $auditSiteId : '',
        ], fn (string $value): bool => $value !== '');
    }

    private function auditLabel(string $action): string
    {
        return match ($action) {
            'agent.created' => 'Agent created',
            'agent.deactivated' => 'Agent deactivated',
            'agent.password_updated' => 'Password changed',
            'agent.reactivated' => 'Agent reactivated',
            'agent.role_changed' => 'Agent role changed',
            'custom_role.created' => 'Custom role created',
            'custom_role.updated' => 'Custom role updated',
            'custom_role.deleted' => 'Custom role deleted',
            'site_access.updated' => 'Site access updated',
            'api_token.created' => 'API token issued',
            'api_token.revoked' => 'API token revoked',
            'outbound_webhook.created' => 'Outbound webhook created',
            'outbound_webhook.disabled' => 'Outbound webhook disabled',
            'outbound_webhook.delivery_retried' => 'Outbound webhook delivery retried',
            'account.oidc_connection_updated' => 'Single sign-on settings updated',
            'account.oidc_provisioning_updated' => 'Single sign-on provisioning updated',
            'account.oidc_role_mapping_created' => 'Single sign-on role mapping added',
            'account.oidc_role_mapping_deleted' => 'Single sign-on role mapping removed',
            'agent.oidc_identity_linked' => 'Single sign-on identity linked',
            'agent.oidc_provisioned' => 'Agent provisioned through single sign-on',
            'agent.oidc_role_mapped' => 'Agent role mapped through single sign-on',
            'agent.oidc_signed_in' => 'Signed in with single sign-on',
            // Without these the default arm headline-cases the raw action
            // and the account reads "Break Glass Resource Viewed" in its
            // own audit log -- the most user-facing place the old term had.
            'break_glass.requested' => 'Operator access requested',
            'break_glass.approved' => 'Operator access approved',
            'break_glass.self_approved' => 'Operator access self-approved',
            'break_glass.denied' => 'Operator access denied',
            'break_glass.opened' => 'Operator started viewing',
            'break_glass.resource_viewed' => 'Operator viewed a record',
            'break_glass.closed' => 'Operator access ended',
            'break_glass.expired' => 'Operator access expired',
            default => str($action)->replace(['.', '_'], ' ')->headline()->toString(),
        };
    }

    private function translatedAuditLabel(string $action): string
    {
        $key = $this->auditActionKey($action);

        return Lang::has($key) ? __($key) : __('account_audit.actions.other');
    }

    private function auditActionKey(string $action): string
    {
        return 'account_audit.actions.'.str_replace(['.', '-'], '_', $action);
    }

    /** @return array{prefix: string|null, value: string|null} */
    private function auditActorParts(AuditEvent $event, bool $canViewVisitorIdentity): array
    {
        if ($event->actor instanceof User) {
            return ['prefix' => null, 'value' => $event->actor->name];
        }

        if ($event->actor instanceof Visitor) {
            return [
                'prefix' => __('account_audit.references.visitor'),
                'value' => $canViewVisitorIdentity ? $this->visitorLabel($event->actor) : null,
            ];
        }

        if ($event->actor instanceof ApiToken) {
            return [
                'prefix' => __('account_audit.references.integration'),
                'value' => $event->actor->name.' ('.$event->actor->displayHint().')',
            ];
        }

        return ['prefix' => __('account_audit.references.system'), 'value' => null];
    }

    /** @return array{prefix: string|null, value: string|null} */
    private function auditSubjectParts(AuditEvent $event, bool $canViewConversations, bool $canManageTickets): array
    {
        if ($event->subject instanceof BreakGlassGrant) {
            return $this->breakGlassReferenceParts($event, $canViewConversations, $canManageTickets);
        }

        if ($event->subject instanceof User || $event->subject instanceof Site) {
            return ['prefix' => null, 'value' => $event->subject->name];
        }

        if ($event->subject instanceof CustomRole || $this->isDeletedCustomRoleSubject($event)) {
            return [
                'prefix' => __('account_audit.references.custom_role'),
                'value' => $event->subject instanceof CustomRole
                    ? $event->subject->name
                    : $this->customRoleName($event),
            ];
        }

        if ($event->subject instanceof ApiToken) {
            return [
                'prefix' => __('account_audit.references.api_token'),
                'value' => $event->subject->name.' ('.$event->subject->displayHint().')',
            ];
        }

        if ($event->subject instanceof OutboundWebhookEndpoint) {
            return [
                'prefix' => __('account_audit.references.outbound_webhook'),
                'value' => $event->subject->name.' ('.$event->subject->secretHint().')',
            ];
        }

        if ($event->subject instanceof OidcConnection) {
            return [
                'prefix' => __('account_audit.references.oidc_connection'),
                'value' => $event->subject->name,
            ];
        }

        if ($this->isOidcIdentitySubject($event)) {
            return [
                'prefix' => __('account_audit.references.oidc_identity'),
                'value' => $this->oidcProviderName($event),
            ];
        }

        if ($event->subject instanceof Conversation) {
            return [
                'prefix' => __('account_audit.references.conversation'),
                'value' => $canViewConversations ? $event->subject->support_code : null,
            ];
        }

        if ($event->subject instanceof CobrowseSession) {
            $event->subject->loadMissing('conversation');

            return [
                'prefix' => __('account_audit.references.cobrowse'),
                'value' => $canViewConversations
                    ? ($event->subject->conversation?->support_code ?? '#'.$event->subject->id)
                    : null,
            ];
        }

        return ['prefix' => __('account_audit.references.account'), 'value' => null];
    }

    /** @return array{prefix: string|null, value: string|null} */
    private function breakGlassReferenceParts(AuditEvent $event, bool $canViewConversations, bool $canManageTickets): array
    {
        $resourceType = data_get($event->metadata, 'resource_type');

        if (is_string($resourceType) && $resourceType !== '') {
            return $this->typedBreakGlassReferenceParts(
                $resourceType,
                data_get($event->metadata, 'resource_label'),
                data_get($event->metadata, 'resource_id'),
                $canViewConversations,
                $canManageTickets,
            );
        }

        $scopeType = data_get($event->metadata, 'scope_type');
        $scopeType = is_string($scopeType) && $scopeType !== ''
            ? $scopeType
            : $event->subject->scope_type;

        return $this->typedBreakGlassReferenceParts(
            $scopeType,
            data_get($event->metadata, 'scope_label') ?? $event->subject->scopeLabel(),
            null,
            $canViewConversations,
            $canManageTickets,
        );
    }

    /** @return array{prefix: string|null, value: string|null} */
    private function typedBreakGlassReferenceParts(
        string $type,
        mixed $label,
        mixed $fallbackId,
        bool $canViewConversations,
        bool $canManageTickets,
    ): array {
        if ($type === BreakGlassGrant::SCOPE_ACCOUNT) {
            return ['prefix' => __('account_audit.references.operator_access_account'), 'value' => null];
        }

        [$sourcePrefix, $translationSuffix] = match ($type) {
            BreakGlassGrant::SCOPE_CONVERSATION => ['Conversation', 'conversation'],
            BreakGlassGrant::SCOPE_SITE => ['Site', 'site'],
            'ticket' => ['Ticket', 'ticket'],
            default => [null, null],
        };

        if (! $this->canViewBreakGlassReference($type, $canViewConversations, $canManageTickets)) {
            return [
                'prefix' => $translationSuffix === null
                    ? __('account_audit.references.operator_access')
                    : __('account_audit.references.operator_access_'.$translationSuffix),
                'value' => null,
            ];
        }

        if ($sourcePrefix === null || $translationSuffix === null) {
            return [
                'prefix' => __('account_audit.references.operator_access'),
                'value' => is_string($label) && $label !== '' ? $label : null,
            ];
        }

        $value = is_string($label) ? $label : '';

        if (str_starts_with($value, $sourcePrefix.' ')) {
            $value = substr($value, strlen($sourcePrefix) + 1);
        }

        if ($value === '(deleted)' || $value === '(out of scope)') {
            $state = $value === '(deleted)' ? 'deleted' : 'out_of_scope';

            return [
                'prefix' => __('account_audit.references.operator_access_'.$translationSuffix.'_'.$state),
                'value' => null,
            ];
        }

        if ($value === '' && (is_int($fallbackId) || is_string($fallbackId))) {
            $value = '#'.$fallbackId;
        }

        return [
            'prefix' => __('account_audit.references.operator_access_'.$translationSuffix),
            'value' => $value !== '' ? $value : null,
        ];
    }

    private function auditActor(AuditEvent $event, bool $canViewVisitorIdentity): string
    {
        if ($event->actor instanceof User) {
            return $event->actor->name;
        }

        if ($event->actor instanceof Visitor) {
            return $canViewVisitorIdentity
                ? 'Visitor '.$this->visitorLabel($event->actor)
                : 'Visitor';
        }

        if ($event->actor instanceof ApiToken) {
            return 'Integration '.$event->actor->name.' ('.$event->actor->displayHint().')';
        }

        return 'System';
    }

    private function auditSubject(AuditEvent $event, bool $canViewConversations, bool $canManageTickets): string
    {
        if ($event->subject instanceof BreakGlassGrant) {
            // These are references rather than customer-authored content, but
            // a support code or ticket number still belongs to the underlying
            // support domain and follows that domain's permission boundary.
            $type = data_get($event->metadata, 'resource_type')
                ?? data_get($event->metadata, 'scope_type')
                ?? $event->subject->scope_type;
            $type = is_string($type) ? $type : '';

            if (! $this->canViewBreakGlassReference($type, $canViewConversations, $canManageTickets)) {
                return match ($type) {
                    BreakGlassGrant::SCOPE_CONVERSATION => 'Operator access: Conversation',
                    'ticket' => 'Operator access: Ticket',
                    default => 'Operator access',
                };
            }

            $label = data_get($event->metadata, 'resource_label')
                ?? data_get($event->metadata, 'scope_label')
                ?? $event->subject->scopeLabel();

            return 'Operator access: '.$label;
        }

        if ($event->subject instanceof User) {
            return $event->subject->name;
        }

        if ($event->subject instanceof CustomRole || $this->isDeletedCustomRoleSubject($event)) {
            return 'Custom role '.($event->subject instanceof CustomRole
                ? $event->subject->name
                : $this->customRoleName($event));
        }

        if ($event->subject instanceof Site) {
            return $event->subject->name;
        }

        if ($event->subject instanceof ApiToken) {
            // Name plus the last four, which is what the token list shows and
            // what an operator matches against their deployment config. A
            // reference by construction: enough to say WHICH credential, never
            // enough to use it, and safe in an export.
            return 'API token '.$event->subject->name.' ('.$event->subject->displayHint().')';
        }

        if ($event->subject instanceof OidcConnection) {
            return 'Single sign-on provider '.$event->subject->name;
        }

        if ($this->isOidcIdentitySubject($event)) {
            $providerName = $this->oidcProviderName($event);

            return 'Single sign-on identity'.($providerName !== null ? ' ('.$providerName.')' : '');
        }

        if ($event->subject instanceof Conversation) {
            // The support code, not the subject line: a subject is visitor-
            // authored text and this page is exported. The code is a reference
            // by construction, which is the same rule the break-glass labels
            // and the cobrowse rows already follow.
            return $canViewConversations
                ? 'Conversation '.$event->subject->support_code
                : 'Conversation';
        }

        if ($event->subject instanceof CobrowseSession) {
            $event->subject->loadMissing('conversation');

            return $canViewConversations
                ? 'Cobrowse '.($event->subject->conversation?->support_code ?? '#'.$event->subject->id)
                : 'Cobrowse';
        }

        return 'Account';
    }

    private function canViewBreakGlassReference(string $type, bool $canViewConversations, bool $canManageTickets): bool
    {
        return match ($type) {
            BreakGlassGrant::SCOPE_ACCOUNT, BreakGlassGrant::SCOPE_SITE => true,
            BreakGlassGrant::SCOPE_CONVERSATION => $canViewConversations,
            'ticket' => $canManageTickets,
            default => $canViewConversations && $canManageTickets,
        };
    }

    private function isDeletedCustomRoleSubject(AuditEvent $event): bool
    {
        return $event->subject === null
            && $event->subject_type === (new CustomRole)->getMorphClass();
    }

    private function customRoleName(AuditEvent $event): string
    {
        $name = data_get($event->metadata, 'role_name')
            ?? data_get($event->metadata, 'new_role_name')
            ?? data_get($event->metadata, 'old_role_name');

        return is_string($name) && $name !== '' ? $name : '#'.$event->subject_id;
    }

    private function isOidcIdentitySubject(AuditEvent $event): bool
    {
        return $event->subject instanceof OidcIdentity
            || $event->subject_type === (new OidcIdentity)->getMorphClass();
    }

    private function oidcProviderName(AuditEvent $event): ?string
    {
        if ($event->subject instanceof OidcIdentity) {
            $event->subject->loadMissing('connection');
        }

        $name = $event->subject instanceof OidcIdentity
            ? $event->subject->connection?->name
            : null;
        $name ??= data_get($event->metadata, 'oidc_provider_name');

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    private function visitorLabel(Visitor $visitor): string
    {
        return collect([
            $visitor->name,
            $visitor->email,
            $visitor->external_id,
            $visitor->anonymous_id,
        ])
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->first() ?? '#'.$visitor->id;
    }
}
