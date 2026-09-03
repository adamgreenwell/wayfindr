<?php

namespace App\Http\Controllers;

use App\Events\ConversationMessageCreated;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ExternalIssueProviderConnection;
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
use App\Support\DashboardLanguage;
use App\Support\ExternalIssueProvider;
use App\Support\ExternalIssues\ExternalIssueCommentFailed;
use App\Support\ExternalIssues\ExternalIssueExportPreview;
use App\Support\ExternalIssues\GitHubIssueCommenter;
use App\Support\ExternalIssues\GitLabIssueCommenter;
use App\Support\ExternalIssues\InboundCommentSync;
use App\Support\ExternalIssues\IssueCommenter;
use App\Support\ExternalIssues\JiraIssueCommenter;
use App\Support\ExternalIssueSyncStatus;
use App\Support\ReplyTemplateOptions;
use App\Support\TicketCategory;
use App\Support\TicketExternalIssueAttempt;
use App\Support\TicketPriority;
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

    public function show(Request $request, Ticket $ticket, VisitorContextSanitizer $visitorContextSanitizer, ReplyTemplateOptions $replyTemplateOptions, ExternalIssueExportPreview $externalIssueExportPreview): View
    {
        $agent = $request->user();

        $this->authorizeTicketAbility($agent, 'view', $ticket);
        $this->markTicketAssignmentNotificationsRead($agent, $ticket);
        $ticket->loadMissing('site');
        $ticket->load([
            'assignee',
            'conversation.latestAgentMessage',
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
        $ticketDetailReturnQuery = $this->ticketDetailReturnQuery($request);
        $ticketTimelineFilter = $this->ticketTimelineFilter($request);
        $fullTicketTimeline = $this->ticketTimeline($ticket);
        $ticketTimeline = $this->filteredTicketTimeline($fullTicketTimeline, $ticketTimelineFilter);

        return view('agent.tickets.show', [
            'account' => $agent->account()->firstOrFail(),
            'accountAgents' => $this->supportAgentsForSite($ticket->site),
            'agent' => $agent,
            'canPostNoteToExternalIssue' => $this->commentableExternalLinks($ticket)->isNotEmpty(),
            'externalIssueProviders' => ExternalIssueProvider::options(),
            'externalIssueSyncStatuses' => $this->translatedOptions('ticket_detail.external.sync_statuses', array_keys(ExternalIssueSyncStatus::options())),
            'externalIssueExportPreview' => $externalIssueExportPreview->forTicket($ticket),
            'githubIssueProjects' => $this->githubIssueProjectsForTicket($ticket),
            'gitlabIssueProjects' => $this->gitlabIssueProjectsForTicket($ticket),
            'jiraIssueProjects' => $this->jiraIssueProjectsForTicket($ticket),
            'latestTicketEscalation' => $ticket->latestRecentEscalationEvent(),
            'noteTemplates' => $this->noteTemplates(),
            'replyTemplates' => $replyTemplateOptions->forAgent($agent),
            'ticketDetailReturnQuery' => $ticketDetailReturnQuery,
            'ticketReturnLink' => $this->ticketReturnLink($ticketReturnQuery),
            'ticketReturnQuery' => $ticketReturnQuery,
            'ticketLabelOptions' => $agent->account->ticketLabels()
                ->orderBy('name')
                ->get(),
            'ticketActivity' => $this->visibleTicketActivity($ticket),
            'ticketCategories' => TicketCategory::options(),
            'ticketCategoryGuidance' => TicketCategory::options(),
            'ticketPriorities' => TicketPriority::options(),
            'ticketPriorityGuidance' => TicketPriority::guidanceOptions(),
            'ticket' => $ticket,
            'ticketArtifactCoverage' => $this->ticketArtifactCoverage($ticket),
            'ticketExternalIssueHandoffReadiness' => $this->ticketExternalIssueHandoffReadiness($ticket),
            'ticketExternalIssueHealth' => $this->ticketExternalIssueHealth($ticket),
            'visitorContext' => $this->visitorContext($ticket, $visitorContextSanitizer),
            'priorVisitorConversations' => $this->priorVisitorConversations($ticket),
            'priorVisitorTickets' => $this->priorVisitorTickets($ticket),
            'linkedConversationMessages' => $this->linkedConversationMessages($ticket),
            'linkedConversationSupportCode' => $ticket->conversation?->support_code,
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

        $this->recordActivity($ticket, $agent, 'ticket.note_added', $metadata);

        $status = 'tickets.flash.note_added';

        // Internal notes stay internal unless the agent explicitly opts to relay
        // this one to the linked external issue (conservative-by-default per the
        // external-integrations stance).
        if ($request->boolean('post_to_external')) {
            $relay = $this->relayNoteToExternalIssues($ticket, $agent, $body);

            if ($relay['failed'] > 0) {
                $status = 'tickets.flash.note_added_not_posted';
            } elseif ($relay['posted'] > 0) {
                $status = 'tickets.flash.note_added_posted';
            }
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

    /**
     * @return array{posted: int, failed: int}
     */
    private function relayNoteToExternalIssues(Ticket $ticket, User $agent, string $body): array
    {
        $posted = 0;
        $failed = 0;

        foreach ($this->commentableExternalLinks($ticket) as $target) {
            /** @var TicketExternalLink $link */
            $link = $target['link'];
            /** @var ExternalIssueProviderConnection $connection */
            $connection = $target['connection'];

            $commenter = $this->commenterFor($link->provider);

            if ($commenter === null) {
                continue;
            }

            try {
                $result = $commenter->comment($connection, $link, $body);

                $posted++;

                // Remember the comment we just created so the inbound webhook
                // does not echo our own comment back onto the ticket as a note.
                $this->markCommentSynced($link, $result['id'] ?? null);

                // The note body is already recorded on the note_added event; the
                // relay event stays content-free provenance.
                $this->recordActivity($ticket, $agent, 'ticket.external_comment_posted', [
                    'external_link_id' => $link->id,
                    'provider' => $link->provider,
                    'external_key' => $link->external_key,
                    'url' => $result['url'] ?? $link->url,
                ]);
            } catch (ExternalIssueCommentFailed $exception) {
                $failed++;

                $this->recordActivity($ticket, $agent, 'ticket.external_comment_failed', [
                    'external_link_id' => $link->id,
                    'provider' => $link->provider,
                    'status' => $exception->status(),
                    'message' => Str::limit($exception->getMessage(), 300),
                ]);
            }
        }

        return ['posted' => $posted, 'failed' => $failed];
    }

    private function commenterFor(string $provider): ?IssueCommenter
    {
        return match ($provider) {
            'github' => app(GitHubIssueCommenter::class),
            'gitlab' => app(GitLabIssueCommenter::class),
            'jira' => app(JiraIssueCommenter::class),
            default => null,
        };
    }

    private function markCommentSynced(TicketExternalLink $link, ?string $commentId): void
    {
        if ($commentId === null || trim($commentId) === '') {
            return;
        }

        app(InboundCommentSync::class)->remember($link, $commentId);
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

        return $this->redirectAfterUpdate($ticket, $request, 'tickets.flash.label_added');
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
            'priority' => ['required', Rule::in(TicketPriority::values())],
        ]);

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
        }

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
            fn (): array => ['status' => 'pending', 'closed_at' => null],
            function (string $previousStatus) use ($ticket, $agent, $metadata): void {

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
            // A ticket already closed keeps the moment it was actually closed.
            fn (string $previous, Ticket $locked): array => [
                'status' => 'closed',
                'closed_at' => $previous === 'closed' ? $locked->closed_at : now(),
            ],
            // Only a TRANSITION is an event -- the rule conversation lifecycle
            // already follows. A double-click, a retry, or a stale page submits
            // close twice; recording both writes consecutive closes with no
            // reopen between them, which makes one resolution contribute two
            // durations to the report and inflates every close count derived
            // from the log.
            function (string $previous) use ($ticket, $agent, $resolutionNote): void {
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
            fn (): array => ['status' => 'open', 'closed_at' => null],
            function (string $previousStatus) use ($ticket, $agent, $reopenNote): void {

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

        $ticket->loadMissing(['assignee', 'site']);
        $oldAssigneeId = $ticket->assignee_id;
        $oldAssigneeName = $ticket->assignee?->name;
        $newAssigneeId = $validated['assignee_id'] ?? null;
        $newAssignee = $newAssigneeId
            ? $agent->account->agents()->whereKey($newAssigneeId)->first()
            : null;

        if ($newAssignee && ! $ticket->site->supportsAgent($newAssignee)) {
            throw ValidationException::withMessages([
                'assignee_id' => __('tickets.errors.assignee_not_on_site'),
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

        $ticket->loadMissing(['assignee', 'site']);
        $oldAssigneeId = $ticket->assignee_id;
        $oldAssigneeName = $ticket->assignee?->name;
        $targetAgent = $agent->account->agents()
            ->whereKey($validated['target_agent_id'])
            ->first();

        if (! $targetAgent || ! $ticket->site->supportsAgent($targetAgent)) {
            throw ValidationException::withMessages([
                'target_agent_id' => __('tickets.errors.assignee_not_on_site'),
            ]);
        }

        if ($targetAgent->is($agent)) {
            throw ValidationException::withMessages([
                'target_agent_id' => __('tickets.errors.escalate_other_agent'),
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

        return $this->redirectAfterUpdate($ticket, $request, 'tickets.flash.escalated');
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
     * @return Collection<int, array{label: string, subject_change: array{old: string, new: string}|null, actor: string, body: string|null, occurred_at: CarbonInterface|null}>
     */
    private function visibleTicketActivity(Ticket $ticket): Collection
    {
        return $ticket->auditEvents()
            ->with('actor')
            ->whereIn('action', $this->visibleActivityActions())
            ->latest('occurred_at')
            ->latest('id')
            ->get()
            ->map(fn (AuditEvent $activity): array => [
                'label' => $this->ticketActivityLabel($activity),
                'subject_change' => $this->ticketActivitySubjectChange($activity),
                'actor' => $this->ticketActivityActor($activity),
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
            ->with(['sender', 'attachments'])
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
                'label' => $isAgentMessage ? __('ticket_detail.timeline.message.agent_reply') : __('ticket_detail.timeline.message.visitor_message'),
                'actor' => $isAgentMessage ? ($message->sender?->name ?? __('ticket_detail.common.agent')) : __('ticket_detail.common.visitor'),
                'badge' => $isAgentMessage ? __('ticket_detail.timeline.message.customer_visible') : __('ticket_detail.timeline.message.customer_message'),
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
                'subject_change' => $this->ticketActivitySubjectChange($activity),
                'actor' => $this->ticketActivityActor($activity),
                'badge' => match ($activity->action) {
                    'ticket.note_added' => __('ticket_detail.timeline.message.internal'),
                    'ticket.external_comment_received' => __('ticket_detail.timeline.message.from_provider', [
                        'provider' => ExternalIssueProvider::label(data_get($activity->metadata, 'provider')),
                    ]),
                    default => __('ticket_detail.timeline.message.ticket_activity'),
                },
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
     * @return array{provider: string, project_key: string, occurred_at: CarbonInterface|null, retry: array{label: string, route: string, site_external_issue_project_id: int}|null}
     */
    private function externalIssueFailureItem(Ticket $ticket, AuditEvent $event): array
    {
        $provider = data_get($event->metadata, 'provider');

        return [
            'provider' => ExternalIssueProvider::label(is_string($provider) ? $provider : null),
            'project_key' => TicketExternalIssueAttempt::eventProjectKey($event),
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
            'label' => __('ticket_detail.external.retry', ['provider' => ExternalIssueProvider::label($provider)]),
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
     *     projects: Collection<int, array{provider_name: string, provider_label: string, project_key: string, state: array{label: string, detail: string, tone: string}}>
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
                    'provider_label' => $project->providerLabel(),
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
        $provider = ExternalIssueProvider::label(data_get($activity->metadata, 'provider'));
        $reference = data_get($activity->metadata, 'external_key') ?? data_get($activity->metadata, 'external_id') ?? '';

        return match ($activity->action) {
            'ticket.created' => data_get($activity->metadata, 'source') === 'conversation' && data_get($activity->metadata, 'support_code')
                ? __('ticket_detail.activity.created_from', ['code' => data_get($activity->metadata, 'support_code')])
                : __('ticket_detail.activity.created'),
            'ticket.closed' => __('ticket_detail.activity.closed'),
            'ticket.pending' => __('ticket_detail.activity.pending'),
            'ticket.reopened' => __('ticket_detail.activity.reopened'),
            'ticket.unheld' => __('ticket_detail.activity.unheld'),
            'ticket.visitor_replied' => __('ticket_detail.activity.visitor_replied'),
            'ticket.label_added' => __('ticket_detail.activity.label_added', ['label' => data_get($activity->metadata, 'label_name')]),
            'ticket.label_removed' => __('ticket_detail.activity.label_removed', ['label' => data_get($activity->metadata, 'label_name')]),
            'ticket.note_added' => __('ticket_detail.activity.note'),
            'ticket.reply_sent' => __('ticket_detail.activity.reply_sent'),
            'ticket.external_link_created' => __('ticket_detail.activity.external_link_added_detail', compact('provider', 'reference')),
            'ticket.external_issue_created' => __('ticket_detail.activity.external_issue_created_detail', compact('provider', 'reference')),
            'ticket.external_link_removed' => __('ticket_detail.activity.external_link_removed_detail', compact('provider', 'reference')),
            'ticket.external_sync_failed' => __('ticket_detail.activity.external_sync_failed_detail', compact('provider')),
            'ticket.external_comment_posted' => __('ticket_detail.activity.external_comment_posted', compact('provider', 'reference')),
            'ticket.external_comment_failed' => __('ticket_detail.activity.external_comment_failed', compact('provider')),
            'ticket.external_comment_received' => filled(data_get($activity->metadata, 'author'))
                ? __('ticket_detail.activity.external_comment_received_from', [
                    'provider' => $provider,
                    'author' => data_get($activity->metadata, 'author'),
                ])
                : __('ticket_detail.activity.external_comment_received', compact('provider')),
            'ticket.assignee_updated' => __('ticket_detail.activity.assignee_changed', [
                'old' => data_get($activity->metadata, 'old_assignee_name') ?? __('ticket_detail.common.unassigned'),
                'new' => data_get($activity->metadata, 'new_assignee_name') ?? __('ticket_detail.common.unassigned'),
            ]),
            'ticket.escalated' => __('ticket_detail.activity.escalated', [
                'old' => data_get($activity->metadata, 'old_assignee_name') ?? __('ticket_detail.common.unassigned'),
                'new' => data_get($activity->metadata, 'target_agent_name') ?? data_get($activity->metadata, 'new_assignee_name') ?? __('ticket_detail.common.unassigned'),
            ]),
            'ticket.updated' => $this->ticketUpdatedLabel(data_get($activity->metadata, 'changes', [])),
            default => __('ticket_detail.activity.unknown', [
                'action' => ucfirst(str_replace(['ticket.', '_'], ['', ' '], $activity->action)),
            ]),
        };
    }

    private function ticketActivityActor(object $activity): string
    {
        if ($activity->actor_type === Visitor::class) {
            return __('ticket_detail.common.visitor');
        }

        return $activity->actor?->name ?? __('ticket_detail.common.system');
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
     * @param  callable(string): void  $record  What to log, given the same.
     */
    private function transitionTicketStatus(Ticket $ticket, callable $attributes, callable $record): void
    {
        DB::transaction(function () use ($ticket, $attributes, $record): void {
            $locked = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->first();
            $previousStatus = (string) ($locked?->status ?? $ticket->status);

            // Written through the LOCKED instance, not the one this request
            // loaded before it waited. Eloquent diffs against the attributes it
            // originally read, so a reopen that loaded "open" and then queued
            // behind a close would find "open" unchanged, omit status from the
            // update, and leave the row closed while recording a reopen.
            $target = $locked ?? $ticket;

            $target->forceFill($attributes($previousStatus, $target))->save();

            // Keep the caller's instance honest about what is now stored.
            $ticket->setRawAttributes($target->getAttributes(), true);

            // Recorded while the lock is still held. Committing the status and
            // logging afterwards lets the next writer take the lock and insert
            // its event first -- a reopen before the close that preceded it,
            // for a ticket that ended up open.
            $record($previousStatus);
        });
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
