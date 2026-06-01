<?php

namespace App\Http\Controllers;

use App\Events\ConversationMessageCreated;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketLabel;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use App\Notifications\TicketAssigned;
use App\Support\AgentNoteTemplate;
use App\Support\AgentReplyTemplate;
use App\Support\ExternalIssueProvider;
use App\Support\ExternalIssueSyncStatus;
use App\Support\TicketCategory;
use App\Support\TicketPriority;
use App\Support\VisitorContextSanitizer;
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
    private const RESERVED_LABEL_SLUGS = ['all'];

    public function show(Request $request, Ticket $ticket, VisitorContextSanitizer $visitorContextSanitizer): View
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'view', $ticket);
        $this->markTicketAssignmentNotificationsRead($agent, $ticket);
        $ticket->loadMissing('site');
        $ticket->load([
            'assignee',
            'conversation',
            'externalLinks' => fn ($query) => $query
                ->latest()
                ->latest('id'),
            'labels',
            'requester',
            'site.externalIssueProjects.providerConnection',
            'auditEvents' => fn ($query) => $query
                ->where('action', 'ticket.note_added')
                ->with('actor')
                ->latest('occurred_at')
                ->latest('id'),
        ]);

        return view('agent.tickets.show', [
            'account' => $agent->account()->firstOrFail(),
            'accountAgents' => $this->supportAgentsForSite($ticket->site),
            'agent' => $agent,
            'externalIssueProviders' => ExternalIssueProvider::options(),
            'externalIssueSyncStatuses' => ExternalIssueSyncStatus::options(),
            'githubIssueProjects' => $this->githubIssueProjectsForTicket($ticket),
            'noteTemplates' => AgentNoteTemplate::options(),
            'replyTemplates' => AgentReplyTemplate::options(),
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
            'visitorContext' => $this->visitorContext($ticket, $visitorContextSanitizer),
            'linkedConversationMessages' => $this->linkedConversationMessages($ticket),
            'ticketTimeline' => $this->ticketTimeline($ticket),
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

        return $this->redirectAfterUpdate($ticket, 'Ticket note added.');
    }

    public function storeLabel(Request $request, Ticket $ticket): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'update', $ticket);

        $validated = $request->validate([
            'label_name' => ['required', 'string', 'max:64'],
        ]);

        $name = $this->normalizeLabelName($validated['label_name']);
        $slug = Str::slug($name);

        if ($name === '' || $slug === '') {
            throw ValidationException::withMessages([
                'label_name' => 'Use at least one letter or number for the label.',
            ]);
        }

        if (in_array($slug, self::RESERVED_LABEL_SLUGS, true)) {
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

        return $this->redirectAfterUpdate($ticket, 'Ticket label added.');
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

        return $this->redirectAfterUpdate($ticket, 'Ticket label removed.');
    }

    public function storeReply(Request $request, Ticket $ticket): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'reply', $ticket);
        $ticket->loadMissing('conversation');

        abort_unless($ticket->conversation, 404);

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:4000'],
            'reply_template' => ['nullable', Rule::in(AgentReplyTemplate::values())],
        ]);

        $selectedTemplate = $validated['reply_template'] ?? null;
        $body = trim((string) ($validated['message'] ?? ''));

        if ($body === '' && $selectedTemplate) {
            $body = trim((string) AgentReplyTemplate::body($selectedTemplate));
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

        if ($selectedTemplate) {
            $metadata['reply_template'] = $selectedTemplate;
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

        if ($selectedTemplate) {
            $activityMetadata['reply_template'] = $selectedTemplate;
        }

        $this->recordActivity($ticket, $agent, 'ticket.reply_sent', $activityMetadata);
        $this->markConversationNotificationsRead($agent, $conversation);
        $conversation->markReadFor($agent);

        event(new ConversationMessageCreated($message));

        return $this->redirectAfterUpdate($ticket, 'Reply sent.');
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

        return $this->redirectAfterUpdate($ticket, 'Ticket updated.');
    }

    public function pending(Request $request, Ticket $ticket): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'updateStatus', $ticket);

        $ticket->forceFill([
            'status' => 'pending',
            'closed_at' => null,
        ])->save();

        $this->recordActivity($ticket, $agent, 'ticket.pending');

        return $this->redirectAfterUpdate($ticket, 'Ticket marked pending.');
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

        return $this->redirectAfterUpdate($ticket, 'Ticket closed.');
    }

    public function reopen(Request $request, Ticket $ticket): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'updateStatus', $ticket);

        $ticket->forceFill([
            'status' => 'open',
            'closed_at' => null,
        ])->save();

        $this->recordActivity($ticket, $agent, 'ticket.reopened');

        return $this->redirectAfterUpdate($ticket, 'Ticket reopened.');
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

        return $this->redirectAfterUpdate($ticket, 'Ticket assignee updated.');
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
            : $site->account->agents()->orderBy('name')->get();
    }

    private function redirectAfterUpdate(Ticket $ticket, string $status): RedirectResponse
    {
        return redirect()
            ->back(302, [], route('dashboard'))
            ->with('status', $status);
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

    private function githubIssueProjectsForTicket(Ticket $ticket): Collection
    {
        return $ticket->site->externalIssueProjects
            ->filter(fn ($project): bool => $project->providerConnection?->provider === 'github'
                && $project->providerConnection->is_enabled
                && $project->hasCapability('create_issue'))
            ->values();
    }

    /**
     * @return array{last_page_url: string|null, started_page_url: string|null, host_context: array<string, string>}
     */
    private function visitorContext(Ticket $ticket, VisitorContextSanitizer $visitorContextSanitizer): array
    {
        $metadata = $ticket->metadata ?? [];
        $visitorContext = $metadata['visitor_context'] ?? [];

        if (! is_array($visitorContext)) {
            $visitorContext = [];
        }

        return [
            'last_page_url' => $this->contextString($visitorContext['last_page_url'] ?? null),
            'started_page_url' => $this->contextString($visitorContext['started_page_url'] ?? null),
            'host_context' => $visitorContextSanitizer->sanitize($visitorContext['host_context'] ?? []),
        ];
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
            'ticket.external_issue_created' => 'GitHub issue created: '.(data_get($activity->metadata, 'external_key') ?? data_get($activity->metadata, 'external_id') ?? ''),
            'ticket.external_link_removed' => 'External link removed: '.ExternalIssueProvider::label(data_get($activity->metadata, 'provider')).' '.(data_get($activity->metadata, 'external_key') ?? data_get($activity->metadata, 'external_id') ?? ''),
            'ticket.external_sync_failed' => 'External sync failed: '.ExternalIssueProvider::label(data_get($activity->metadata, 'provider')),
            'ticket.assignee_updated' => 'Assignee changed from '.(data_get($activity->metadata, 'old_assignee_name') ?? 'Unassigned').' to '.(data_get($activity->metadata, 'new_assignee_name') ?? 'Unassigned'),
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
            'ticket.closed' => data_get($activity->metadata, 'resolution_note'),
            default => null,
        };
    }

    private function normalizeLabelName(string $labelName): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $labelName)), 0, 64);
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
