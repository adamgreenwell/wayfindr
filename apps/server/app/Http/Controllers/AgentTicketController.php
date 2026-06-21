<?php

namespace App\Http\Controllers;

use App\Events\ConversationMessageCreated;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\SiteExternalIssueProject;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use App\Models\TicketLabel;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use App\Notifications\TicketAssigned;
use App\Support\AgentNoteTemplate;
use App\Support\ExternalIssueProvider;
use App\Support\ExternalIssueSyncStatus;
use App\Support\ReplyTemplateOptions;
use App\Support\TicketCategory;
use App\Support\TicketPriority;
use App\Support\VisitorContextSanitizer;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AgentTicketController extends Controller
{
    public function show(Request $request, Ticket $ticket, VisitorContextSanitizer $visitorContextSanitizer, ReplyTemplateOptions $replyTemplateOptions): View
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'view', $ticket);
        $this->markTicketAssignmentNotificationsRead($agent, $ticket);
        $ticket->loadMissing('site');
        $ticket->load([
            'assignee',
            'conversation.latestMessage',
            'externalLinks' => fn ($query) => $query
                ->latest()
                ->latest('id'),
            'labels',
            'latestEscalationEvent.actor',
            'requester',
            'site.externalIssueProjects.providerConnection',
            'auditEvents' => fn ($query) => $query
                ->where('action', 'ticket.note_added')
                ->with('actor')
                ->latest('occurred_at')
                ->latest('id'),
        ]);

        $ticketReturnQuery = $this->ticketQueueReturnQuery($request);
        $ticketTimeline = $this->ticketTimeline($ticket);

        return view('agent.tickets.show', [
            'account' => $agent->account()->firstOrFail(),
            'accountAgents' => $this->supportAgentsForSite($ticket->site),
            'agent' => $agent,
            'externalIssueProviders' => ExternalIssueProvider::options(),
            'externalIssueSyncStatuses' => ExternalIssueSyncStatus::options(),
            'githubIssueProjects' => $this->githubIssueProjectsForTicket($ticket),
            'gitlabIssueProjects' => $this->gitlabIssueProjectsForTicket($ticket),
            'latestTicketEscalation' => $ticket->latestRecentEscalationEvent(),
            'noteTemplates' => AgentNoteTemplate::options(),
            'replyTemplates' => $replyTemplateOptions->forAgent($agent),
            'ticketReturnLink' => $this->ticketReturnLink($ticketReturnQuery),
            'ticketReturnQuery' => $ticketReturnQuery,
            'ticketLabelOptions' => $agent->account->ticketLabels()
                ->orderBy('name')
                ->get(),
            'ticketActivity' => $ticket->auditEvents()
                ->with('actor')
                ->whereIn('action', $this->visibleActivityActions())
                ->latest('occurred_at')
                ->latest('id')
                ->get(),
            'ticketCategories' => TicketCategory::options(),
            'ticketCategoryGuidance' => TicketCategory::options(),
            'ticketPriorities' => TicketPriority::options(),
            'ticketPriorityGuidance' => TicketPriority::guidanceOptions(),
            'ticket' => $ticket,
            'ticketArtifactCoverage' => $this->ticketArtifactCoverage($ticket),
            'ticketExternalIssueHealth' => $this->ticketExternalIssueHealth($ticket),
            'visitorContext' => $this->visitorContext($ticket, $visitorContextSanitizer),
            'priorVisitorConversations' => $this->priorVisitorConversations($ticket),
            'priorVisitorTickets' => $this->priorVisitorTickets($ticket),
            'linkedConversationMessages' => $this->linkedConversationMessages($ticket),
            'ticketTimeline' => $ticketTimeline,
            'ticketTimelineSummary' => $this->ticketTimelineSummary($ticketTimeline),
        ]);
    }

    public function storeNote(Request $request, Ticket $ticket): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'addNote', $ticket);

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'note_template' => ['nullable', Rule::in(AgentNoteTemplate::values())],
        ]);

        $selectedTemplate = $validated['note_template'] ?? null;
        $body = trim((string) ($validated['body'] ?? ''));

        if ($body === '' && $selectedTemplate) {
            $body = trim((string) AgentNoteTemplate::body($selectedTemplate));
        }

        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => 'Please enter an internal note.',
            ]);
        }

        $metadata = [
            'body' => $body,
        ];

        if ($selectedTemplate) {
            $metadata['note_template'] = $selectedTemplate;
        }

        $this->recordActivity($ticket, $agent, 'ticket.note_added', $metadata);

        return $this->redirectAfterUpdate($ticket, $request, 'Ticket note added.');
    }

    public function storeLabel(Request $request, Ticket $ticket): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'update', $ticket);

        $validated = $request->validate([
            'label_name' => ['required', 'string', 'max:64'],
        ]);

        $name = TicketLabel::normalizeName($validated['label_name']);
        $slug = TicketLabel::slugForName($name);

        if ($name === '' || $slug === '') {
            throw ValidationException::withMessages([
                'label_name' => 'Use at least one letter or number for the label.',
            ]);
        }

        if (TicketLabel::isReservedSlug($slug)) {
            throw ValidationException::withMessages([
                'label_name' => 'That label name is reserved for ticket filtering.',
            ]);
        }

        $label = TicketLabel::firstOrCreate([
            'account_id' => $ticket->account_id,
            'slug' => $slug,
        ], [
            'name' => $name,
        ]);

        $ticket->labels()->syncWithoutDetaching([$label->id]);

        $this->recordActivity($ticket, $agent, 'ticket.label_added', [
            'label_id' => $label->id,
            'label_name' => $label->name,
            'label_slug' => $label->slug,
        ]);

        return $this->redirectAfterUpdate($ticket, $request, 'Ticket label added.');
    }

    public function destroyLabel(Request $request, Ticket $ticket, TicketLabel $ticketLabel): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'update', $ticket);

        abort_unless(
            (int) $ticketLabel->account_id === (int) $ticket->account_id
            && $ticket->labels()->whereKey($ticketLabel->id)->exists(),
            404,
        );

        $ticket->labels()->detach($ticketLabel->id);

        $this->recordActivity($ticket, $agent, 'ticket.label_removed', [
            'label_id' => $ticketLabel->id,
            'label_name' => $ticketLabel->name,
            'label_slug' => $ticketLabel->slug,
        ]);

        return $this->redirectAfterUpdate($ticket, $request, 'Ticket label removed.');
    }

    public function storeReply(Request $request, Ticket $ticket, ReplyTemplateOptions $replyTemplateOptions): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'reply', $ticket);
        $ticket->loadMissing('conversation');

        abort_unless($ticket->conversation, 404);

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:4000'],
            'reply_template' => ['nullable', 'string', 'max:120'],
        ]);

        $selectedTemplate = $validated['reply_template'] ?? null;
        $resolvedTemplate = null;
        $body = trim((string) ($validated['message'] ?? ''));

        if ($selectedTemplate) {
            $resolvedTemplate = $replyTemplateOptions->resolve($agent, $selectedTemplate);

            if (! $resolvedTemplate) {
                throw ValidationException::withMessages([
                    'reply_template' => 'Choose an available reply helper.',
                ]);
            }
        }

        if ($body === '' && $resolvedTemplate) {
            $body = trim($resolvedTemplate['body']);
        }

        if ($body === '') {
            throw ValidationException::withMessages([
                'message' => 'Please enter a reply.',
            ]);
        }

        $conversation = $ticket->conversation;
        $metadata = [
            'source' => 'ticket',
            'ticket_id' => $ticket->id,
        ];

        if ($resolvedTemplate) {
            $metadata = [
                ...$metadata,
                ...$this->replyTemplateMetadata($resolvedTemplate),
            ];
        }

        $message = $conversation->messages()->create([
            'sender_type' => User::class,
            'sender_id' => $agent->id,
            'type' => 'text',
            'body' => $body,
            'metadata' => $metadata,
        ]);

        $conversation->forceFill([
            'assigned_agent_id' => $conversation->assigned_agent_id ?: $agent->id,
            'status' => 'open',
            'closed_at' => null,
            'last_message_at' => $message->created_at,
        ])->save();

        $activityMetadata = [
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
        ];

        if ($resolvedTemplate) {
            $activityMetadata = [
                ...$activityMetadata,
                ...$this->replyTemplateMetadata($resolvedTemplate),
            ];
        }

        $this->recordActivity($ticket, $agent, 'ticket.reply_sent', $activityMetadata);
        $this->markConversationNotificationsRead($agent, $conversation);
        $conversation->markReadFor($agent);

        event(new ConversationMessageCreated($message));

        return $this->redirectAfterUpdate($ticket, $request, 'Reply sent.');
    }

    /**
     * @param  array{key: string, label: string, body: string, managed_id?: int}  $resolvedTemplate
     * @return array<string, mixed>
     */
    private function replyTemplateMetadata(array $resolvedTemplate): array
    {
        if (array_key_exists('managed_id', $resolvedTemplate)) {
            return [
                'reply_template_id' => $resolvedTemplate['managed_id'],
                'reply_template_name' => $resolvedTemplate['label'],
            ];
        }

        return [
            'reply_template' => $resolvedTemplate['key'],
        ];
    }

    public function update(Request $request, Ticket $ticket): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'update', $ticket);

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'category' => ['nullable', Rule::in(TicketCategory::values())],
            'priority' => ['required', Rule::in(TicketPriority::values())],
        ]);

        $changes = $this->ticketFieldChanges($ticket, $validated);

        $ticket->forceFill($validated)->save();

        if ($changes !== []) {
            $this->recordActivity($ticket, $agent, 'ticket.updated', [
                'changes' => $changes,
            ]);
        }

        return $this->redirectAfterUpdate($ticket, $request, 'Ticket updated.');
    }

    public function pending(Request $request, Ticket $ticket): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'updateStatus', $ticket);

        $validated = $request->validate([
            'pending_note' => ['nullable', 'string', 'max:4000'],
        ]);

        $pendingNote = trim((string) ($validated['pending_note'] ?? ''));

        $ticket->forceFill([
            'status' => 'pending',
            'closed_at' => null,
        ])->save();

        $this->recordActivity(
            $ticket,
            $agent,
            'ticket.pending',
            $pendingNote === '' ? [] : ['pending_note' => $pendingNote],
        );

        return $this->redirectAfterUpdate($ticket, $request, 'Ticket marked pending.');
    }

    public function close(Request $request, Ticket $ticket): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'updateStatus', $ticket);

        $validated = $request->validate([
            'resolution_note' => ['nullable', 'string', 'max:4000'],
        ]);

        $resolutionNote = trim((string) ($validated['resolution_note'] ?? ''));

        $ticket->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
        ])->save();

        $this->recordActivity(
            $ticket,
            $agent,
            'ticket.closed',
            $resolutionNote === '' ? [] : ['resolution_note' => $resolutionNote],
        );

        return $this->redirectAfterUpdate($ticket, $request, 'Ticket closed.');
    }

    public function reopen(Request $request, Ticket $ticket): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'updateStatus', $ticket);

        $validated = $request->validate([
            'reopen_note' => ['nullable', 'string', 'max:4000'],
        ]);

        $reopenNote = trim((string) ($validated['reopen_note'] ?? ''));

        $ticket->forceFill([
            'status' => 'open',
            'closed_at' => null,
        ])->save();

        $this->recordActivity(
            $ticket,
            $agent,
            'ticket.reopened',
            $reopenNote === '' ? [] : ['reopen_note' => $reopenNote],
        );

        return $this->redirectAfterUpdate($ticket, $request, 'Ticket reopened.');
    }

    public function updateAssignee(Request $request, Ticket $ticket): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'assign', $ticket);

        $validated = $request->validate([
            'assignee_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('account_id', $agent->account_id),
            ],
        ]);

        $ticket->loadMissing(['assignee', 'site']);
        $oldAssigneeId = $ticket->assignee_id;
        $oldAssigneeName = $ticket->assignee?->name;
        $newAssigneeId = $validated['assignee_id'] ?? null;
        $newAssignee = $newAssigneeId
            ? $agent->account->agents()->whereKey($newAssigneeId)->first()
            : null;

        if ($newAssignee && ! $ticket->site->supportsAgent($newAssignee)) {
            throw ValidationException::withMessages([
                'assignee_id' => 'Choose an agent assigned to this site.',
            ]);
        }

        $newAssigneeName = $newAssignee?->name;

        $ticket->forceFill([
            'assignee_id' => $newAssigneeId,
        ])->save();

        $this->recordActivity($ticket, $agent, 'ticket.assignee_updated', [
            'old_assignee_name' => $oldAssigneeName,
            'new_assignee_name' => $newAssigneeName,
        ]);

        $freshTicket = $ticket->fresh() ?? $ticket;

        if (
            $newAssignee
            && $newAssignee->isNot($agent)
            && $newAssignee->id !== $oldAssigneeId
            && $newAssignee->shouldReceiveTicketAssignmentAlert($freshTicket)
        ) {
            $newAssignee->notify(new TicketAssigned($freshTicket, $agent));
        }

        return $this->redirectAfterUpdate($ticket, $request, 'Ticket assignee updated.');
    }

    public function storeEscalation(Request $request, Ticket $ticket): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'assign', $ticket);

        $validated = $request->validate([
            'target_agent_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('account_id', $agent->account_id),
            ],
            'reason' => ['nullable', 'string', 'max:4000'],
        ]);

        $ticket->loadMissing(['assignee', 'site']);
        $oldAssigneeId = $ticket->assignee_id;
        $oldAssigneeName = $ticket->assignee?->name;
        $targetAgent = $agent->account->agents()
            ->whereKey($validated['target_agent_id'])
            ->first();

        if (! $targetAgent || ! $ticket->site->supportsAgent($targetAgent)) {
            throw ValidationException::withMessages([
                'target_agent_id' => 'Choose an agent assigned to this site.',
            ]);
        }

        if ($targetAgent->is($agent)) {
            throw ValidationException::withMessages([
                'target_agent_id' => 'Choose another agent to escalate this ticket to.',
            ]);
        }

        $ticket->forceFill([
            'assignee_id' => $targetAgent->id,
        ])->save();

        $reason = trim((string) ($validated['reason'] ?? ''));
        $metadata = [
            'old_assignee_id' => $oldAssigneeId,
            'old_assignee_name' => $oldAssigneeName,
            'new_assignee_id' => $targetAgent->id,
            'new_assignee_name' => $targetAgent->name,
            'target_agent_id' => $targetAgent->id,
            'target_agent_name' => $targetAgent->name,
            'target_had_site_access' => true,
        ];

        if ($reason !== '') {
            $metadata['reason'] = $reason;
        }

        $this->recordActivity($ticket, $agent, 'ticket.escalated', $metadata);

        $freshTicket = $ticket->fresh() ?? $ticket;

        if ($targetAgent->shouldReceiveTicketAssignmentAlert($freshTicket)) {
            $targetAgent->notify(new TicketAssigned($freshTicket, $agent));
        }

        return $this->redirectAfterUpdate($ticket, $request, 'Ticket escalated.');
    }

    private function authorizeTicketAbility(User $agent, string $ability, Ticket $ticket): void
    {
        abort_unless(Gate::forUser($agent)->allows($ability, $ticket), 404);
    }

    private function supportAgentsForSite(Site $site): Collection
    {
        $supportAgents = $site->eligibleSupportAgents()
            ->orderBy('name')
            ->get();

        return $supportAgents->isNotEmpty()
            ? $supportAgents
            : $site->account->agents()
                ->whereNull('deactivated_at')
                ->orderBy('name')
                ->get();
    }

    /**
     * @return Collection<int, array{label: string, value: string, description: string, tone: string}>
     */
    private function ticketArtifactCoverage(Ticket $ticket): Collection
    {
        $labelCount = $ticket->labels->count();
        $noteCount = $ticket->auditEvents->count();
        $externalLinkCount = $ticket->externalLinks
            ->filter(fn ($externalLink): bool => (int) $externalLink->account_id === (int) $ticket->account_id
                && (int) $externalLink->ticket_id === (int) $ticket->id)
            ->count();

        return collect([
            [
                'description' => $ticket->conversation
                    ? 'Support code and transcript are attached.'
                    : 'No chat transcript is attached yet.',
                'label' => 'Conversation',
                'tone' => $ticket->conversation ? 'ready' : 'manual',
                'value' => $ticket->conversation ? 'Linked' : 'Not linked',
            ],
            [
                'description' => $ticket->requester
                    ? 'Safe visitor context can support follow-up.'
                    : 'No visitor profile is attached yet.',
                'label' => 'Visitor',
                'tone' => $ticket->requester ? 'ready' : 'manual',
                'value' => $ticket->requester ? 'Linked' : 'Not linked',
            ],
            [
                'description' => $labelCount > 0
                    ? 'Queue filters and handoffs can use these labels.'
                    : 'Add labels when this needs queue-level grouping.',
                'label' => 'Labels',
                'tone' => $labelCount > 0 ? 'ready' : 'manual',
                'value' => $labelCount.' '.Str::plural('label', $labelCount),
            ],
            [
                'description' => $noteCount > 0
                    ? 'Private agent context exists for handoff.'
                    : 'No private handoff context has been captured.',
                'label' => 'Internal notes',
                'tone' => $noteCount > 0 ? 'ready' : 'manual',
                'value' => $noteCount.' '.Str::plural('note', $noteCount),
            ],
            [
                'description' => $externalLinkCount > 0
                    ? 'External tracker references are attached.'
                    : 'Link an external issue when work leaves Wayfindr.',
                'label' => 'External issues',
                'tone' => $externalLinkCount > 0 ? 'ready' : 'manual',
                'value' => $externalLinkCount > 0
                    ? $externalLinkCount.' '.Str::plural('link', $externalLinkCount)
                    : 'Not linked',
            ],
        ]);
    }

    private function redirectAfterUpdate(Ticket $ticket, Request $request, string $status): RedirectResponse
    {
        $ticketReturnQuery = $this->ticketQueueReturnQuery($request);

        if ($ticketReturnQuery !== []) {
            return redirect()
                ->route('dashboard.tickets.show', ['ticket' => $ticket] + $ticketReturnQuery)
                ->with('status', $status);
        }

        return redirect()
            ->back(302, [], route('dashboard.tickets.show', $ticket))
            ->with('status', $status);
    }

    /**
     * @param  array<string, int|string>  $query
     * @return array{label: string, href: string}
     */
    private function ticketReturnLink(array $query): array
    {
        if ($query === []) {
            return [
                'label' => 'Back to dashboard',
                'href' => route('dashboard'),
            ];
        }

        return [
            'label' => 'Back to ticket queue',
            'href' => route('dashboard.tickets.index', $query),
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function ticketQueueReturnQuery(Request $request): array
    {
        $query = [];
        $ticketStatus = $request->input('ticket_status');

        if (is_string($ticketStatus) && in_array($ticketStatus, ['pending', 'closed', 'all'], true)) {
            $query['ticket_status'] = $ticketStatus;
        }

        $ticketFilter = $request->input('ticket_filter');

        if (is_string($ticketFilter) && in_array($ticketFilter, ['assigned_to_me', 'unassigned'], true)) {
            $query['ticket_filter'] = $ticketFilter;
        }

        $ticketSite = $request->input('ticket_site');

        if (is_int($ticketSite) && $ticketSite > 0) {
            $query['ticket_site'] = $ticketSite;
        } elseif (is_string($ticketSite) && ctype_digit($ticketSite) && (int) $ticketSite > 0) {
            $query['ticket_site'] = (int) $ticketSite;
        }

        $ticketPriority = $request->input('ticket_priority');

        if (is_string($ticketPriority) && in_array($ticketPriority, TicketPriority::values(), true)) {
            $query['ticket_priority'] = $ticketPriority;
        }

        $ticketCategory = $request->input('ticket_category');

        if (is_string($ticketCategory) && ($ticketCategory === 'uncategorized' || in_array($ticketCategory, TicketCategory::values(), true))) {
            $query['ticket_category'] = $ticketCategory;
        }

        $ticketLabel = $request->input('ticket_label');

        if (
            is_string($ticketLabel)
            && ! TicketLabel::isReservedSlug($ticketLabel)
            && preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $ticketLabel) === 1
        ) {
            $query['ticket_label'] = $ticketLabel;
        }

        $ticketAttention = $request->input('ticket_attention');

        if (is_string($ticketAttention) && in_array($ticketAttention, ['escalated', 'needs_reply', 'needs_owner', 'needs_agent', 'waiting_on_customer', 'resolved'], true)) {
            $query['ticket_attention'] = $ticketAttention;
        }

        $ticketSearch = $request->input('ticket_search');
        $ticketSearch = is_string($ticketSearch) ? mb_substr(trim($ticketSearch), 0, 120) : '';

        if ($ticketSearch !== '') {
            $query['ticket_search'] = $ticketSearch;
        }

        return $query;
    }

    private function markTicketAssignmentNotificationsRead(User $agent, Ticket $ticket): void
    {
        $agent->unreadNotifications()
            ->where('type', TicketAssigned::class)
            ->get()
            ->filter(fn ($notification): bool => (int) data_get($notification->data, 'ticket_id') === $ticket->id)
            ->each
            ->markAsRead();
    }

    private function markConversationNotificationsRead(User $agent, Conversation $conversation): void
    {
        $agent->unreadNotifications()
            ->where('type', ConversationNeedsReply::class)
            ->get()
            ->filter(fn ($notification): bool => (int) data_get($notification->data, 'conversation_id') === $conversation->id)
            ->each
            ->markAsRead();
    }

    private function linkedConversationMessages(Ticket $ticket): Collection
    {
        if (! $ticket->conversation) {
            return collect();
        }

        return $ticket->conversation->messages()
            ->with('sender')
            ->latest('created_at')
            ->latest('id')
            ->limit(5)
            ->get()
            ->reverse()
            ->values();
    }

    private function ticketTimeline(Ticket $ticket): Collection
    {
        $conversationMessages = $ticket->conversation
            ? $ticket->conversation->messages()->with('sender')->get()
            : collect();

        $messageItems = $conversationMessages->toBase()->map(function ($message): array {
            $isAgentMessage = $message->sender_type === User::class;

            return [
                'type' => $isAgentMessage ? 'agent-message' : 'visitor-message',
                'label' => $isAgentMessage ? 'Agent reply' : 'Visitor message',
                'actor' => $isAgentMessage ? ($message->sender?->name ?? 'Agent') : 'Visitor',
                'badge' => $isAgentMessage ? 'Customer-visible' : 'Customer message',
                'body' => $message->body,
                'occurred_at' => $message->created_at,
                'sequence' => $message->id,
            ];
        });

        $activityItems = $ticket->auditEvents()
            ->with('actor')
            ->whereIn('action', $this->timelineActivityActions())
            ->get()
            ->toBase()
            ->map(fn ($activity): array => [
                'type' => $activity->action === 'ticket.note_added' ? 'internal-note' : 'ticket-activity',
                'label' => $this->ticketActivityLabel($activity),
                'actor' => $this->ticketActivityActor($activity),
                'badge' => $activity->action === 'ticket.note_added' ? 'Internal' : 'Ticket activity',
                'body' => $this->ticketTimelineBody($activity),
                'occurred_at' => $activity->occurred_at,
                'sequence' => $activity->id,
            ]);

        return $messageItems
            ->merge($activityItems)
            ->sortBy(fn (array $item): string => ($item['occurred_at']?->format('U.u') ?? '0').'-'.str_pad((string) $item['sequence'], 10, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * @param  Collection<int, array{type: string, label: string, actor: string, badge: string, body: string|null, occurred_at: CarbonInterface|null, sequence: int}>  $ticketTimeline
     * @return Collection<int, array{label: string, value: string, description: string}>
     */
    private function ticketTimelineSummary(Collection $ticketTimeline): Collection
    {
        $counts = $ticketTimeline->countBy('type');
        $conversationCount = (int) $counts->get('agent-message', 0) + (int) $counts->get('visitor-message', 0);
        $internalNoteCount = (int) $counts->get('internal-note', 0);
        $ticketActivityCount = (int) $counts->get('ticket-activity', 0);

        return collect([
            [
                'label' => 'Conversation',
                'value' => $conversationCount.' '.Str::plural('item', $conversationCount),
                'description' => 'Visitor messages and customer-visible replies.',
            ],
            [
                'label' => 'Internal notes',
                'value' => $internalNoteCount.' '.Str::plural('note', $internalNoteCount),
                'description' => 'Private agent context for handoff.',
            ],
            [
                'label' => 'Ticket activity',
                'value' => $ticketActivityCount.' '.Str::plural('update', $ticketActivityCount),
                'description' => 'Status, assignment, label, and integration events.',
            ],
        ]);
    }

    private function githubIssueProjectsForTicket(Ticket $ticket): Collection
    {
        return $this->issueProjectsForTicket($ticket, 'github');
    }

    private function gitlabIssueProjectsForTicket(Ticket $ticket): Collection
    {
        return $this->issueProjectsForTicket($ticket, 'gitlab');
    }

    /**
     * @return array{
     *     label: string,
     *     tone: string,
     *     total: int,
     *     status_counts: Collection<int, array{key: string, label: string, count: int}>,
     *     latest_attempt: array{label: string, body: string, occurred_at: CarbonInterface|null},
     *     failures: Collection<int, array{provider: string, project_key: string, occurred_at: CarbonInterface|null, retry: array{label: string, route: string, site_external_issue_project_id: int}|null}>
     * }
     */
    private function ticketExternalIssueHealth(Ticket $ticket): array
    {
        $externalLinks = $ticket->externalLinks
            ->filter(fn ($externalLink): bool => (int) $externalLink->account_id === (int) $ticket->account_id
                && (int) $externalLink->ticket_id === (int) $ticket->id);

        $statusCounts = $externalLinks->countBy('sync_status');
        $statusItems = collect(ExternalIssueSyncStatus::options())
            ->map(fn (string $label, string $status): array => [
                'key' => $status,
                'label' => $label,
                'count' => (int) ($statusCounts[$status] ?? 0),
            ])
            ->values();

        $failedCount = (int) ($statusCounts['sync_failed'] ?? 0);
        $pendingCount = (int) ($statusCounts['sync_pending'] ?? 0);
        $successfulIssueCreations = $ticket->auditEvents()
            ->where('account_id', $ticket->account_id)
            ->where('action', 'ticket.external_issue_created')
            ->get();
        $failedEvents = $ticket->auditEvents()
            ->where('account_id', $ticket->account_id)
            ->where('action', 'ticket.external_sync_failed')
            ->latest('occurred_at')
            ->latest('id')
            ->get()
            ->reject(fn (AuditEvent $event): bool => $this->externalIssueFailureWasResolved($event, $successfulIssueCreations))
            ->values();
        $linkFailures = $externalLinks
            ->where('sync_status', 'sync_failed')
            ->values()
            ->map(fn ($externalLink): array => [
                'provider' => $externalLink->providerLabel(),
                'project_key' => $externalLink->project_key,
                'occurred_at' => $externalLink->last_synced_at ?? $externalLink->updated_at,
                'retry' => $this->externalIssueRetryAction(
                    $ticket,
                    $externalLink->provider,
                    data_get($externalLink->metadata, 'site_external_issue_project_id'),
                ),
            ])
            ->toBase();
        $eventFailures = $failedEvents
            ->map(fn ($event): array => $this->externalIssueFailureItem($ticket, $event))
            ->toBase();
        $failures = $linkFailures
            ->merge($eventFailures)
            ->sortByDesc('occurred_at')
            ->values()
            ->take(3);

        return [
            'label' => match (true) {
                $failedCount > 0 || $failedEvents->isNotEmpty() => 'Needs attention',
                $externalLinks->isEmpty() => 'No external links',
                $pendingCount > 0 => 'Sync pending',
                default => 'Healthy',
            },
            'tone' => match (true) {
                $failedCount > 0 || $failedEvents->isNotEmpty() => 'attention',
                $pendingCount > 0 || $externalLinks->isEmpty() => 'manual',
                default => 'ready',
            },
            'total' => $externalLinks->count(),
            'status_counts' => $statusItems,
            'latest_attempt' => $this->latestExternalIssueAttempt($ticket, $externalLinks),
            'failures' => $failures,
        ];
    }

    /**
     * @param  Collection<int, TicketExternalLink>  $externalLinks
     * @return array{label: string, body: string, occurred_at: CarbonInterface|null}
     */
    private function latestExternalIssueAttempt(Ticket $ticket, Collection $externalLinks): array
    {
        $linkAttempts = $externalLinks
            ->map(fn (TicketExternalLink $externalLink): array => $this->externalIssueLinkAttemptItem($externalLink))
            ->toBase();
        $eventAttempts = $ticket->auditEvents()
            ->where('account_id', $ticket->account_id)
            ->whereIn('action', [
                'ticket.external_issue_created',
                'ticket.external_link_removed',
                'ticket.external_sync_failed',
            ])
            ->get()
            ->map(fn (AuditEvent $event): array => $this->externalIssueEventAttemptItem($event))
            ->toBase();

        $attempt = $linkAttempts
            ->merge($eventAttempts)
            ->sortByDesc(fn (array $attempt): string => sprintf(
                '%020d.%020d',
                $attempt['occurred_at']?->getTimestamp() ?? 0,
                $attempt['sequence'],
            ))
            ->first();

        if (! $attempt) {
            return [
                'label' => 'No external attempt yet',
                'body' => 'Create or link an external issue when this ticket needs work in another tracker.',
                'occurred_at' => null,
            ];
        }

        return [
            'label' => $attempt['label'],
            'body' => $attempt['body'],
            'occurred_at' => $attempt['occurred_at'],
        ];
    }

    /**
     * @return array{label: string, body: string, occurred_at: CarbonInterface|null, sequence: int}
     */
    private function externalIssueLinkAttemptItem(TicketExternalLink $externalLink): array
    {
        $provider = $externalLink->providerLabel();
        $projectKey = $externalLink->project_key ?: 'Project not recorded';
        $externalReference = $externalLink->external_key ?: $externalLink->external_id;
        $occurredAt = $externalLink->last_synced_at ?? $externalLink->updated_at;

        return match ($externalLink->sync_status) {
            'sync_failed' => [
                'label' => "{$provider} sync failed",
                'body' => "{$projectKey} needs attention. Provider details withheld.",
                'occurred_at' => $occurredAt,
                'sequence' => (int) $externalLink->id,
            ],
            'sync_pending' => [
                'label' => "{$provider} sync pending",
                'body' => "{$projectKey} is waiting for provider confirmation.",
                'occurred_at' => $occurredAt,
                'sequence' => (int) $externalLink->id,
            ],
            default => [
                'label' => "{$provider} link active",
                'body' => $externalReference
                    ? "{$projectKey} is linked to {$externalReference}."
                    : "{$projectKey} is linked.",
                'occurred_at' => $occurredAt,
                'sequence' => (int) $externalLink->id,
            ],
        };
    }

    /**
     * @return array{label: string, body: string, occurred_at: CarbonInterface|null, sequence: int}
     */
    private function externalIssueEventAttemptItem(AuditEvent $event): array
    {
        $provider = data_get($event->metadata, 'provider');
        $providerLabel = ExternalIssueProvider::label(is_string($provider) ? $provider : null);
        $projectKey = $this->externalIssueFailureProjectKey($event);

        if ($event->action === 'ticket.external_link_removed') {
            $externalReference = data_get($event->metadata, 'external_key')
                ?: data_get($event->metadata, 'external_id');
            $externalReference = is_string($externalReference) && trim($externalReference) !== ''
                ? trim($externalReference)
                : null;

            return [
                'label' => "{$providerLabel} link removed",
                'body' => $externalReference
                    ? "{$projectKey} is no longer linked to {$externalReference}."
                    : "{$projectKey} external link was removed.",
                'occurred_at' => $event->occurred_at,
                'sequence' => (int) $event->id,
            ];
        }

        if ($event->action === 'ticket.external_issue_created') {
            $externalReference = data_get($event->metadata, 'external_key')
                ?: data_get($event->metadata, 'external_id');
            $externalReference = is_string($externalReference) && trim($externalReference) !== ''
                ? trim($externalReference)
                : null;

            return [
                'label' => "{$providerLabel} issue created",
                'body' => $externalReference
                    ? "{$projectKey} is linked to {$externalReference}."
                    : "{$projectKey} was created in the external tracker.",
                'occurred_at' => $event->occurred_at,
                'sequence' => (int) $event->id,
            ];
        }

        return [
            'label' => "{$providerLabel} sync failed",
            'body' => "{$projectKey} needs attention. Provider details withheld.",
            'occurred_at' => $event->occurred_at,
            'sequence' => (int) $event->id,
        ];
    }

    private function externalIssueFailureProjectKey(AuditEvent $event): string
    {
        $projectKey = data_get($event->metadata, 'project_key');

        return is_string($projectKey) && trim($projectKey) !== ''
            ? trim($projectKey)
            : 'Project not recorded';
    }

    /**
     * @param  Collection<int, AuditEvent>  $successfulIssueCreations
     */
    private function externalIssueFailureWasResolved(AuditEvent $failure, Collection $successfulIssueCreations): bool
    {
        $failedProjectId = data_get($failure->metadata, 'site_external_issue_project_id');
        $failedProvider = data_get($failure->metadata, 'provider');

        if (! is_numeric($failedProjectId) || ! is_string($failedProvider)) {
            return false;
        }

        return $successfulIssueCreations->contains(function (AuditEvent $success) use ($failure, $failedProjectId, $failedProvider): bool {
            return (int) data_get($success->metadata, 'site_external_issue_project_id') === (int) $failedProjectId
                && data_get($success->metadata, 'provider') === $failedProvider
                && $this->externalIssueEventIsAfter($success, $failure);
        });
    }

    private function externalIssueEventIsAfter(AuditEvent $candidate, AuditEvent $reference): bool
    {
        if (! $candidate->occurred_at || ! $reference->occurred_at) {
            return (int) $candidate->id > (int) $reference->id;
        }

        if ($candidate->occurred_at->greaterThan($reference->occurred_at)) {
            return true;
        }

        return $candidate->occurred_at->equalTo($reference->occurred_at)
            && (int) $candidate->id > (int) $reference->id;
    }

    /**
     * @return array{provider: string, project_key: string, occurred_at: CarbonInterface|null, retry: array{label: string, route: string, site_external_issue_project_id: int}|null}
     */
    private function externalIssueFailureItem(Ticket $ticket, AuditEvent $event): array
    {
        $provider = data_get($event->metadata, 'provider');

        return [
            'provider' => ExternalIssueProvider::label(is_string($provider) ? $provider : null),
            'project_key' => $this->externalIssueFailureProjectKey($event),
            'occurred_at' => $event->occurred_at,
            'retry' => $this->externalIssueRetryAction(
                $ticket,
                is_string($provider) ? $provider : null,
                data_get($event->metadata, 'site_external_issue_project_id'),
            ),
        ];
    }

    /**
     * @return array{label: string, route: string, site_external_issue_project_id: int}|null
     */
    private function externalIssueRetryAction(Ticket $ticket, ?string $provider, mixed $projectId): ?array
    {
        $routeName = $this->externalIssueRetryRouteName($provider);

        if ($routeName === null || ! is_numeric($projectId)) {
            return null;
        }

        $projectId = (int) $projectId;
        $project = $ticket->site->externalIssueProjects
            ->first(fn (SiteExternalIssueProject $project): bool => (int) $project->id === $projectId
                && (int) $project->account_id === (int) $ticket->account_id
                && (int) $project->site_id === (int) $ticket->site_id
                && $project->providerConnection?->provider === $provider
                && $project->providerConnection?->is_enabled === true
                && $project->hasCapability('create_issue'));

        if (! $project) {
            return null;
        }

        return [
            'label' => 'Retry '.ExternalIssueProvider::label($provider).' issue',
            'route' => route($routeName, $ticket),
            'site_external_issue_project_id' => $project->id,
        ];
    }

    private function externalIssueRetryRouteName(?string $provider): ?string
    {
        return match ($provider) {
            'github' => 'dashboard.tickets.external-issues.github.store',
            'gitlab' => 'dashboard.tickets.external-issues.gitlab.store',
            default => null,
        };
    }

    private function issueProjectsForTicket(Ticket $ticket, string $provider): Collection
    {
        return $ticket->site->externalIssueProjects
            ->filter(fn ($project): bool => $project->providerConnection?->provider === $provider
                && $project->providerConnection->is_enabled
                && $project->hasCapability('create_issue'))
            ->values();
    }

    /**
     * @return array{has_visitor: bool, anonymous_id: string, external_id: string|null, last_seen_at: CarbonInterface|null, last_page_url: string|null, started_page_url: string|null, host_context: array<string, string>}
     */
    private function visitorContext(Ticket $ticket, VisitorContextSanitizer $visitorContextSanitizer): array
    {
        $requester = $ticket->requester;
        $requesterMetadata = $requester?->metadata ?? [];
        $conversationMetadata = $ticket->conversation?->metadata ?? [];
        $metadata = $ticket->metadata ?? [];
        $visitorContext = $metadata['visitor_context'] ?? [];

        if (! is_array($visitorContext)) {
            $visitorContext = [];
        }

        $hostContext = $visitorContext['host_context'] ?? null;

        if (! is_array($hostContext) || $hostContext === []) {
            $hostContext = $requesterMetadata['context'] ?? [];
        }

        return [
            'has_visitor' => $requester !== null,
            'anonymous_id' => $requester?->anonymous_id ?? 'Not linked',
            'external_id' => $visitorContextSanitizer->sanitizeIdentifier($requester?->external_id),
            'last_seen_at' => $requester?->last_seen_at,
            'last_page_url' => $this->contextString($visitorContext['last_page_url'] ?? null)
                ?? $this->contextString($requesterMetadata['last_page_url'] ?? null),
            'started_page_url' => $this->contextString($visitorContext['started_page_url'] ?? null)
                ?? $this->contextString($conversationMetadata['started_page_url'] ?? null),
            'host_context' => $visitorContextSanitizer->sanitize($hostContext),
        ];
    }

    /**
     * @return Collection<int, Conversation>
     */
    private function priorVisitorConversations(Ticket $ticket): Collection
    {
        if (! $ticket->requester_id) {
            return collect();
        }

        return Conversation::query()
            ->with(['assignedAgent', 'tickets'])
            ->where('site_id', $ticket->site_id)
            ->where('visitor_id', $ticket->requester_id)
            ->when($ticket->conversation_id, fn ($query) => $query->whereKeyNot($ticket->conversation_id))
            ->latest('last_message_at')
            ->latest('created_at')
            ->latest('id')
            ->limit(5)
            ->get();
    }

    /**
     * @return Collection<int, Ticket>
     */
    private function priorVisitorTickets(Ticket $ticket): Collection
    {
        if (! $ticket->requester_id) {
            return collect();
        }

        return Ticket::query()
            ->with(['assignee', 'conversation'])
            ->where('account_id', $ticket->account_id)
            ->where('site_id', $ticket->site_id)
            ->where('requester_id', $ticket->requester_id)
            ->whereKeyNot($ticket->id)
            ->latest('updated_at')
            ->latest('created_at')
            ->latest('id')
            ->limit(5)
            ->get();
    }

    private function contextString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 2048);
    }

    /**
     * @return list<string>
     */
    private function visibleActivityActions(): array
    {
        return [
            'ticket.created',
            'ticket.updated',
            'ticket.pending',
            'ticket.closed',
            'ticket.reopened',
            'ticket.assignee_updated',
            'ticket.escalated',
            'ticket.label_added',
            'ticket.label_removed',
            'ticket.note_added',
            'ticket.reply_sent',
            'ticket.external_link_created',
            'ticket.external_issue_created',
            'ticket.external_link_removed',
            'ticket.external_sync_failed',
            'ticket.visitor_replied',
        ];
    }

    /**
     * @return list<string>
     */
    private function timelineActivityActions(): array
    {
        return [
            'ticket.created',
            'ticket.updated',
            'ticket.pending',
            'ticket.closed',
            'ticket.reopened',
            'ticket.assignee_updated',
            'ticket.escalated',
            'ticket.label_added',
            'ticket.label_removed',
            'ticket.note_added',
            'ticket.external_link_created',
            'ticket.external_issue_created',
            'ticket.external_link_removed',
            'ticket.external_sync_failed',
            'ticket.visitor_replied',
        ];
    }

    private function ticketActivityLabel(object $activity): string
    {
        return match ($activity->action) {
            'ticket.created' => data_get($activity->metadata, 'source') === 'conversation' && data_get($activity->metadata, 'support_code')
                ? 'Ticket created from conversation '.data_get($activity->metadata, 'support_code')
                : 'Ticket created',
            'ticket.closed' => 'Ticket closed',
            'ticket.pending' => 'Ticket marked pending',
            'ticket.reopened' => 'Ticket reopened',
            'ticket.visitor_replied' => 'Visitor replied',
            'ticket.label_added' => 'Label added: '.data_get($activity->metadata, 'label_name'),
            'ticket.label_removed' => 'Label removed: '.data_get($activity->metadata, 'label_name'),
            'ticket.note_added' => 'Internal note',
            'ticket.external_link_created' => 'External link added: '.ExternalIssueProvider::label(data_get($activity->metadata, 'provider')).' '.(data_get($activity->metadata, 'external_key') ?? data_get($activity->metadata, 'external_id') ?? ''),
            'ticket.external_issue_created' => ExternalIssueProvider::label(data_get($activity->metadata, 'provider')).' issue created: '.(data_get($activity->metadata, 'external_key') ?? data_get($activity->metadata, 'external_id') ?? ''),
            'ticket.external_link_removed' => 'External link removed: '.ExternalIssueProvider::label(data_get($activity->metadata, 'provider')).' '.(data_get($activity->metadata, 'external_key') ?? data_get($activity->metadata, 'external_id') ?? ''),
            'ticket.external_sync_failed' => 'External sync failed: '.ExternalIssueProvider::label(data_get($activity->metadata, 'provider')),
            'ticket.assignee_updated' => 'Assignee changed from '.(data_get($activity->metadata, 'old_assignee_name') ?? 'Unassigned').' to '.(data_get($activity->metadata, 'new_assignee_name') ?? 'Unassigned'),
            'ticket.escalated' => 'Ticket escalated from '.(data_get($activity->metadata, 'old_assignee_name') ?? 'Unassigned').' to '.(data_get($activity->metadata, 'target_agent_name') ?? data_get($activity->metadata, 'new_assignee_name') ?? 'Unassigned'),
            'ticket.updated' => $this->ticketUpdatedLabel(data_get($activity->metadata, 'changes', [])),
            default => ucfirst(str_replace(['ticket.', '_'], ['', ' '], $activity->action)),
        };
    }

    private function ticketActivityActor(object $activity): string
    {
        if ($activity->actor_type === Visitor::class) {
            return 'Visitor';
        }

        return $activity->actor?->name ?? 'System';
    }

    private function ticketTimelineBody(object $activity): ?string
    {
        return match ($activity->action) {
            'ticket.note_added' => data_get($activity->metadata, 'body'),
            'ticket.pending' => data_get($activity->metadata, 'pending_note'),
            'ticket.closed' => data_get($activity->metadata, 'resolution_note'),
            'ticket.reopened' => data_get($activity->metadata, 'reopen_note'),
            'ticket.escalated' => data_get($activity->metadata, 'reason'),
            default => null,
        };
    }

    private function ticketUpdatedLabel(array $changes): string
    {
        if ($changes === []) {
            return 'Ticket updated';
        }

        return collect($changes)
            ->map(function (array $change, string $field): string {
                if ($field === 'description') {
                    return 'Description updated';
                }

                if ($field === 'category') {
                    return 'Category changed from '.TicketCategory::label(data_get($change, 'old')).' to '.TicketCategory::label(data_get($change, 'new'));
                }

                if ($field === 'priority') {
                    return 'Priority changed from '.ucfirst((string) data_get($change, 'old')).' to '.ucfirst((string) data_get($change, 'new'));
                }

                return ucfirst($field).' changed from '.data_get($change, 'old').' to '.data_get($change, 'new');
            })
            ->implode(' ');
    }

    /**
     * @param  array{subject: string, description?: string|null, category?: string|null, priority: string}  $validated
     * @return array<string, array{old: string|null, new: string|null}>
     */
    private function ticketFieldChanges(Ticket $ticket, array $validated): array
    {
        $changes = [];

        foreach (['subject', 'description', 'category', 'priority'] as $field) {
            $oldValue = $ticket->{$field};
            $newValue = $validated[$field] ?? null;

            if ($oldValue !== $newValue) {
                $changes[$field] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $changes;
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
