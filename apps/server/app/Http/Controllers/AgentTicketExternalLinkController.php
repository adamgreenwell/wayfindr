<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use App\Models\User;
use App\Support\ExternalIssueProvider;
use App\Support\ExternalIssueSyncStatus;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AgentTicketExternalLinkController extends Controller
{
    public function __construct(private readonly SiteManagerCoverage $siteManagerCoverage) {}

    public function store(Request $request, Ticket $ticket): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketUpdate($agent, $ticket);

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(ExternalIssueProvider::values())],
            'project_key' => ['required', 'string', 'max:255'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'external_key' => ['nullable', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:2048'],
            'sync_status' => ['nullable', 'string', Rule::in(ExternalIssueSyncStatus::values())],
        ]);

        DB::transaction(function () use ($agent, $ticket, $validated): void {
            [$agent, $ticket] = $this->lockedTicketActor($agent, $ticket);
            $externalLink = $ticket->externalLinks()->create([
                'account_id' => $ticket->account_id,
                'site_id' => $ticket->site_id,
                'provider' => $validated['provider'],
                'project_key' => $validated['project_key'],
                'external_id' => $validated['external_id'] ?? null,
                'external_key' => $validated['external_key'] ?? null,
                'url' => $validated['url'],
                'sync_status' => $validated['sync_status'] ?? 'linked',
                'metadata' => [],
            ]);

            $this->recordActivity($ticket, $agent, 'ticket.external_link_created', [
                'external_link_id' => $externalLink->id,
                'provider' => $externalLink->provider,
                'project_key' => $externalLink->project_key,
                'external_id' => $externalLink->external_id,
                'external_key' => $externalLink->external_key,
                'url' => $externalLink->url,
                'sync_status' => $externalLink->sync_status,
            ]);
        });

        return redirect()
            ->back(302, [], route('dashboard'))
            ->with('status', 'ticket_detail.flash.external_link_added');
    }

    public function destroy(Request $request, Ticket $ticket, TicketExternalLink $externalLink): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketUpdate($agent, $ticket);
        DB::transaction(function () use ($agent, $externalLink, $ticket): void {
            [$agent, $ticket] = $this->lockedTicketActor($agent, $ticket);
            $externalLink = TicketExternalLink::query()
                ->whereKey($externalLink->id)
                ->where('ticket_id', $ticket->id)
                ->where('account_id', $ticket->account_id)
                ->where('site_id', $ticket->site_id)
                ->lockForUpdate()
                ->firstOrFail();
            $metadata = [
                'external_link_id' => $externalLink->id,
                'provider' => $externalLink->provider,
                'project_key' => $externalLink->project_key,
                'external_id' => $externalLink->external_id,
                'external_key' => $externalLink->external_key,
                'url' => $externalLink->url,
                'sync_status' => $externalLink->sync_status,
            ];

            $externalLink->delete();

            $this->recordActivity($ticket, $agent, 'ticket.external_link_removed', $metadata);
        });

        return redirect()
            ->back(302, [], route('dashboard'))
            ->with('status', 'ticket_detail.flash.external_link_removed');
    }

    private function authorizeTicketUpdate(User $agent, Ticket $ticket): void
    {
        abort_unless(Gate::forUser($agent)->allows('update', $ticket), 404);
    }

    /** @return array{0: User, 1: Ticket} */
    private function lockedTicketActor(User $agent, Ticket $ticket): array
    {
        $accountId = (int) $ticket->account_id;
        $this->siteManagerCoverage->lockAccount($accountId);
        $agent = User::query()
            ->whereKey($agent->id)
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->firstOrFail();
        $site = Site::query()
            ->whereKey($ticket->site_id)
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->firstOrFail();
        $ticket = Ticket::query()
            ->whereKey($ticket->id)
            ->where('account_id', $accountId)
            ->where('site_id', $site->id)
            ->lockForUpdate()
            ->firstOrFail();
        $ticket->setRelation('site', $site);

        $this->authorizeTicketUpdate($agent, $ticket);

        return [$agent, $ticket];
    }

    private function recordActivity(Ticket $ticket, User $agent, string $action, array $metadata = []): void
    {
        $ticket->auditEvents()->create([
            'account_id' => $ticket->account_id,
            'site_id' => $ticket->site_id,
            'actor_type' => User::class,
            'actor_id' => $agent->id,
            'action' => $action,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
