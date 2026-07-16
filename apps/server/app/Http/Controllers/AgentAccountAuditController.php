<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BreakGlassGrant;
use App\Models\CobrowseSession;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        $auditEvents = $this->auditItems($baseQuery, $auditAction, $auditSearch, $auditSiteId, 50);

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
        $auditEvents = $this->auditItems($baseQuery, $auditAction, $auditSearch, $auditSiteId, 500);

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
        return $account->sites()
            ->visibleToAgent($agent)
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
     * @return array<string, string>
     */
    private function availableActions(Builder $baseQuery): array
    {
        return (clone $baseQuery)
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->filter(fn ($action): bool => is_string($action) && $action !== '')
            ->mapWithKeys(fn (string $action): array => [$action => $this->auditLabel($action)])
            ->all();
    }

    /**
     * @param  array<string, string>  $availableActions
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
     * @return Collection<int, array{occurred_at: string, action: string, label: string, actor: string, subject: string, site: string}>
     */
    private function auditItems(Builder $baseQuery, string $auditAction, string $auditSearch, ?int $auditSiteId, int $limit): Collection
    {
        return $this->applyAuditFilters(clone $baseQuery, $auditAction, $auditSearch, $auditSiteId)
            ->latest('occurred_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditEvent $event): array => [
                'occurred_at' => $event->occurred_at?->toDateTimeString() ?? '',
                'action' => $event->action,
                'label' => $this->auditLabel($event->action),
                'actor' => $this->auditActor($event),
                'subject' => $this->auditSubject($event),
                'site' => $event->site?->name ?? 'Account',
            ]);
    }

    /**
     * @param  array{occurred_at: string, action: string, label: string, actor: string, subject: string, site: string}  $event
     * @return array<int, string>
     */
    private function auditCsvRow(array $event): array
    {
        return [
            $this->spreadsheetSafeCsvValue($event['occurred_at']),
            $this->spreadsheetSafeCsvValue($event['action']),
            $this->spreadsheetSafeCsvValue($event['label']),
            $this->spreadsheetSafeCsvValue($event['actor']),
            $this->spreadsheetSafeCsvValue($event['subject']),
            $this->spreadsheetSafeCsvValue($event['site']),
        ];
    }

    private function spreadsheetSafeCsvValue(string $value): string
    {
        if (preg_match('/^\s*[=+\-@]/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
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
            default => str($action)->replace(['.', '_'], ' ')->headline()->toString(),
        };
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

            return 'Break-glass: '.$label;
        }

        if ($event->subject instanceof User) {
            return $event->subject->name;
        }

        if ($event->subject instanceof Site) {
            return $event->subject->name;
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
