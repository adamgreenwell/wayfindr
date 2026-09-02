<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\BreakGlassGrant;
use App\Models\CobrowseSession;
use App\Models\Conversation;
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
        $baseQuery = $this->baseAuditQuery($account, $visibleSiteIds);
        $availableActions = $this->availableActions($baseQuery);
        [$auditAction, $auditSearch, $auditSiteId] = $this->filters($request, $availableActions, $visibleSiteIds);
        $auditQuery = $this->auditQueryParams($auditAction, $auditSearch, $auditSiteId);
        $auditEvents = $this->auditEvents($baseQuery, $auditAction, $auditSearch, $auditSiteId, 50)
            ->map(fn (AuditEvent $event): array => $this->auditItem($event));

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
        $baseQuery = $this->baseAuditQuery($account, $visibleSiteIds);
        [$auditAction, $auditSearch, $auditSiteId] = $this->filters($request, $this->availableActions($baseQuery), $visibleSiteIds);
        $auditEvents = $this->auditEvents($baseQuery, $auditAction, $auditSearch, $auditSiteId, 500);

        return response()->streamDownload(function () use ($auditEvents): void {
            $stream = fopen('php://output', 'w');

            if ($stream === false) {
                return;
            }

            fputcsv($stream, ['occurred_at', 'action', 'label', 'actor', 'subject', 'site']);

            foreach ($auditEvents as $event) {
                fputcsv($stream, $this->auditCsvRow($event));
            }

            fclose($stream);
        }, 'wayfindr-account-audit-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function accountAdmin(Request $request): User
    {
        $agent = $request->user();

        abort_unless($agent?->account_id && $agent->isAdmin(), 403);

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
    private function auditEvents(Builder $baseQuery, string $auditAction, string $auditSearch, ?int $auditSiteId, int $limit): Collection
    {
        return $this->applyAuditFilters(clone $baseQuery, $auditAction, $auditSearch, $auditSiteId)
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
    private function auditItem(AuditEvent $event): array
    {
        return [
            'occurred_at' => $event->occurred_at === null ? '' : ReaderClock::dateTime($event->occurred_at),
            'action' => $event->action,
            'label' => $this->translatedAuditLabel($event->action),
            'actor' => $this->auditActorParts($event),
            'subject' => $this->auditSubjectParts($event),
            'site' => $event->site
                ? ['prefix' => null, 'value' => $event->site->name]
                : ['prefix' => __('account_audit.references.account'), 'value' => null],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function auditCsvRow(AuditEvent $event): array
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
            $this->auditActor($event),
            $this->auditSubject($event),
            $event->site?->name ?? 'Account',
        ]);
    }

    /**
     * @param  Builder<AuditEvent>  $query
     * @return Builder<AuditEvent>
     */
    private function applyAuditFilters(Builder $query, string $auditAction, string $auditSearch, ?int $auditSiteId): Builder
    {
        return $query
            ->when($auditAction !== '', fn (Builder $query) => $query->where('action', $auditAction))
            ->when($auditSiteId !== null, fn (Builder $query) => $query->where('site_id', $auditSiteId))
            ->when($auditSearch !== '', function (Builder $query) use ($auditSearch): void {
                $searchPattern = '%'.$auditSearch.'%';

                $query->where(function (Builder $query) use ($searchPattern): void {
                    $query
                        ->whereLike('action', $searchPattern)
                        ->orWhereHas('site', fn (Builder $query) => $query
                            ->whereLike('name', $searchPattern)
                            ->orWhereLike('domain', $searchPattern))
                        ->orWhereHasMorph('actor', [User::class], fn (Builder $query) => $query
                            ->whereLike('name', $searchPattern)
                            ->orWhereLike('email', $searchPattern))
                        ->orWhereHasMorph('actor', [Visitor::class], fn (Builder $query) => $query
                            ->whereLike('name', $searchPattern)
                            ->orWhereLike('email', $searchPattern)
                            ->orWhereLike('external_id', $searchPattern)
                            ->orWhereLike('anonymous_id', $searchPattern))
                        ->orWhereHasMorph('subject', [User::class], fn (Builder $query) => $query
                            ->whereLike('name', $searchPattern)
                            ->orWhereLike('email', $searchPattern))
                        ->orWhereHasMorph('subject', [Site::class], fn (Builder $query) => $query
                            ->whereLike('name', $searchPattern)
                            ->orWhereLike('domain', $searchPattern))
                        ->orWhereHasMorph('subject', [Conversation::class], fn (Builder $query) => $query
                            ->whereLike('support_code', $searchPattern))
                        // The token's name and last four are what the subject
                        // column shows, so the search has to reach them --
                        // otherwise an account with several credentials can see
                        // which one an event concerns but cannot filter to it.
                        ->orWhereHasMorph('subject', [ApiToken::class], fn (Builder $query) => $query
                            ->whereLike('name', $searchPattern)
                            ->orWhereLike('last_four', $searchPattern))
                        ->orWhereHasMorph('subject', [CobrowseSession::class], fn (Builder $query) => $query
                            ->whereHas('conversation', fn (Builder $query) => $query->whereLike('support_code', $searchPattern)))
                        // Break-glass subjects surface their reference-safe
                        // labels (support code, site name, "Ticket #n") from
                        // event metadata — the search must reach what the
                        // subject column shows.
                        ->orWhereLike('metadata->resource_label', $searchPattern)
                        ->orWhereLike('metadata->scope_label', $searchPattern);
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
            'site_access.updated' => 'Site access updated',
            'api_token.created' => 'API token issued',
            'api_token.revoked' => 'API token revoked',
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
    private function auditActorParts(AuditEvent $event): array
    {
        if ($event->actor instanceof User) {
            return ['prefix' => null, 'value' => $event->actor->name];
        }

        if ($event->actor instanceof Visitor) {
            return [
                'prefix' => __('account_audit.references.visitor'),
                'value' => $this->visitorLabel($event->actor),
            ];
        }

        return ['prefix' => __('account_audit.references.system'), 'value' => null];
    }

    /** @return array{prefix: string|null, value: string|null} */
    private function auditSubjectParts(AuditEvent $event): array
    {
        if ($event->subject instanceof BreakGlassGrant) {
            return $this->breakGlassReferenceParts($event);
        }

        if ($event->subject instanceof User || $event->subject instanceof Site) {
            return ['prefix' => null, 'value' => $event->subject->name];
        }

        if ($event->subject instanceof ApiToken) {
            return [
                'prefix' => __('account_audit.references.api_token'),
                'value' => $event->subject->name.' ('.$event->subject->displayHint().')',
            ];
        }

        if ($event->subject instanceof Conversation) {
            return [
                'prefix' => __('account_audit.references.conversation'),
                'value' => $event->subject->support_code,
            ];
        }

        if ($event->subject instanceof CobrowseSession) {
            $event->subject->loadMissing('conversation');

            return [
                'prefix' => __('account_audit.references.cobrowse'),
                'value' => $event->subject->conversation?->support_code ?? '#'.$event->subject->id,
            ];
        }

        return ['prefix' => __('account_audit.references.account'), 'value' => null];
    }

    /** @return array{prefix: string|null, value: string|null} */
    private function breakGlassReferenceParts(AuditEvent $event): array
    {
        $resourceType = data_get($event->metadata, 'resource_type');

        if (is_string($resourceType) && $resourceType !== '') {
            return $this->typedBreakGlassReferenceParts(
                $resourceType,
                data_get($event->metadata, 'resource_label'),
                data_get($event->metadata, 'resource_id'),
            );
        }

        $scopeType = data_get($event->metadata, 'scope_type');
        $scopeType = is_string($scopeType) && $scopeType !== ''
            ? $scopeType
            : $event->subject->scope_type;

        return $this->typedBreakGlassReferenceParts(
            $scopeType,
            data_get($event->metadata, 'scope_label') ?? $event->subject->scopeLabel(),
        );
    }

    /** @return array{prefix: string|null, value: string|null} */
    private function typedBreakGlassReferenceParts(string $type, mixed $label, mixed $fallbackId = null): array
    {
        if ($type === BreakGlassGrant::SCOPE_ACCOUNT) {
            return ['prefix' => __('account_audit.references.operator_access_account'), 'value' => null];
        }

        [$sourcePrefix, $translationSuffix] = match ($type) {
            BreakGlassGrant::SCOPE_CONVERSATION => ['Conversation', 'conversation'],
            BreakGlassGrant::SCOPE_SITE => ['Site', 'site'],
            'ticket' => ['Ticket', 'ticket'],
            default => [null, null],
        };

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

    private function auditActor(AuditEvent $event): string
    {
        if ($event->actor instanceof User) {
            return $event->actor->name;
        }

        if ($event->actor instanceof Visitor) {
            return 'Visitor '.$this->visitorLabel($event->actor);
        }

        return 'System';
    }

    private function auditSubject(AuditEvent $event): string
    {
        if ($event->subject instanceof BreakGlassGrant) {
            // The break-glass label fields are references by construction
            // (support code, site name, "Ticket #n" — never customer
            // content), so surfacing them here keeps the export boundary
            // while telling the account exactly what an operator reached.
            $label = data_get($event->metadata, 'resource_label')
                ?? data_get($event->metadata, 'scope_label')
                ?? $event->subject->scopeLabel();

            return 'Operator access: '.$label;
        }

        if ($event->subject instanceof User) {
            return $event->subject->name;
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

        if ($event->subject instanceof Conversation) {
            // The support code, not the subject line: a subject is visitor-
            // authored text and this page is exported. The code is a reference
            // by construction, which is the same rule the break-glass labels
            // and the cobrowse rows already follow.
            return 'Conversation '.$event->subject->support_code;
        }

        if ($event->subject instanceof CobrowseSession) {
            $event->subject->loadMissing('conversation');

            return 'Cobrowse '.($event->subject->conversation?->support_code ?? '#'.$event->subject->id);
        }

        return 'Account';
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
