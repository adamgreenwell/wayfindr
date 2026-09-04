<?php

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Enums\ConversationStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\ConversationMessageCreated;
use App\Events\TicketUpdated;
use App\Jobs\DeliverTicketExternalComment;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ExternalIssueProviderConnection;
use App\Models\Site;
use App\Models\SiteExternalIssueProject;
use App\Models\Ticket;
use App\Models\TicketExternalCommentDelivery;
use App\Models\TicketExternalLink;
use App\Models\TicketLabel;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\AutomationRuleMatched;
use App\Notifications\ConversationNeedsReply;
use App\Notifications\SlaDeadlineAlert;
use App\Notifications\TicketAssigned;
use App\Support\AgentNoteTemplate;
use App\Support\Automation\AutomationMacroAuthorization;
use App\Support\DashboardLanguage;
use App\Support\ExternalIssueProvider;
use App\Support\ExternalIssues\ExternalIssueExportPreview;
use App\Support\ExternalIssueSyncStatus;
use App\Support\ReplyTemplateOptions;
use App\Support\Routing\AssignmentAuditTrail;
use App\Support\Sites\SiteManagerCoverage;
use App\Support\Sla\SlaStatePresenter;
use App\Support\TicketCategory;
use App\Support\TicketExternalIssueAttempt;
use App\Support\VisitorContextSanitizer;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AgentTicketController extends Controller
{
    /** Providers with an IssueCommenter implementation for outbound note relay. */
    private const COMMENT_PROVIDERS = ['github', 'gitlab', 'jira'];

    public function __construct(
        private readonly SiteManagerCoverage $siteManagerCoverage,
        private readonly AssignmentAuditTrail $assignmentAuditTrail,
    ) {}

    public function show(Request $request, Ticket $ticket, VisitorContextSanitizer $visitorContextSanitizer, ReplyTemplateOptions $replyTemplateOptions, ExternalIssueExportPreview $externalIssueExportPreview, SlaStatePresenter $slaStates, AutomationMacroAuthorization $macroAuthorization): View
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'view', $ticket);
        $this->markTicketAssignmentNotificationsRead($agent, $ticket);
        $canViewLinkedConversation = $agent->hasAccountPermission(AccountPermission::ViewConversations);
        $canReplyToLinkedConversation = Gate::forUser($agent)->allows('reply', $ticket);
        $ticket->loadMissing('site');
        $ticket->load([
            'assignee',
            'conversation.latestAgentMessage',
            'conversation.latestMessage',
            'conversation.latestNonIntegrationMessage',
            'externalLinks' => fn ($query) => $query
                ->latest()
                ->latest('id'),
            'labels',
            'latestEscalationEvent.actor',
            'requester',
            'site.externalIssueProjects.providerConnection',
            'slaClocks',
            'auditEvents' => fn ($query) => $query
                ->where('action', 'ticket.note_added')
                ->with('actor')
                ->latest('occurred_at')
                ->latest('id'),
        ]);

        $ticketReturnQuery = $this->ticketQueueReturnQuery($request);
        $ticketDetailReturnQuery = $this->ticketDetailReturnQuery($request);
        $ticketTimelineFilter = $this->ticketTimelineFilter($request);
        $canViewTicketDescription = $canViewLinkedConversation
            || ! $ticket->hasConversationDerivedDescription();
        $fullTicketTimeline = $this->ticketTimeline($ticket, $canViewLinkedConversation);
        $ticketTimeline = $this->filteredTicketTimeline($fullTicketTimeline, $ticketTimelineFilter);
        $account = $agent->account()->firstOrFail();
        $automationMacros = $account->automationMacros()
            ->enabled()
            ->forSubjectType('ticket')
            ->inDisplayOrder()
            ->get()
            ->filter(fn ($macro): bool => $macroAuthorization->allows($agent, $macro, $ticket))
            ->values();

        return view('agent.tickets.show', [
            'account' => $account,
            'accountAgents' => $this->supportAgentsForSite($ticket->site),
            'agent' => $agent,
            'automationMacros' => $automationMacros,
            'canAssignTickets' => Gate::forUser($agent)->allows('assign', $ticket),
            'canReplyToLinkedConversation' => $canReplyToLinkedConversation,
            'canViewLinkedConversation' => $canViewLinkedConversation,
            'canViewTicketDescription' => $canViewTicketDescription,
            'canPostNoteToExternalIssue' => $this->commentableExternalLinks($ticket)->isNotEmpty(),
            'externalIssueProviders' => collect(ExternalIssueProvider::options())
                ->map(fn (string $_label, string $provider): string => $this->ticketExternalIssueProviderLabel($provider))
                ->all(),
            'externalIssueSyncStatuses' => $this->translatedOptions('ticket_detail.external.sync_statuses', array_keys(ExternalIssueSyncStatus::options())),
            'externalIssueExportPreview' => $externalIssueExportPreview->forTicket($ticket, $canViewLinkedConversation),
            'githubIssueProjects' => $this->githubIssueProjectsForTicket($ticket),
            'gitlabIssueProjects' => $this->gitlabIssueProjectsForTicket($ticket),
            'jiraIssueProjects' => $this->jiraIssueProjectsForTicket($ticket),
            'latestTicketEscalation' => $ticket->latestRecentEscalationEvent(),
            'noteTemplates' => $this->noteTemplates(),
            'replyTemplates' => $canReplyToLinkedConversation
                ? $replyTemplateOptions->forAgent($agent)
                : collect(),
            'ticketDetailReturnQuery' => $ticketDetailReturnQuery,
            'ticketReturnLink' => $this->ticketReturnLink($ticketReturnQuery),
            'ticketReturnQuery' => $ticketReturnQuery,
            'ticketLabelOptions' => $agent->account->ticketLabels()
                ->orderBy('name')
                ->get(),
            'ticketActivity' => $this->visibleTicketActivity($ticket, $canViewLinkedConversation),
            'ticketCategories' => TicketCategory::options(),
            'ticketCategoryGuidance' => TicketCategory::options(),
            'ticketDescription' => $canViewTicketDescription ? $ticket->description : null,
            'ticketPriorities' => TicketPriority::options(),
            'ticketPriorityGuidance' => TicketPriority::guidanceOptions(),
            'slaStates' => $slaStates->all($ticket),
            'ticket' => $ticket,
            'ticketArtifactCoverage' => $this->ticketArtifactCoverage($ticket),
            'ticketExternalIssueHandoffReadiness' => $this->ticketExternalIssueHandoffReadiness($ticket),
            'ticketExternalIssueHealth' => $this->ticketExternalIssueHealth($ticket),
            'visitorContext' => $this->visitorContext($ticket, $visitorContextSanitizer),
            'priorVisitorConversations' => $canViewLinkedConversation
                ? $this->priorVisitorConversations($ticket)
                : collect(),
            'priorVisitorTickets' => $this->priorVisitorTickets($ticket, $canViewLinkedConversation),
            'linkedConversationMessages' => $this->linkedConversationMessages($ticket, $canViewLinkedConversation),
            'linkedConversationSupportCode' => $canViewLinkedConversation
                ? $ticket->conversation?->support_code
                : null,
            'ticketTimelineEmptyDescription' => $this->ticketTimelineEmptyDescription($ticketTimelineFilter),
            'ticketTimelineEmptyMessage' => $this->ticketTimelineEmptyMessage($ticketTimelineFilter),
            'ticketTimelineFilter' => $ticketTimelineFilter,
            'ticketTimelineFilters' => $this->ticketTimelineFilters(),
            'ticketTimeline' => $ticketTimeline,
            'ticketTimelineSummary' => $this->ticketTimelineSummary($fullTicketTimeline),
            'ticketTimelineTotalCount' => $fullTicketTimeline->count(),
        ]);
    }

    public function storeNote(Request $request, Ticket $ticket): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'addNote', $ticket);

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'note_template' => ['nullable', Rule::in(AgentNoteTemplate::values())],
            'post_to_external' => ['nullable', 'boolean'],
        ]);

        $selectedTemplate = $validated['note_template'] ?? null;
        $body = trim((string) ($validated['body'] ?? ''));

        if ($body === '' && $selectedTemplate) {
            $body = trim((string) AgentNoteTemplate::body($selectedTemplate));
        }

        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => __('tickets.errors.note_required'),
            ]);
        }

        $metadata = [
            'body' => $body,
        ];

        if ($selectedTemplate) {
            $metadata['note_template'] = $selectedTemplate;
        }

        $postToExternal = $request->boolean('post_to_external');
        [$agent, $ticket, $deliveryIds] = DB::transaction(function () use ($agent, $body, $metadata, $postToExternal, $ticket): array {
            [$agent, $ticket] = $this->lockedTicketActor($agent, $ticket, 'addNote');
            $note = $this->recordActivity($ticket, $agent, 'ticket.note_added', $metadata);
            $deliveryIds = [];

            // Internal notes stay internal unless the agent explicitly opts to
            // relay this one. The durable intent is committed under the same
            // account lock as the fresh authorization. Provider HTTP runs only
            // afterwards, so an accepted remote comment can never be rolled
            // back into a retryable local request.
            if ($postToExternal) {
                foreach ($this->commentableExternalLinks($ticket) as $target) {
                    $delivery = TicketExternalCommentDelivery::query()->create([
                        'public_id' => (string) Str::uuid(),
                        'account_id' => $ticket->account_id,
                        'site_id' => $ticket->site_id,
                        'ticket_id' => $ticket->id,
                        'ticket_external_link_id' => $target['link']->id,
                        'provider_connection_id' => $target['connection']->id,
                        'actor_id' => $agent->id,
                        'note_audit_event_id' => $note->id,
                        'body' => $body,
                    ]);
                    $deliveryIds[] = $delivery->id;
                }
            }

            return [$agent, $ticket, $deliveryIds];
        });

        $outcomes = collect($deliveryIds)
            ->map(fn (int $deliveryId): string => DeliverTicketExternalComment::processNow($deliveryId));
        $status = 'tickets.flash.note_added';

        if ($outcomes->contains(DeliverTicketExternalComment::OUTCOME_FAILED)) {
            $status = 'tickets.flash.note_added_not_posted';
        } elseif ($outcomes->contains(DeliverTicketExternalComment::OUTCOME_PENDING)) {
            $status = 'tickets.flash.note_added_queued';
        } elseif ($outcomes->contains(DeliverTicketExternalComment::OUTCOME_POSTED)) {
            $status = 'tickets.flash.note_added_posted';
        }

        return $this->redirectAfterUpdate($ticket, $request, $status);
    }

    /**
     * External links whose provider connection is enabled, has the add_comment
     * capability, and has a commenter implementation. Used both to decide
     * whether to offer the opt-in and to relay a note.
     *
     * @return Collection<int, array{link: TicketExternalLink, connection: ExternalIssueProviderConnection}>
     */
    private function commentableExternalLinks(Ticket $ticket): Collection
    {
        $links = $ticket->relationLoaded('externalLinks')
            ? $ticket->externalLinks
            : $ticket->externalLinks()->get();

        $connectionIds = $links
            ->map(fn (TicketExternalLink $link): mixed => data_get($link->metadata, 'external_issue_provider_connection_id'))
            ->filter()
            ->unique()
            ->values();

        if ($connectionIds->isEmpty()) {
            return collect();
        }

        $connections = ExternalIssueProviderConnection::query()
            ->where('account_id', $ticket->account_id)
            ->whereKey($connectionIds->all())
            ->where('is_enabled', true)
            ->get()
            ->keyBy('id');

        return $links
            ->map(function (TicketExternalLink $link) use ($connections): ?array {
                $connectionId = data_get($link->metadata, 'external_issue_provider_connection_id');
                $connection = $connectionId ? $connections->get($connectionId) : null;

                if (! $connection instanceof ExternalIssueProviderConnection || ! $connection->hasCapability('add_comment')) {
                    return null;
                }

                // Only providers with a commenter implementation can relay.
                if (! in_array($link->provider, self::COMMENT_PROVIDERS, true)) {
                    return null;
                }

                return ['link' => $link, 'connection' => $connection];
            })
            ->filter()
            ->values();
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
                'label_name' => __('tickets.errors.label_needs_content'),
            ]);
        }

        if (TicketLabel::isReservedSlug($slug)) {
            throw ValidationException::withMessages([
                'label_name' => __('tickets.errors.label_reserved'),
            ]);
        }

        [$agent, $ticket] = DB::transaction(function () use ($agent, $name, $slug, $ticket): array {
            [$agent, $ticket] = $this->lockedTicketActor($agent, $ticket, 'update');
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

            return [$agent, $ticket];
        });

        return $this->redirectAfterUpdate($ticket, $request, 'tickets.flash.label_added');
    }

    public function destroyLabel(Request $request, Ticket $ticket, TicketLabel $ticketLabel): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'update', $ticket);

        [$agent, $ticket] = DB::transaction(function () use ($agent, $ticket, $ticketLabel): array {
            [$agent, $ticket] = $this->lockedTicketActor($agent, $ticket, 'update');
            $ticketLabel = TicketLabel::query()
                ->whereKey($ticketLabel->id)
                ->where('account_id', $ticket->account_id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($ticket->labels()->whereKey($ticketLabel->id)->exists(), 404);

            $ticket->labels()->detach($ticketLabel->id);

            $this->recordActivity($ticket, $agent, 'ticket.label_removed', [
                'label_id' => $ticketLabel->id,
                'label_name' => $ticketLabel->name,
                'label_slug' => $ticketLabel->slug,
            ]);

            return [$agent, $ticket];
        });

        return $this->redirectAfterUpdate($ticket, $request, 'tickets.flash.label_removed');
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
                    'reply_template' => __('tickets.errors.reply_helper'),
                ]);
            }
        }

        if ($body === '' && $resolvedTemplate) {
            $body = trim($resolvedTemplate['body']);
        }

        if ($body === '') {
            throw ValidationException::withMessages([
                'message' => __('tickets.errors.reply_required'),
            ]);
        }

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

        [$message, $agent, $conversation, $ticket] = DB::transaction(function () use ($ticket, $agent, $body, $metadata, $resolvedTemplate): array {
            [$agent, $ticket] = $this->lockedTicketActor($agent, $ticket, 'reply');
            $conversation = Conversation::query()
                ->whereKey($ticket->conversation_id)
                ->where('site_id', $ticket->site_id)
                ->lockForUpdate()
                ->firstOrFail();
            $canManageConversation = $agent->hasAccountPermission(AccountPermission::ManageConversations);

            // ConversationMessageObserver creates the webhook outbox rows
            // during this insert. Keep the reply, conversation state and
            // delivery handoff atomic even when the queue is unavailable.
            $message = $conversation->messages()->create([
                'sender_type' => User::class,
                'sender_id' => $agent->id,
                'type' => 'text',
                'body' => $body,
                'metadata' => $metadata,
            ]);

            $wasUnassigned = $conversation->assigned_agent_id === null;
            $conversationAttributes = [
                'assigned_agent_id' => $conversation->assigned_agent_id ?: ($canManageConversation ? $agent->id : null),
                'last_message_at' => $message->created_at,
            ];

            if ($canManageConversation) {
                $conversationAttributes['status'] = ConversationStatus::Open;
                $conversationAttributes['closed_at'] = null;
            }

            $conversation->forceFill($conversationAttributes)->save();

            if ($wasUnassigned && $conversation->assigned_agent_id !== null) {
                $this->assignmentAuditTrail->conversation($conversation, $agent, null, $agent, 'manual');
            }

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

            return [$message, $agent, $conversation, $ticket];
        });
        $this->markConversationNotificationsRead($agent, $conversation);
        $conversation->markReadFor($agent);

        event(new ConversationMessageCreated($message));

        return $this->redirectAfterUpdate($ticket, $request, 'tickets.flash.reply_sent');
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
            'priority' => ['required', Rule::enum(TicketPriority::class)],
        ]);

        [$agent, $ticket] = DB::transaction(function () use ($agent, $ticket, $validated): array {
            [$agent, $ticket] = $this->lockedTicketActor($agent, $ticket, 'update');
            $changes = $this->ticketFieldChanges($ticket, $validated);

            if (array_key_exists('description', $changes)) {
                $validated['metadata'] = array_replace($ticket->metadata ?? [], [
                    'description_source' => 'agent_summary',
                ]);
            }

            $ticket->forceFill($validated)->save();

            if ($changes !== []) {
                $this->recordActivity($ticket, $agent, 'ticket.updated', [
                    'changes' => $changes,
                ]);
                event(new TicketUpdated($ticket));
            }

            return [$agent, $ticket];
        });

        return $this->redirectAfterUpdate($ticket, $request, 'tickets.flash.updated');
    }

    public function pending(Request $request, Ticket $ticket): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'updateStatus', $ticket);

        $validated = $request->validate([
            'pending_note' => ['nullable', 'string', 'max:4000'],
        ]);

        $pendingNote = trim((string) ($validated['pending_note'] ?? ''));
        $metadata = $pendingNote === '' ? [] : ['pending_note' => $pendingNote];

        $this->transitionTicketStatus(
            $ticket,
            $agent,
            fn (): array => ['status' => TicketStatus::Pending, 'closed_at' => null],
            function (string $previousStatus, Ticket $ticket, User $agent) use ($metadata): void {

                // Leaving `closed` is a REOPEN, whichever button did it. The form is
                // only offered for open tickets, so this is a stale or crafted submit
                // -- but it still un-closes the ticket, and recording only the hold
                // would leave the resolution looking like it held while the ticket
                // quietly went back to work. Every duration measured afterwards would
                // run from the original start.
                if ($previousStatus === 'closed') {
                    $this->recordActivity($ticket, $agent, 'ticket.reopened', $metadata);
                }

                // Only a transition is an event: a ticket already on hold does
                // not go on hold again.
                if ($previousStatus !== 'pending') {
                    $this->recordActivity($ticket, $agent, 'ticket.pending', $metadata);
                }
            },
        );

        return $this->redirectAfterUpdate($ticket, $request, 'tickets.flash.marked_pending');
    }

    public function close(Request $request, Ticket $ticket): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'updateStatus', $ticket);

        $validated = $request->validate([
            'resolution_note' => ['nullable', 'string', 'max:4000'],
        ]);

        $resolutionNote = trim((string) ($validated['resolution_note'] ?? ''));

        $this->transitionTicketStatus(
            $ticket,
            $agent,
            // A ticket already closed keeps the moment it was actually closed.
            fn (string $previous, Ticket $locked): array => [
                'status' => TicketStatus::Closed,
                'closed_at' => $previous === 'closed' ? $locked->closed_at : now(),
            ],
            // Only a TRANSITION is an event -- the rule conversation lifecycle
            // already follows. A double-click, a retry, or a stale page submits
            // close twice; recording both writes consecutive closes with no
            // reopen between them, which makes one resolution contribute two
            // durations to the report and inflates every close count derived
            // from the log.
            function (string $previous, Ticket $ticket, User $agent) use ($resolutionNote): void {
                if ($previous === 'closed') {
                    return;
                }

                $this->recordActivity(
                    $ticket,
                    $agent,
                    'ticket.closed',
                    $resolutionNote === '' ? [] : ['resolution_note' => $resolutionNote],
                );
            },
        );

        return $this->redirectAfterUpdate($ticket, $request, 'tickets.flash.closed');
    }

    public function reopen(Request $request, Ticket $ticket): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'updateStatus', $ticket);

        $validated = $request->validate([
            'reopen_note' => ['nullable', 'string', 'max:4000'],
        ]);

        $reopenNote = trim((string) ($validated['reopen_note'] ?? ''));

        $this->transitionTicketStatus(
            $ticket,
            $agent,
            fn (): array => ['status' => TicketStatus::Open, 'closed_at' => null],
            function (string $previousStatus, Ticket $ticket, User $agent) use ($reopenNote): void {

                // The same control reopens a CLOSED ticket and un-holds a PENDING one,
                // and only the first is a reopen. `open -> pending -> reopen -> close`
                // is the ordinary flow, not an edge case: recording a reopen there
                // claims a resolution failed when none was ever reached, and restarts
                // the resolution clock at the un-hold, hiding every hour before the
                // ticket was put on hold.
                //
                // And an ALREADY-OPEN ticket transitioned from nothing, so it records
                // nothing. A retried submit or a stale form would otherwise write an
                // un-hold for a hold that never happened -- the same duplicate-event
                // bug the close path has a guard for, reintroduced one branch over.
                $action = match ($previousStatus) {
                    'closed' => 'ticket.reopened',
                    'pending' => 'ticket.unheld',
                    default => null,
                };

                if ($action !== null) {
                    $this->recordActivity(
                        $ticket,
                        $agent,
                        $action,
                        $reopenNote === '' ? [] : ['reopen_note' => $reopenNote],
                    );
                }
            },
        );

        return $this->redirectAfterUpdate($ticket, $request, 'tickets.flash.reopened');
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

        $newAssigneeId = isset($validated['assignee_id'])
            ? (int) $validated['assignee_id']
            : null;
        [$agent, $ticket, $newAssignee, $oldAssigneeId] = DB::transaction(function () use ($agent, $newAssigneeId, $ticket): array {
            [$agent, $ticket, $newAssignee] = $this->lockedTicketAssignment(
                $agent,
                $ticket,
                $newAssigneeId,
                'assignee_id',
            );
            $ticket->loadMissing('assignee');
            $oldAssigneeId = $ticket->assignee_id;
            $oldAssignee = $ticket->assignee;

            if ($oldAssigneeId !== $newAssigneeId) {
                $ticket->forceFill(['assignee_id' => $newAssigneeId])->save();
                $this->assignmentAuditTrail->ticket($ticket, $agent, $oldAssignee, $newAssignee, 'manual');
                event(new TicketUpdated($ticket));
            }

            return [$agent, $ticket, $newAssignee, $oldAssigneeId];
        });

        if (
            $newAssignee
            && $newAssignee->isNot($agent)
            && $newAssignee->id !== $oldAssigneeId
            && (int) $ticket->assignee_id === (int) $newAssignee->id
            && $newAssignee->shouldReceiveTicketAssignmentAlert($ticket)
        ) {
            $newAssignee->notify(new TicketAssigned($ticket, $agent));
        }

        return $this->redirectAfterUpdate($ticket, $request, 'tickets.flash.assignee_updated');
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

        $reason = trim((string) ($validated['reason'] ?? ''));
        [$agent, $ticket, $targetAgent] = DB::transaction(function () use ($agent, $reason, $ticket, $validated): array {
            [$agent, $ticket, $targetAgent] = $this->lockedTicketAssignment(
                $agent,
                $ticket,
                (int) $validated['target_agent_id'],
                'target_agent_id',
            );
            abort_unless($targetAgent instanceof User, 404);

            if ($targetAgent->is($agent)) {
                throw ValidationException::withMessages([
                    'target_agent_id' => __('tickets.errors.escalate_other_agent'),
                ]);
            }

            $ticket->loadMissing('assignee');
            $oldAssigneeId = $ticket->assignee_id;
            $oldAssigneeName = $ticket->assignee?->name;
            $ticket->forceFill(['assignee_id' => $targetAgent->id])->save();
            $assigneeChanged = $ticket->wasChanged('assignee_id');

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

            if ($assigneeChanged) {
                event(new TicketUpdated($ticket));
            }

            return [$agent, $ticket, $targetAgent];
        });

        if ((int) $ticket->assignee_id === (int) $targetAgent->id
            && $targetAgent->shouldReceiveTicketAssignmentAlert($ticket)) {
            $targetAgent->notify(new TicketAssigned($ticket, $agent));
        }

        return $this->redirectAfterUpdate($ticket, $request, 'tickets.flash.escalated');
    }

    private function authorizeTicketAbility(User $agent, string $ability, Ticket $ticket): void
    {
        abort_unless(Gate::forUser($agent)->allows($ability, $ticket), 404);
    }

    /** @return array{0: User, 1: Ticket} */
    private function lockedTicketActor(User $agent, Ticket $ticket, string $ability): array
    {
        $accountId = (int) $ticket->account_id;
        $this->siteManagerCoverage->lockAccount($accountId);
        $agent = User::query()
            ->whereKey($agent->id)
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->firstOrFail();
        $ticket = Ticket::query()
            ->with('site')
            ->whereKey($ticket->id)
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->firstOrFail();

        $this->authorizeTicketAbility($agent, $ability, $ticket);

        return [$agent, $ticket];
    }

    /** @return array{0: User, 1: Ticket, 2: User|null} */
    private function lockedTicketAssignment(User $agent, Ticket $ticket, ?int $targetAgentId, string $field): array
    {
        $accountId = (int) $ticket->account_id;
        $this->siteManagerCoverage->lockAccount($accountId);
        $userIds = collect([$agent->id, $targetAgentId])
            ->filter(fn (?int $id): bool => $id !== null)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $users = User::query()
            ->where('account_id', $accountId)
            ->whereKey($userIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $agent = $users->get($agent->id);
        abort_unless($agent instanceof User, 404);

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

        $this->authorizeTicketAbility($agent, 'assign', $ticket);

        $targetAgent = $targetAgentId === null ? null : $users->get($targetAgentId);

        if ($targetAgentId !== null && (! $targetAgent instanceof User
            || ! $site->supportsAgent($targetAgent)
            || ! $targetAgent->hasAccountPermission(AccountPermission::ManageTickets))) {
            throw ValidationException::withMessages([
                $field => __('tickets.errors.assignee_not_on_site'),
            ]);
        }

        return [$agent, $ticket, $targetAgent];
    }

    private function supportAgentsForSite(Site $site): Collection
    {
        $supportAgents = $site->eligibleSupportAgents()
            ->with('customRole')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $user->hasAccountPermission(AccountPermission::ManageTickets))
            ->values();

        return $supportAgents->isNotEmpty()
            ? $supportAgents
            : $site->account->agents()
                ->whereNull('deactivated_at')
                ->with('customRole')
                ->orderBy('name')
                ->get()
                ->filter(fn (User $user): bool => $user->hasAccountPermission(AccountPermission::ManageTickets))
                ->values();
    }

    /**
     * A filter or select map whose stored value stays stable while its label
     * follows the reader's request-scoped dashboard language.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function translatedOptions(string $catalogue, array $keys): array
    {
        $options = [];

        foreach ($keys as $key) {
            $options[$key] = __($catalogue.'.'.$key);
        }

        return $options;
    }

    private function ticketExternalIssueProviderLabel(?string $provider): string
    {
        return match ($provider) {
            'other' => __('ticket_detail.external.provider_other'),
            null => __('ticket_detail.external.provider_unknown'),
            default => ExternalIssueProvider::options()[$provider] ?? __('ticket_detail.external.provider_unknown'),
        };
    }

    private function ticketExternalIssueProviderIsBrand(?string $provider): bool
    {
        return $provider !== null
            && $provider !== 'other'
            && array_key_exists($provider, ExternalIssueProvider::options());
    }

    /**
     * @param  array<string, string>  $parameters
     * @param  array<string, string>  $localizedParameters
     * @return array{key: string, parameters: array<string, string>, localized_parameters: array<string, string>}
     */
    private function ticketExternalIssueFeedback(
        string $key,
        ?string $provider,
        array $parameters = [],
        array $localizedParameters = [],
    ): array {
        $providerLabel = $this->ticketExternalIssueProviderLabel($provider);

        if ($this->ticketExternalIssueProviderIsBrand($provider)) {
            $parameters['provider'] = $providerLabel;
        } else {
            $localizedParameters['provider'] = $providerLabel;
        }

        return $this->translatedFeedback($key, $parameters, $localizedParameters);
    }

    /**
     * Only the helper label is dashboard chrome. Its body becomes stored team
     * content, so the built-in English body stays English and says so rather
     * than silently becoming whichever language the current agent reads.
     *
     * @return array<string, array{label: string, body: string, body_language: string}>
     */
    private function noteTemplates(): array
    {
        return collect(AgentNoteTemplate::options())
            ->map(fn (array $template, string $key): array => [
                ...$template,
                'label' => __('ticket_detail.notes.templates.'.$key),
                'body_language' => DashboardLanguage::FALLBACK,
            ])
            ->all();
    }

    /**
     * @return Collection<int, array{label: string, label_feedback: array<string, mixed>|null, subject_change: array{old: string, new: string}|null, label_change: array{action: string, name: string}|null, actor: string, actor_is_authored: bool, body: string|null, occurred_at: CarbonInterface|null}>
     */
    private function visibleTicketActivity(Ticket $ticket, bool $canViewLinkedConversation): Collection
    {
        return $ticket->auditEvents()
            ->with('actor')
            ->whereIn('action', $this->visibleActivityActions())
            ->latest('occurred_at')
            ->latest('id')
            ->get()
            ->map(fn (AuditEvent $activity): array => [
                'label' => $this->ticketActivityLabel($activity),
                'label_feedback' => $this->ticketActivityLabelFeedback($activity, $canViewLinkedConversation),
                'subject_change' => $this->ticketActivitySubjectChange($activity),
                'label_change' => $this->ticketActivityLabelChange($activity),
                'actor' => $this->ticketActivityActor($activity),
                'actor_is_authored' => $this->ticketActivityActorIsAuthored($activity),
                'body' => $this->ticketTimelineBody($activity),
                'occurred_at' => $activity->occurred_at,
            ]);
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
                    ? __('ticket_detail.artifacts.conversation_linked')
                    : __('ticket_detail.artifacts.conversation_unlinked'),
                'label' => __('ticket_detail.artifacts.conversation'),
                'tone' => $ticket->conversation ? 'ready' : 'manual',
                'value' => $ticket->conversation ? __('ticket_detail.artifacts.linked') : __('ticket_detail.common.not_linked'),
            ],
            [
                'description' => $ticket->requester
                    ? __('ticket_detail.artifacts.visitor_linked')
                    : __('ticket_detail.artifacts.visitor_unlinked'),
                'label' => __('ticket_detail.artifacts.visitor'),
                'tone' => $ticket->requester ? 'ready' : 'manual',
                'value' => $ticket->requester ? __('ticket_detail.artifacts.linked') : __('ticket_detail.common.not_linked'),
            ],
            [
                'description' => $labelCount > 0
                    ? __('ticket_detail.artifacts.labels_present')
                    : __('ticket_detail.artifacts.labels_empty'),
                'label' => __('ticket_detail.artifacts.labels'),
                'tone' => $labelCount > 0 ? 'ready' : 'manual',
                'value' => trans_choice('ticket_detail.counts.labels', $labelCount, ['count' => $labelCount]),
            ],
            [
                'description' => $noteCount > 0
                    ? __('ticket_detail.artifacts.notes_present')
                    : __('ticket_detail.artifacts.notes_empty'),
                'label' => __('ticket_detail.artifacts.notes'),
                'tone' => $noteCount > 0 ? 'ready' : 'manual',
                'value' => trans_choice('ticket_detail.counts.notes', $noteCount, ['count' => $noteCount]),
            ],
            [
                'description' => $externalLinkCount > 0
                    ? __('ticket_detail.artifacts.external_present')
                    : __('ticket_detail.artifacts.external_empty'),
                'label' => __('ticket_detail.artifacts.external'),
                'tone' => $externalLinkCount > 0 ? 'ready' : 'manual',
                'value' => $externalLinkCount > 0
                    ? trans_choice('ticket_detail.counts.links', $externalLinkCount, ['count' => $externalLinkCount])
                    : __('ticket_detail.common.not_linked'),
            ],
        ]);
    }

    private function redirectAfterUpdate(Ticket $ticket, Request $request, string $status): RedirectResponse
    {
        $ticketReturnQuery = $this->ticketDetailReturnQuery($request);

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
                'label' => __('ticket_detail.common.back_dashboard'),
                'href' => route('dashboard'),
            ];
        }

        return [
            'label' => __('ticket_detail.common.back_queue'),
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

        $ticketExternalIssue = $request->input('ticket_external');

        if (is_string($ticketExternalIssue) && in_array($ticketExternalIssue, ['failed', 'pending', 'linked', 'none'], true)) {
            $query['ticket_external'] = $ticketExternalIssue;
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
            ->whereIn('type', [TicketAssigned::class, SlaDeadlineAlert::class, AutomationRuleMatched::class])
            ->get()
            ->filter(fn ($notification): bool => (int) data_get($notification->data, 'ticket_id') === $ticket->id)
            ->each
            ->markAsRead();
    }

    private function markConversationNotificationsRead(User $agent, Conversation $conversation): void
    {
        $agent->unreadNotifications()
            ->whereIn('type', [ConversationNeedsReply::class, SlaDeadlineAlert::class, AutomationRuleMatched::class])
            ->get()
            ->filter(fn ($notification): bool => (int) data_get($notification->data, 'conversation_id') === $conversation->id)
            ->each
            ->markAsRead();
    }

    private function linkedConversationMessages(Ticket $ticket, bool $canViewLinkedConversation): Collection
    {
        if (! $canViewLinkedConversation || ! $ticket->conversation) {
            return collect();
        }

        return $ticket->conversation->messages()
            ->with(['sender', 'attachments'])
            ->latest('created_at')
            ->latest('id')
            ->limit(5)
            ->get()
            ->reverse()
            ->values();
    }

    private function ticketTimeline(Ticket $ticket, bool $canViewLinkedConversation): Collection
    {
        $conversationMessages = $canViewLinkedConversation && $ticket->conversation
            ? $ticket->conversation->messages()->with('sender')->get()
            : collect();

        $messageItems = $conversationMessages->toBase()->map(function ($message): array {
            $isAgentMessage = $message->sender_type === User::class;
            $isIntegrationMessage = $message->sender_type === ApiToken::class;
            $isSupportMessage = $isAgentMessage || $isIntegrationMessage;

            return [
                'type' => $isSupportMessage ? 'agent-message' : 'visitor-message',
                'label' => match (true) {
                    $isAgentMessage => __('ticket_detail.timeline.message.agent_reply'),
                    $isIntegrationMessage => __('ticket_detail.timeline.message.integration_reply'),
                    default => __('ticket_detail.timeline.message.visitor_message'),
                },
                'actor' => match (true) {
                    $isAgentMessage => $message->sender?->name ?? __('ticket_detail.common.agent'),
                    $isIntegrationMessage => $message->sender?->name ?? __('ticket_detail.common.integration'),
                    default => __('ticket_detail.common.visitor'),
                },
                'actor_is_authored' => $isSupportMessage && $message->sender?->name !== null,
                'badge' => $isSupportMessage ? __('ticket_detail.timeline.message.customer_visible') : __('ticket_detail.timeline.message.customer_message'),
                'badge_feedback' => null,
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
                'type' => in_array($activity->action, ['ticket.note_added', 'ticket.external_comment_received'], true) ? 'internal-note' : 'ticket-activity',
                'label' => $this->ticketActivityLabel($activity),
                'label_feedback' => $this->ticketActivityLabelFeedback($activity, $canViewLinkedConversation),
                'subject_change' => $this->ticketActivitySubjectChange($activity),
                'label_change' => $this->ticketActivityLabelChange($activity),
                'actor' => $this->ticketActivityActor($activity),
                'actor_is_authored' => $this->ticketActivityActorIsAuthored($activity),
                'badge' => match ($activity->action) {
                    'ticket.note_added' => __('ticket_detail.timeline.message.internal'),
                    'ticket.external_comment_received' => __('ticket_detail.timeline.message.from_provider', [
                        'provider' => $this->ticketExternalIssueProviderLabel(
                            is_string(data_get($activity->metadata, 'provider')) ? data_get($activity->metadata, 'provider') : null
                        ),
                    ]),
                    default => __('ticket_detail.timeline.message.ticket_activity'),
                },
                'badge_feedback' => $activity->action === 'ticket.external_comment_received'
                    ? $this->ticketExternalIssueFeedback(
                        'ticket_detail.timeline.message.from_provider',
                        is_string(data_get($activity->metadata, 'provider')) ? data_get($activity->metadata, 'provider') : null,
                    )
                    : null,
                'body' => $this->ticketTimelineBody($activity),
                'occurred_at' => $activity->occurred_at,
                'sequence' => $activity->id,
            ]);

        return $messageItems
            ->merge($activityItems)
            ->sortBy(fn (array $item): string => ($item['occurred_at']?->format('U.u') ?? '0').'-'.str_pad((string) $item['sequence'], 10, '0', STR_PAD_LEFT))
            ->values();
    }

    private function ticketTimelineFilter(Request $request): string
    {
        $filter = $request->input('timeline_filter');

        return is_string($filter) && array_key_exists($filter, $this->ticketTimelineFilters())
            ? $filter
            : 'all';
    }

    /**
     * @return array<string, int|string>
     */
    private function ticketDetailReturnQuery(Request $request): array
    {
        $query = $this->ticketQueueReturnQuery($request);
        $timelineFilter = $this->ticketTimelineFilter($request);

        if ($timelineFilter !== 'all') {
            $query['timeline_filter'] = $timelineFilter;
        }

        return $query;
    }

    /**
     * @return array<string, string>
     */
    private function ticketTimelineFilters(): array
    {
        return [
            'all' => __('ticket_detail.timeline.filters.all'),
            'conversation' => __('ticket_detail.timeline.filters.conversation'),
            'internal_notes' => __('ticket_detail.timeline.filters.internal_notes'),
            'ticket_activity' => __('ticket_detail.timeline.filters.ticket_activity'),
        ];
    }

    /**
     * @param  Collection<int, array{type: string, label: string, actor: string, badge: string, body: string|null, occurred_at: CarbonInterface|null, sequence: int}>  $ticketTimeline
     * @return Collection<int, array{type: string, label: string, actor: string, badge: string, body: string|null, occurred_at: CarbonInterface|null, sequence: int}>
     */
    private function filteredTicketTimeline(Collection $ticketTimeline, string $filter): Collection
    {
        return match ($filter) {
            'conversation' => $ticketTimeline
                ->filter(fn (array $item): bool => in_array($item['type'], ['agent-message', 'visitor-message'], true))
                ->values(),
            'internal_notes' => $ticketTimeline
                ->where('type', 'internal-note')
                ->values(),
            'ticket_activity' => $ticketTimeline
                ->where('type', 'ticket-activity')
                ->values(),
            default => $ticketTimeline,
        };
    }

    private function ticketTimelineEmptyMessage(string $filter): string
    {
        return match ($filter) {
            'conversation' => __('ticket_detail.timeline.empty.conversation'),
            'internal_notes' => __('ticket_detail.timeline.empty.internal_notes'),
            'ticket_activity' => __('ticket_detail.timeline.empty.ticket_activity'),
            default => __('ticket_detail.timeline.empty.all'),
        };
    }

    private function ticketTimelineEmptyDescription(string $filter): string
    {
        return match ($filter) {
            'conversation' => __('ticket_detail.timeline.empty_detail.conversation'),
            'internal_notes' => __('ticket_detail.timeline.empty_detail.internal_notes'),
            'ticket_activity' => __('ticket_detail.timeline.empty_detail.ticket_activity'),
            default => __('ticket_detail.timeline.empty_detail.all'),
        };
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
                'label' => __('ticket_detail.timeline.summary.conversation'),
                'value' => trans_choice('ticket_detail.counts.items', $conversationCount, ['count' => $conversationCount]),
                'description' => __('ticket_detail.timeline.summary.conversation_detail'),
            ],
            [
                'label' => __('ticket_detail.timeline.summary.notes'),
                'value' => trans_choice('ticket_detail.counts.notes', $internalNoteCount, ['count' => $internalNoteCount]),
                'description' => __('ticket_detail.timeline.summary.notes_detail'),
            ],
            [
                'label' => __('ticket_detail.timeline.summary.activity'),
                'value' => trans_choice('ticket_detail.counts.updates', $ticketActivityCount, ['count' => $ticketActivityCount]),
                'description' => __('ticket_detail.timeline.summary.activity_detail'),
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

    private function jiraIssueProjectsForTicket(Ticket $ticket): Collection
    {
        return $this->issueProjectsForTicket($ticket, 'jira');
    }

    /**
     * @return array{
     *     label: string,
     *     tone: string,
     *     total: int,
     *     status_counts: Collection<int, array{key: string, label: string, count: int}>,
     *     latest_attempt: array{label: string, label_feedback: array<string, mixed>, body: string, body_feedback: array<string, mixed>, occurred_at: CarbonInterface|null},
     *     failures: Collection<int, array{provider: string, project_key: string, feedback: array<string, mixed>, occurred_at: CarbonInterface|null, retry: array{label: string, label_feedback: array<string, mixed>, route: string, site_external_issue_project_id: int}|null}>
     * }
     */
    private function ticketExternalIssueHealth(Ticket $ticket): array
    {
        $externalLinks = $ticket->externalLinks
            ->filter(fn ($externalLink): bool => (int) $externalLink->account_id === (int) $ticket->account_id
                && (int) $externalLink->ticket_id === (int) $ticket->id);

        $statusCounts = $externalLinks->countBy('sync_status');
        $statusItems = collect(ExternalIssueSyncStatus::options())
            ->map(fn (string $_label, string $status): array => [
                'key' => $status,
                'label' => __('ticket_detail.external.sync_statuses.'.$status),
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
                'provider' => $this->ticketExternalIssueProviderLabel($externalLink->provider),
                'project_key' => $externalLink->project_key,
                'feedback' => $this->ticketExternalIssueFeedback(
                    'ticket_detail.external.sync_failed',
                    $externalLink->provider,
                    ['project' => $externalLink->project_key],
                ),
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
                $failedCount > 0 || $failedEvents->isNotEmpty() => __('ticket_detail.external.health.attention'),
                $externalLinks->isEmpty() => __('ticket_detail.external.health.none'),
                $pendingCount > 0 => __('ticket_detail.external.health.pending'),
                default => __('ticket_detail.external.health.healthy'),
            },
            'tone' => match (true) {
                $failedCount > 0 || $failedEvents->isNotEmpty() => 'attention',
                $pendingCount > 0 || $externalLinks->isEmpty() => 'manual',
                default => 'ready',
            },
            'total' => $externalLinks->count(),
            'status_counts' => $statusItems,
            'latest_attempt' => TicketExternalIssueAttempt::latestForTicket($ticket, $externalLinks),
            'failures' => $failures,
        ];
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
     * @return array{provider: string, project_key: string, feedback: array<string, mixed>, occurred_at: CarbonInterface|null, retry: array{label: string, label_feedback: array<string, mixed>, route: string, site_external_issue_project_id: int}|null}
     */
    private function externalIssueFailureItem(Ticket $ticket, AuditEvent $event): array
    {
        $provider = data_get($event->metadata, 'provider');
        $projectValue = data_get($event->metadata, 'project_key');
        $projectKey = TicketExternalIssueAttempt::eventProjectKey($event);
        $hasProjectKey = is_string($projectValue) && trim($projectValue) !== '';

        return [
            'provider' => $this->ticketExternalIssueProviderLabel(is_string($provider) ? $provider : null),
            'project_key' => $projectKey,
            'feedback' => $this->ticketExternalIssueFeedback(
                'ticket_detail.external.sync_failed',
                is_string($provider) ? $provider : null,
                $hasProjectKey ? ['project' => $projectKey] : [],
                $hasProjectKey ? [] : ['project' => $projectKey],
            ),
            'occurred_at' => $event->occurred_at,
            'retry' => $this->externalIssueRetryAction(
                $ticket,
                is_string($provider) ? $provider : null,
                data_get($event->metadata, 'site_external_issue_project_id'),
            ),
        ];
    }

    /**
     * @return array{label: string, label_feedback: array<string, mixed>, route: string, site_external_issue_project_id: int}|null
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
            'label' => __('ticket_detail.external.retry', ['provider' => $this->ticketExternalIssueProviderLabel($provider)]),
            'label_feedback' => $this->ticketExternalIssueFeedback('ticket_detail.external.retry', $provider),
            'route' => route($routeName, $ticket),
            'site_external_issue_project_id' => $project->id,
        ];
    }

    private function externalIssueRetryRouteName(?string $provider): ?string
    {
        return match ($provider) {
            'github' => 'dashboard.tickets.external-issues.github.store',
            'gitlab' => 'dashboard.tickets.external-issues.gitlab.store',
            'jira' => 'dashboard.tickets.external-issues.jira.store',
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
     * @return array{
     *     label: string,
     *     tone: string,
     *     summary: string,
     *     detail: string,
     *     projects: Collection<int, array{provider_name: string, provider_name_is_authored: bool, provider_label: string, provider_is_brand: bool, project_key: string, state: array{label: string, detail: string, tone: string}}>
     * }
     */
    private function ticketExternalIssueHandoffReadiness(Ticket $ticket): array
    {
        $projects = $ticket->site->externalIssueProjects
            ->filter(fn (SiteExternalIssueProject $project): bool => (int) $project->account_id === (int) $ticket->account_id
                && (int) $project->site_id === (int) $ticket->site_id)
            ->values();
        $readyCount = $projects
            ->filter(fn (SiteExternalIssueProject $project): bool => $project->supportsIssueCreationHandoff())
            ->count();
        $mappedCount = $projects->count();

        return [
            'label' => match (true) {
                $mappedCount === 0 => __('ticket_detail.external.handoff.not_configured'),
                $readyCount > 0 => __('ticket_detail.external.handoff.ready'),
                default => __('ticket_detail.external.handoff.setup'),
            },
            'tone' => match (true) {
                $readyCount > 0 => 'ready',
                $mappedCount === 0 => 'manual',
                default => 'attention',
            },
            'summary' => trans_choice('ticket_detail.counts.handoff_projects', $readyCount, ['count' => $readyCount]),
            'detail' => match (true) {
                $mappedCount === 0 => __('ticket_detail.external.handoff.map'),
                $readyCount > 0 => __('ticket_detail.external.handoff.create'),
                default => __('ticket_detail.external.handoff.manual'),
            },
            'projects' => $projects
                ->map(fn (SiteExternalIssueProject $project): array => [
                    'provider_name' => $project->providerConnection?->name ?? __('ticket_detail.common.external_tracker'),
                    'provider_name_is_authored' => $project->providerConnection?->name !== null,
                    'provider_label' => $this->ticketExternalIssueProviderLabel($project->providerConnection?->provider),
                    'provider_is_brand' => $this->ticketExternalIssueProviderIsBrand($project->providerConnection?->provider),
                    'project_key' => $project->project_key,
                    'state' => $this->ticketExternalIssueProjectState($project),
                ]),
        ];
    }

    /**
     * Translate model state at the request boundary. The model itself remains
     * English because it is also safe to call from jobs and console commands.
     *
     * @return array{label: string, detail: string, tone: string}
     */
    private function ticketExternalIssueProjectState(SiteExternalIssueProject $project): array
    {
        if (! $project->providerConnection?->is_enabled) {
            return [
                'label' => __('ticket_detail.external.handoff.blocked'),
                'detail' => __('ticket_detail.external.handoff.disabled'),
                'tone' => 'attention',
            ];
        }

        if (! $project->hasSupportedIssueCreationProvider()) {
            return [
                'label' => __('ticket_detail.external.handoff.link_only'),
                'detail' => __('ticket_detail.external.handoff.unsupported'),
                'tone' => 'manual',
            ];
        }

        if ($project->supportsIssueCreationHandoff()) {
            return [
                'label' => __('ticket_detail.external.handoff.handoff_ready'),
                'detail' => __('ticket_detail.external.handoff.can_create'),
                'tone' => 'ready',
            ];
        }

        return [
            'label' => __('ticket_detail.external.handoff.link_only'),
            'detail' => __('ticket_detail.external.handoff.not_enabled'),
            'tone' => 'manual',
        ];
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
            'anonymous_id' => $requester?->anonymous_id ?? __('ticket_detail.common.not_linked'),
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
    private function priorVisitorTickets(Ticket $ticket, bool $canViewLinkedConversation): Collection
    {
        if (! $ticket->requester_id) {
            return collect();
        }

        $relations = ['assignee'];

        if ($canViewLinkedConversation) {
            $relations[] = 'conversation';
        }

        return Ticket::query()
            ->with($relations)
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
            'ticket.unheld',
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
            'ticket.external_comment_posted',
            'ticket.external_comment_failed',
            'ticket.external_comment_received',
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
            'ticket.unheld',
            'ticket.assignee_updated',
            'ticket.escalated',
            'ticket.label_added',
            'ticket.label_removed',
            'ticket.note_added',
            'ticket.external_link_created',
            'ticket.external_issue_created',
            'ticket.external_link_removed',
            'ticket.external_sync_failed',
            'ticket.external_comment_posted',
            'ticket.external_comment_failed',
            'ticket.external_comment_received',
            'ticket.visitor_replied',
        ];
    }

    private function ticketActivityLabel(object $activity): string
    {
        return match ($activity->action) {
            'ticket.created' => data_get($activity->metadata, 'source') === 'conversation' && data_get($activity->metadata, 'support_code')
                ? ''
                : __('ticket_detail.activity.created'),
            'ticket.closed' => __('ticket_detail.activity.closed'),
            'ticket.pending' => __('ticket_detail.activity.pending'),
            'ticket.reopened' => __('ticket_detail.activity.reopened'),
            'ticket.unheld' => __('ticket_detail.activity.unheld'),
            'ticket.visitor_replied' => __('ticket_detail.activity.visitor_replied'),
            'ticket.label_added', 'ticket.label_removed' => '',
            'ticket.note_added' => __('ticket_detail.activity.note'),
            'ticket.reply_sent' => __('ticket_detail.activity.reply_sent'),
            'ticket.external_link_created',
            'ticket.external_issue_created',
            'ticket.external_link_removed',
            'ticket.external_sync_failed',
            'ticket.external_comment_posted',
            'ticket.external_comment_failed',
            'ticket.external_comment_received' => '',
            'ticket.assignee_updated', 'ticket.escalated' => '',
            'ticket.updated' => $this->ticketUpdatedLabel(data_get($activity->metadata, 'changes', [])),
            default => __('ticket_detail.activity.unknown', [
                'action' => ucfirst(str_replace(['ticket.', '_'], ['', ' '], $activity->action)),
            ]),
        };
    }

    /**
     * Keep account-authored names and external identifiers structured until
     * Blade can mark each one as unknown-language inside localized chrome.
     *
     * @return array{key: string, parameters: array<string, string>, localized_parameters: array<string, string>}|null
     */
    private function ticketActivityLabelFeedback(object $activity, bool $canViewLinkedConversation): ?array
    {
        $providerValue = data_get($activity->metadata, 'provider');
        $provider = is_string($providerValue) ? $providerValue : null;
        $reference = data_get($activity->metadata, 'external_key') ?? data_get($activity->metadata, 'external_id');

        if ($canViewLinkedConversation
            && $activity->action === 'ticket.created'
            && data_get($activity->metadata, 'source') === 'conversation'
            && filled(data_get($activity->metadata, 'support_code'))) {
            return $this->translatedFeedback(
                'ticket_detail.activity.created_from',
                ['code' => (string) data_get($activity->metadata, 'support_code')],
            );
        }

        if (in_array($activity->action, [
            'ticket.external_link_created',
            'ticket.external_issue_created',
            'ticket.external_link_removed',
            'ticket.external_sync_failed',
            'ticket.external_comment_posted',
            'ticket.external_comment_failed',
            'ticket.external_comment_received',
        ], true)) {
            $key = match ($activity->action) {
                'ticket.external_link_created' => filled($reference)
                    ? 'ticket_detail.activity.external_link_added_detail'
                    : 'ticket_detail.activity.external_link_added',
                'ticket.external_issue_created' => filled($reference)
                    ? 'ticket_detail.activity.external_issue_created_detail'
                    : 'ticket_detail.activity.external_issue_created',
                'ticket.external_link_removed' => filled($reference)
                    ? 'ticket_detail.activity.external_link_removed_detail'
                    : 'ticket_detail.activity.external_link_removed',
                'ticket.external_sync_failed' => 'ticket_detail.activity.external_sync_failed_detail',
                'ticket.external_comment_posted' => filled($reference)
                    ? 'ticket_detail.activity.external_comment_posted'
                    : 'ticket_detail.activity.external_comment_posted_bare',
                'ticket.external_comment_failed' => 'ticket_detail.activity.external_comment_failed',
                default => filled(data_get($activity->metadata, 'author'))
                    ? 'ticket_detail.activity.external_comment_received_from'
                    : 'ticket_detail.activity.external_comment_received',
            };

            $parameters = [];

            if (filled($reference) && $activity->action !== 'ticket.external_comment_received') {
                $parameters['reference'] = (string) $reference;
            }

            if ($activity->action === 'ticket.external_comment_received'
                && filled(data_get($activity->metadata, 'author'))) {
                $parameters['author'] = (string) data_get($activity->metadata, 'author');
            }

            return $this->ticketExternalIssueFeedback(
                $key,
                $provider,
                $parameters,
            );
        }

        if (in_array($activity->action, ['ticket.assignee_updated', 'ticket.escalated'], true)) {
            $old = data_get($activity->metadata, 'old_assignee_name');
            $new = $activity->action === 'ticket.escalated'
                ? data_get($activity->metadata, 'target_agent_name') ?? data_get($activity->metadata, 'new_assignee_name')
                : data_get($activity->metadata, 'new_assignee_name');
            $parameters = [];
            $localizedParameters = [];

            foreach (['old' => $old, 'new' => $new] as $key => $value) {
                if (filled($value)) {
                    $parameters[$key] = (string) $value;
                } else {
                    $localizedParameters[$key] = __('ticket_detail.common.unassigned');
                }
            }

            return $this->translatedFeedback(
                $activity->action === 'ticket.escalated'
                    ? 'ticket_detail.activity.escalated'
                    : 'ticket_detail.activity.assignee_changed',
                $parameters,
                $localizedParameters,
            );
        }

        return null;
    }

    /**
     * @param  array<string, string>  $parameters
     * @param  array<string, string>  $localizedParameters
     * @return array{key: string, parameters: array<string, string>, localized_parameters: array<string, string>}
     */
    private function translatedFeedback(string $key, array $parameters = [], array $localizedParameters = []): array
    {
        return [
            'key' => $key,
            'parameters' => $parameters,
            'localized_parameters' => $localizedParameters,
        ];
    }

    private function ticketActivityActor(object $activity): string
    {
        if ($activity->actor_type === Visitor::class) {
            return __('ticket_detail.common.visitor');
        }

        if ($activity->actor_type === ApiToken::class) {
            return __('ticket_detail.common.integration_actor', [
                'name' => $activity->actor?->name ?? __('ticket_detail.common.integration'),
            ]);
        }

        return $activity->actor?->name ?? __('ticket_detail.common.system');
    }

    private function ticketActivityActorIsAuthored(object $activity): bool
    {
        return ! in_array($activity->actor_type, [Visitor::class, ApiToken::class], true)
            && $activity->actor?->name !== null;
    }

    private function ticketTimelineBody(object $activity): ?string
    {
        return match ($activity->action) {
            'ticket.note_added' => data_get($activity->metadata, 'body'),
            'ticket.external_comment_received' => data_get($activity->metadata, 'body'),
            'ticket.pending' => data_get($activity->metadata, 'pending_note'),
            'ticket.closed' => data_get($activity->metadata, 'resolution_note'),
            'ticket.reopened' => data_get($activity->metadata, 'reopen_note'),
            'ticket.unheld' => data_get($activity->metadata, 'reopen_note'),
            'ticket.escalated' => data_get($activity->metadata, 'reason'),
            default => null,
        };
    }

    /**
     * Subject values are authored content, not dashboard chrome. Keep them
     * structured until Blade can give each value its own unknown-language
     * boundary inside the translated sentence.
     *
     * @return array{old: string, new: string}|null
     */
    private function ticketActivitySubjectChange(object $activity): ?array
    {
        if ($activity->action !== 'ticket.updated') {
            return null;
        }

        $change = data_get($activity->metadata, 'changes.subject');

        if (! is_array($change)) {
            return null;
        }

        return [
            'old' => (string) data_get($change, 'old'),
            'new' => (string) data_get($change, 'new'),
        ];
    }

    /**
     * Label names are account-authored content. Keep the name structured until
     * Blade can mark it as unknown-language inside the translated sentence.
     *
     * @return array{action: string, name: string}|null
     */
    private function ticketActivityLabelChange(object $activity): ?array
    {
        $action = match ($activity->action) {
            'ticket.label_added' => 'added',
            'ticket.label_removed' => 'removed',
            default => null,
        };

        if ($action === null) {
            return null;
        }

        return [
            'action' => $action,
            'name' => (string) data_get($activity->metadata, 'label_name'),
        ];
    }

    private function ticketUpdatedLabel(array $changes): string
    {
        if ($changes === []) {
            return __('ticket_detail.activity.updated');
        }

        $otherChanges = collect($changes)->except('subject');

        if ($otherChanges->isEmpty()) {
            return '';
        }

        return $otherChanges
            ->map(function (array $change, string $field): string {
                if ($field === 'description') {
                    return __('ticket_detail.activity.description_updated');
                }

                if ($field === 'category') {
                    $old = data_get($change, 'old');
                    $new = data_get($change, 'new');

                    return __('ticket_detail.activity.category_changed', [
                        'old' => is_string($old) && array_key_exists($old, TicketCategory::options())
                            ? __('tickets.categories.'.$old)
                            : __('tickets.filters.category_uncategorized'),
                        'new' => is_string($new) && array_key_exists($new, TicketCategory::options())
                            ? __('tickets.categories.'.$new)
                            : __('tickets.filters.category_uncategorized'),
                    ]);
                }

                if ($field === 'priority') {
                    $old = (string) data_get($change, 'old');
                    $new = (string) data_get($change, 'new');

                    return __('ticket_detail.activity.priority_changed', [
                        'old' => array_key_exists($old, TicketPriority::options()) ? __('tickets.priorities.'.$old) : $old,
                        'new' => array_key_exists($new, TicketPriority::options()) ? __('tickets.priorities.'.$new) : $new,
                    ]);
                }

                return __('ticket_detail.activity.field_changed', [
                    'field' => ucfirst($field),
                    'old' => data_get($change, 'old'),
                    'new' => data_get($change, 'new'),
                ]);
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
            if (! array_key_exists($field, $validated)) {
                continue;
            }

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

    /**
     * Change a ticket's status under a row lock, and record it while held.
     *
     * The same shape `AgentConversationController::transitionStatus()` uses,
     * and for the same reasons -- which is the point: these guards decide what
     * the reports count, so the two halves cannot afford different concurrency
     * behaviour.
     *
     * Read-check-write on a request-bound model is not enough. Two agents
     * submitting at once both evaluate the status they loaded before either
     * save lands, so two closes both record `ticket.closed` and overwrite
     * `closed_at` -- and a concurrent close and reopen can leave the row closed
     * with only a reopen on record, which is a report contradicting the ticket
     * in front of you.
     *
     * @param  callable(string, Ticket): array<string, mixed>  $attributes  What to write, given the LOCKED previous status.
     * @param  callable(string, Ticket, User): void  $record  What to log, given the same.
     */
    private function transitionTicketStatus(Ticket $ticket, User $agent, callable $attributes, callable $record): void
    {
        DB::transaction(function () use ($ticket, $agent, $attributes, $record): void {
            [$agent, $locked] = $this->lockedTicketActor($agent, $ticket, 'updateStatus');
            $previousStatus = (string) $locked->status;

            // Written through the LOCKED instance, not the one this request
            // loaded before it waited. Eloquent diffs against the attributes it
            // originally read, so a reopen that loaded "open" and then queued
            // behind a close would find "open" unchanged, omit status from the
            // update, and leave the row closed while recording a reopen.
            $target = $locked;

            $target->forceFill($attributes($previousStatus, $target))->save();
            $statusChanged = $target->wasChanged(['status', 'closed_at']);

            // Keep the caller's instance honest about what is now stored.
            $ticket->setRawAttributes($target->getAttributes(), true);

            // Recorded while the lock is still held. Committing the status and
            // logging afterwards lets the next writer take the lock and insert
            // its event first -- a reopen before the close that preceded it,
            // for a ticket that ended up open.
            $record($previousStatus, $target, $agent);

            if ($statusChanged) {
                event(new TicketUpdated($target));
                $ticket->setRawAttributes($target->getAttributes(), true);
            }
        });
    }

    private function recordActivity(Ticket $ticket, User $agent, string $action, array $metadata = []): AuditEvent
    {
        return $ticket->auditEvents()->create([
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
