<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use App\Enums\PlatformRole;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\AutomationRuleExecution;
use App\Models\BreakGlassGrant;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\ConversationBulkActionRun;
use App\Models\ConversationCopilotKnowledgeSuggestion;
use App\Models\ConversationCopilotReplyDraft;
use App\Models\ConversationCopilotSummary;
use App\Models\ConversationCopilotTicketSuggestion;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\ConversationRating;
use App\Models\ConversationReplyDelivery;
use App\Models\OutboundWebhookDelivery;
use App\Models\ProactiveMessageDelivery;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketBulkActionRun;
use App\Models\TicketExternalCommentDelivery;
use App\Models\TicketExternalLink;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorIdentityAlias;
use App\Models\VisitorNote;
use App\Support\Database\StableReadTransaction;
use App\Support\Zip\StoredZipWriter;
use ArrayObject;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonSerializable;
use Throwable;
use UnitEnum;

/**
 * Everything held about one visitor, as a ZIP (ADR 0026 §7).
 *
 * The read side of the erasure map: it selects through the same
 * VisitorFootprint, and VisitorEraser::COVERAGE says for every table it
 * reaches where the export puts it, or why it leaves it out. COLUMNS does the
 * same for every column of every table it exports, so a column added later is
 * left out, and fails the export's test, until someone decides.
 *
 * Every file describes one moment: the rows are read in one repeatable-read
 * transaction holding a shared lock on the site, which erasure takes
 * exclusively. The archive is built into a temporary file inside that window
 * and served after it, so the lock lasts as long as building takes, not as
 * long as the agent's connection does.
 */
final class VisitorExporter
{
    use DetectsConcurrencyErrors;
    use QueriesIdsInChunks;

    /** Comfortably inside the 4 GiB a ZIP file without ZIP64 can hold. */
    public const MAX_BYTES = 3_500_000_000;

    /**
     * Metadata keys that name an agent, an operator or a tracker's user, and
     * the role each is replaced with: their identity is data about them, not
     * about the person (§7). Where the same object records the kind of user
     * as `<prefix>_type`, that is the role instead: a cobrowse session ended
     * by the visitor says so. The export's test holds this list to every key
     * the code writes a user into.
     */
    public const IDENTITY_KEYS = [
        'old_assignee_id' => 'agent',
        'old_assignee_name' => 'agent',
        'new_assignee_id' => 'agent',
        'new_assignee_name' => 'agent',
        'target_agent_id' => 'agent',
        'target_agent_name' => 'agent',
        'requested_by_id' => 'agent',
        'requested_by_name' => 'agent',
        'triggered_by_user_id' => 'agent',
        'triggered_by_name' => 'agent',
        'assigned_by_name' => 'agent',
        'ended_by_id' => 'agent',
        'ended_by_name' => 'agent',
        'requester' => 'platform operator',
        'author' => 'issue tracker user',
    ];

    /** Columns whose JSON can carry IDENTITY_KEYS. */
    public const IDENTITY_COLUMNS = [
        'audit_events' => ['metadata'],
        'notifications' => ['data'],
        'cobrowse_sessions' => ['metadata'],
        'automation_rule_executions' => ['metadata'],
    ];

    private const ROLE = 'replaced by a role (§7): the agent\'s identity is data about the agent';

    private const COPILOT = [
        'id' => true, 'conversation_id' => true, 'requested_by_id' => self::ROLE, 'generation' => true, 'status' => true,
        'source_last_message_id' => true, 'source_message_count' => true, 'provider' => true, 'model' => true,
        'prompt_tokens' => true, 'completion_tokens' => true, 'failure_code' => true, 'requested_at' => true,
        'started_at' => true, 'completed_at' => true, 'created_at' => true, 'updated_at' => true,
    ];

    private const BULK_RUN = [
        'id' => 'the run\'s own bookkeeping', 'account_id' => 'the run\'s own bookkeeping',
        'triggered_by_user_id' => self::ROLE, 'action' => true,
        'value' => 'what the action set, the work item\'s own history', 'item_count' => 'the run\'s own bookkeeping',
        'changed_count' => 'the run\'s own bookkeeping', 'changes' => 'which items it changed, the work item\'s own history',
        'return_query' => 'replaced by search: the saved queue search, which is what can name them',
        'undone_at' => 'the run\'s own bookkeeping', 'undone_by_user_id' => self::ROLE,
        'undo_result' => 'the run\'s own bookkeeping', 'created_at' => true, 'updated_at' => 'the run\'s own bookkeeping',
        'item_ids' => 'which items it selected, the work item\'s own history',
    ];

    /**
     * Every column of every table the export reads: true when it is exported
     * as it is, or why it is not.
     *
     * @var array<string, array<string, true|string>>
     */
    public const COLUMNS = [
        'visitors' => [
            'id' => true, 'site_id' => true, 'external_id' => true, 'anonymous_id' => true, 'name' => true, 'email' => true,
            'metadata' => true, 'last_seen_at' => true, 'created_at' => true, 'updated_at' => true,
            'current_visit_started_at' => true, 'presence_only' => true, 'last_web_seen_at' => true,
        ],
        'visitor_identity_aliases' => [
            'id' => true, 'site_id' => true, 'visitor_id' => true, 'anonymous_id' => true, 'previous_visitor_ids' => true,
            'created_at' => true, 'updated_at' => true,
        ],
        'visitor_notes' => [
            'id' => true, 'account_id' => true, 'visitor_id' => true, 'author_id' => self::ROLE, 'body' => true,
            'created_at' => true, 'updated_at' => true,
        ],
        'conversations' => [
            'id' => true, 'site_id' => true, 'visitor_id' => true, 'assigned_agent_id' => self::ROLE, 'support_code' => true,
            'status' => true, 'subject' => true, 'metadata' => true, 'last_message_at' => true, 'closed_at' => true,
            'created_at' => true, 'updated_at' => true, 'priority' => true, 'support_wait_started_at' => true,
            'support_wait_elapsed_seconds' => true, 'support_wait_last_counted_at' => true, 'owner_session_id' => true,
        ],
        'conversation_messages' => [
            'id' => true, 'conversation_id' => true, 'sender_type' => self::ROLE, 'sender_id' => self::ROLE, 'type' => true,
            'body' => true, 'metadata' => true, 'seen_at' => true, 'created_at' => true, 'updated_at' => true,
            'email_message_id' => true,
        ],
        'conversation_message_attachments' => [
            'id' => true, 'conversation_message_id' => true, 'account_id' => true, 'site_id' => true,
            'storage_disk' => 'where this installation keeps the file, which is in attachments/',
            'storage_key' => 'where this installation keeps the file, which is in attachments/',
            'original_filename' => true, 'mime_type' => true, 'size_bytes' => true, 'checksum' => true, 'status' => true,
            'scan_status' => true, 'scanned_at' => true, 'created_at' => true, 'updated_at' => true,
            'conversation_id' => true, 'uploaded_by_type' => self::ROLE, 'uploaded_by_id' => self::ROLE,
        ],
        'conversation_reply_deliveries' => [
            'id' => true, 'conversation_message_id' => true, 'recipient' => true, 'message_id' => true, 'in_reply_to' => true,
            'attempts' => true, 'last_attempted_at' => true, 'accepted_at' => true, 'failed_at' => true,
            'created_at' => true, 'updated_at' => true,
        ],
        'conversation_ratings' => [
            'id' => true, 'conversation_id' => true, 'site_id' => true, 'score' => true, 'comment' => true, 'rated_at' => true,
            'episode_closed_at' => true, 'episode_event_id' => true, 'created_at' => true, 'updated_at' => true,
        ],
        'cobrowse_sessions' => [
            'id' => true, 'conversation_id' => true, 'site_id' => true, 'visitor_id' => true, 'requested_by_id' => self::ROLE,
            'status' => true, 'metadata' => true, 'consented_at' => true, 'ended_at' => true, 'created_at' => true,
            'updated_at' => true,
        ],
        'conversation_copilot_summaries' => self::COPILOT + ['summary' => true],
        'conversation_copilot_reply_drafts' => self::COPILOT + ['draft' => true],
        'conversation_copilot_ticket_suggestions' => self::COPILOT + ['title' => true, 'priority' => true, 'label_ids' => true],
        'conversation_copilot_knowledge_suggestions' => self::COPILOT + ['article_ids' => true],
        'proactive_message_deliveries' => [
            'id' => true, 'site_id' => true, 'proactive_message_rule_id' => true, 'visitor_id' => true, 'conversation_id' => true,
            'public_id' => true, 'rule_public_id' => true,
            'visitor_key' => 'a keyed hash of their browser ID, meaningless outside this installation',
            'claim_key' => 'delivery bookkeeping: which page load claimed the message',
            'message' => true, 'claimed_at' => true, 'expires_at' => true, 'shown_at' => true, 'engaged_at' => true,
            'dismissed_at' => true, 'created_at' => true, 'updated_at' => true,
        ],
        'tickets' => [
            'id' => true, 'account_id' => true, 'site_id' => true, 'conversation_id' => true, 'requester_id' => true,
            'assignee_id' => self::ROLE, 'status' => true, 'priority' => true, 'subject' => true, 'description' => true,
            'metadata' => true, 'closed_at' => true, 'created_at' => true, 'updated_at' => true, 'category' => true,
        ],
        'ticket_external_links' => [
            'id' => true, 'account_id' => true, 'site_id' => true, 'ticket_id' => true, 'provider' => true,
            'project_key' => true, 'external_id' => true, 'external_key' => true, 'url' => true, 'sync_status' => true,
            'last_synced_at' => true, 'metadata' => true, 'created_at' => true, 'updated_at' => true,
        ],
        'ticket_external_comment_deliveries' => [
            'id' => true, 'public_id' => true, 'account_id' => true, 'site_id' => true, 'ticket_id' => true,
            'ticket_external_link_id' => true, 'provider_connection_id' => true, 'actor_id' => self::ROLE,
            'note_audit_event_id' => true, 'body' => true, 'attempts' => true, 'started_at' => true, 'accepted_at' => true,
            'delivered_at' => true, 'failed_at' => true, 'remote_comment_id' => true, 'remote_url' => true,
            'last_error' => true, 'created_at' => true, 'updated_at' => true,
        ],
        'notifications' => [
            'id' => true, 'type' => true,
            'notifiable_type' => 'replaced by recipient: the agent the alert went to, by role (§7)',
            'notifiable_id' => 'replaced by recipient: the agent the alert went to, by role (§7)',
            'data' => true, 'read_at' => true, 'created_at' => true, 'updated_at' => true,
            'agent_alerted_at' => 'alert delivery bookkeeping', 'agent_alert_version' => 'alert delivery bookkeeping',
            'agent_alert_broadcast_claim_version' => 'alert delivery bookkeeping',
            'agent_alert_broadcast_pending_version' => 'alert delivery bookkeeping',
            'agent_alert_fingerprint' => 'alert delivery bookkeeping',
            'agent_alert_realtime_received_version' => 'alert delivery bookkeeping',
        ],
        'audit_events' => [
            'id' => true, 'account_id' => true, 'site_id' => true, 'actor_type' => self::ROLE, 'actor_id' => self::ROLE,
            'subject_type' => true, 'subject_id' => true, 'action' => true, 'metadata' => true, 'occurred_at' => true,
            'created_at' => true, 'updated_at' => true,
        ],
        'outbound_webhook_deliveries' => [
            'id' => 'the delivery\'s identifiers (§7)', 'public_id' => 'the delivery\'s identifiers (§7)',
            'outbound_webhook_endpoint_id' => 'the delivery\'s identifiers (§7)', 'site_id' => true, 'event' => true,
            'sequence' => 'the delivery\'s identifiers (§7)',
            'payload' => 'identifiers only (ADR 0020); the response sample is what can quote them',
            'attempts' => true, 'last_attempted_at' => true, 'response_status' => true, 'response_body' => true,
            'last_error' => true, 'delivered_at' => true, 'failed_at' => true, 'cancelled_at' => true,
            'created_at' => true, 'updated_at' => true,
        ],
        'automation_rule_executions' => [
            'id' => true, 'account_id' => true, 'automation_rule_id' => true, 'subject_type' => true, 'subject_id' => true,
            'rule_name' => true, 'event' => true, 'status' => true,
            'conditions' => 'the run\'s copy of its rule (§7)', 'actions' => 'the run\'s copy of its rule (§7)',
            'action_results' => 'what the rule\'s actions did to the work item, naming agents by ID',
            'metadata' => true, 'error_message' => true, 'started_at' => true, 'completed_at' => true,
            'created_at' => true, 'updated_at' => true, 'automation_macro_id' => true, 'triggered_by_user_id' => self::ROLE,
        ],
        'conversation_bulk_action_runs' => self::BULK_RUN,
        'ticket_bulk_action_runs' => self::BULK_RUN,
        'break_glass_grants' => [
            'id' => true, 'account_id' => true, 'scope_type' => true, 'conversation_id' => true, 'site_id' => true,
            'requester_id' => self::ROLE, 'reason' => true, 'status' => true, 'approver_id' => self::ROLE,
            'self_approved' => true, 'requested_minutes' => true, 'approved_at' => true, 'expires_at' => true,
            'closed_at' => true, 'created_at' => true, 'updated_at' => true,
        ],
        'failed_jobs' => [
            'id' => 'the failed-job store\'s own bookkeeping', 'uuid' => 'the failed-job store\'s own bookkeeping',
            'connection' => 'the failed-job store\'s own bookkeeping', 'queue' => 'the failed-job store\'s own bookkeeping',
            'payload' => 'replaced by job, its name: the rest is the job\'s serialized arguments',
            'exception' => 'replaced by error, its first line: the message that quotes them; the stack trace below it is the installation\'s own code (§7)',
            'failed_at' => true,
        ],
    ];

    /**
     * Keys the export adds to a table's rows, besides its exported columns.
     *
     * @var array<string, list<string>>
     */
    public const DERIVED = [
        'visitor_notes' => ['author'],
        'conversations' => ['assigned_agent'],
        'conversation_messages' => ['sender'],
        'conversation_message_attachments' => ['uploaded_by', 'file'],
        'cobrowse_sessions' => ['requested_by'],
        'conversation_copilot_summaries' => ['requested_by'],
        'conversation_copilot_reply_drafts' => ['requested_by'],
        'conversation_copilot_ticket_suggestions' => ['requested_by'],
        'conversation_copilot_knowledge_suggestions' => ['requested_by'],
        'tickets' => ['assignee'],
        'ticket_external_comment_deliveries' => ['actor'],
        'notifications' => ['recipient'],
        'audit_events' => ['actor', 'grant'],
        'automation_rule_executions' => ['triggered_by'],
        'conversation_bulk_action_runs' => ['triggered_by', 'search'],
        'ticket_bulk_action_runs' => ['triggered_by', 'search'],
        'break_glass_grants' => ['requester', 'approver'],
        'failed_jobs' => ['job', 'error'],
    ];

    /** @var array<int, string> */
    private array $userRoles = [];

    public function __construct(private readonly VisitorFootprint $footprint) {}

    /**
     * Build the archive into a temporary file the caller serves and removes.
     *
     * @return array{path: string, filename: string, counts: array<string, int>, pruned: list<string>, withheld: list<string>}
     *
     * @throws VisitorExportRefused
     */
    public function export(User $actor, Visitor $visitor): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'wayfindr-export-');
        $visitorId = (int) $visitor->id;

        try {
            // Isolation is set before the first query, which is the shared
            // lock on the site: erasure holds it exclusively, so an erasure
            // under way finishes first. A site row another transaction changed
            // while the lock was awaited fails the snapshot, so it is taken
            // again.
            for ($attempt = 1; ; $attempt++) {
                try {
                    $built = StableReadTransaction::run(DB::connection(), fn (): array => $this->build($visitor, $path));

                    break;
                } catch (Throwable $e) {
                    if ($attempt >= 3 || ! $this->causedByConcurrencyError($e)) {
                        throw $e;
                    }
                }
            }

            // The snapshot can predate an erasure, or a merge into another
            // contact, that committed while the site lock was awaited: the
            // archive would describe someone who is no longer here.
            if (! Visitor::query()->whereKey($visitorId)->exists()) {
                throw new VisitorExportRefused(VisitorExportRefused::GONE);
            }

            // Counts only, like the erasure's: the record that an export
            // happened must not become a copy of what it held.
            AuditEvent::query()->create([
                'account_id' => $actor->account_id,
                'site_id' => $built['site_id'],
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->id,
                'subject_type' => $visitor->getMorphClass(),
                'subject_id' => $visitorId,
                'action' => 'visitor.exported',
                'metadata' => ['exported' => $built['counts']],
                'occurred_at' => now(),
            ]);

            return [
                'path' => $path,
                'filename' => sprintf('wayfindr-contact-%d-%s.zip', $visitorId, now()->format('Ymd-His')),
                'counts' => $built['counts'],
                'pruned' => $built['pruned'],
                'withheld' => $built['withheld'],
            ];
        } catch (Throwable $e) {
            @unlink($path);

            throw $e;
        }
    }

    /**
     * @return array{site_id: int, counts: array<string, int>, pruned: list<string>, withheld: list<string>}
     */
    private function build(Visitor $visitor, string $path): array
    {
        $site = Site::query()->whereKey($visitor->site_id)->sharedLock()->first();
        $visitor = $site === null ? null : Visitor::query()->whereKey($visitor->id)->where('site_id', $site->id)->first();

        if (! $site instanceof Site || ! $visitor instanceof Visitor) {
            throw new VisitorExportRefused(VisitorExportRefused::GONE);
        }

        $scope = $this->footprint->scope($visitor);
        $this->assertFits($scope);

        $zip = new StoredZipWriter($path);

        try {
            [$files, $pruned, $withheld] = $this->writeAttachments($zip, $visitor, $scope);
            $counts = [
                ...$this->writeVisitor($zip, $visitor),
                ...$this->writeConversations($zip, $visitor, $scope, $files),
                ...$this->writeTickets($zip, $scope),
                ...$this->writeProactive($zip, $site, $visitor, $scope),
                ...$this->writeAlerts($zip, $site, $scope),
                ...$this->writeAudit($zip, $site, $visitor, $scope),
                ...$this->writeIncidental($zip, $site, $visitor, $scope),
                ...$this->writeBreakGlass($zip, $site, $scope),
                'attachments' => count($files),
                'attachments_pruned' => count($pruned),
                'attachments_withheld' => count($withheld),
            ];
            $zip->addFromString('README.txt', $this->readme($counts, $pruned, $withheld));
            $zip->close();
        } catch (Throwable $e) {
            $zip->abandon();

            throw $e;
        }

        return ['site_id' => (int) $site->id, 'counts' => $counts, 'pruned' => array_values($pruned), 'withheld' => array_values($withheld)];
    }

    /**
     * Refused before anything is written, rather than half way through.
     *
     * @param  array{conversation_ids: list<int>, attachment_ids: list<int>, ticket_ids: list<int>}  $scope
     */
    private function assertFits(array $scope): void
    {
        $entries = count($scope['conversation_ids']) + count($scope['attachment_ids']) + count($scope['ticket_ids']) + 16;
        $bytes = 0;

        foreach (array_chunk($scope['attachment_ids'], 500) as $chunk) {
            $bytes += (int) ConversationMessageAttachment::query()->whereIn('id', $chunk)->sum('size_bytes');
        }

        if ($entries > StoredZipWriter::MAX_ENTRIES || $bytes > self::MAX_BYTES) {
            throw new VisitorExportRefused(VisitorExportRefused::TOO_LARGE);
        }
    }

    /**
     * The binaries, read inside the snapshot. One that retention or a
     * cancelled upload removed after the snapshot was taken is listed as
     * pruned, not silently left out. One the download path would not serve,
     * because the malware scanner holds it or it never finished uploading,
     * is not served here either: its details are in its conversation's file,
     * and README.txt lists it as withheld.
     *
     * @param  array{attachment_ids: list<int>}  $scope
     * @return array{0: array<int, string>, 1: array<int, string>, 2: array<int, string>}
     */
    private function writeAttachments(StoredZipWriter $zip, Visitor $visitor, array $scope): array
    {
        $files = [];
        $pruned = [];
        $withheld = [];

        foreach (array_chunk($scope['attachment_ids'], 500) as $chunk) {
            foreach (ConversationMessageAttachment::query()->whereIn('id', $chunk)->orderBy('id')->get() as $attachment) {
                if (! self::belongsToThem($attachment, $visitor)) {
                    continue;
                }

                $name = 'attachments/'.$attachment->id.'-'.self::safeName((string) $attachment->original_filename, 'file');

                if (! $attachment->isReady()) {
                    $withheld[(int) $attachment->id] = $name.' ('.$attachment->getRawOriginal('status').')';

                    continue;
                }

                $stream = null;

                try {
                    $stream = Storage::disk((string) $attachment->storage_disk)->readStream((string) $attachment->storage_key);
                } catch (Throwable $e) {
                    report($e);
                }

                if (! is_resource($stream)) {
                    $pruned[(int) $attachment->id] = $name;

                    continue;
                }

                try {
                    $zip->addFromStream($name, $stream);
                } finally {
                    fclose($stream);
                }

                $files[(int) $attachment->id] = $name;
            }
        }

        return [$files, $pruned, $withheld];
    }

    /** @return array<string, int> */
    private function writeVisitor(StoredZipWriter $zip, Visitor $visitor): array
    {
        $notes = 0;

        $this->json($zip, 'visitor.json', [
            'visitor' => $this->row('visitors', $visitor),
            'browser_ids' => $this->rows(VisitorIdentityAlias::query()->where('visitor_id', $visitor->id), 'visitor_identity_aliases'),
            'notes' => $this->rows(VisitorNote::query()->where('visitor_id', $visitor->id), 'visitor_notes', function (VisitorNote $note, array $row) use (&$notes): array {
                $notes++;

                return [...$row, 'author' => $this->userRole($note->author_id)];
            }),
        ]);

        return ['notes' => $notes];
    }

    /**
     * One file per conversation. A cobrowse session belongs to the
     * conversation it was started from; one of the person's on a
     * conversation that is not theirs goes in cobrowse.json.
     *
     * @param  array{conversation_ids: list<int>, cobrowse_ids: list<int>}  $scope
     * @param  array<int, string>  $files
     * @return array<string, int>
     */
    private function writeConversations(StoredZipWriter $zip, Visitor $visitor, array $scope, array $files): array
    {
        $visitorId = (int) $visitor->id;
        $messages = 0;
        $names = [];

        foreach (array_chunk($scope['conversation_ids'], 500) as $chunk) {
            foreach (Conversation::query()->whereIn('id', $chunk)->orderBy('id')->get() as $conversation) {
                $id = (int) $conversation->id;
                $name = self::safeName((string) $conversation->support_code, 'conversation-'.$id);
                $name = isset($names[$name]) ? 'conversation-'.$id : $name;
                $names[$name] = true;

                $this->json($zip, "conversations/{$name}.json", [
                    'conversation' => [...$this->row('conversations', $conversation), 'assigned_agent' => $this->userRole($conversation->assigned_agent_id)],
                    'messages' => $this->rows(ConversationMessage::query()->where('conversation_id', $id), 'conversation_messages', function (ConversationMessage $message, array $row) use ($visitorId, &$messages): array {
                        $messages++;

                        return [...$row, 'sender' => $this->role($message->sender_type, $message->sender_id, $visitorId)];
                    }),
                    'attachments' => $this->rows(ConversationMessageAttachment::query()->where('conversation_id', $id), 'conversation_message_attachments', fn (ConversationMessageAttachment $attachment, array $row): ?array => self::belongsToThem($attachment, $visitor) ? [
                        ...$row,
                        'uploaded_by' => $this->role($attachment->uploaded_by_type, $attachment->uploaded_by_id, $visitorId),
                        'file' => $files[(int) $attachment->id] ?? null,
                    ] : null),
                    'reply_deliveries' => $this->rows(
                        ConversationReplyDelivery::query()->whereIn('conversation_message_id', ConversationMessage::query()->select('id')->where('conversation_id', $id)),
                        'conversation_reply_deliveries',
                    ),
                    'ratings' => $this->rows(ConversationRating::query()->where('conversation_id', $id), 'conversation_ratings'),
                    'cobrowse_sessions' => $this->rows(CobrowseSession::query()->where('conversation_id', $id), 'cobrowse_sessions', $this->requestedBy(...)),
                    'copilot_summaries' => $this->rows(ConversationCopilotSummary::query()->where('conversation_id', $id), 'conversation_copilot_summaries', $this->requestedBy(...)),
                    'copilot_reply_drafts' => $this->rows(ConversationCopilotReplyDraft::query()->where('conversation_id', $id), 'conversation_copilot_reply_drafts', $this->requestedBy(...)),
                    'copilot_ticket_suggestions' => $this->rows(ConversationCopilotTicketSuggestion::query()->where('conversation_id', $id), 'conversation_copilot_ticket_suggestions', $this->requestedBy(...)),
                    'copilot_knowledge_suggestions' => $this->rows(ConversationCopilotKnowledgeSuggestion::query()->where('conversation_id', $id), 'conversation_copilot_knowledge_suggestions', $this->requestedBy(...)),
                ]);
            }
        }

        $elsewhere = CobrowseSession::query()
            ->where('visitor_id', $visitorId)
            ->whereNotIn('conversation_id', Conversation::query()->select('id')->where('visitor_id', $visitorId));

        if ($elsewhere->exists()) {
            $this->json($zip, 'cobrowse.json', [
                'sessions' => $this->rows($elsewhere, 'cobrowse_sessions', $this->requestedBy(...)),
            ]);
        }

        return ['conversations' => count($scope['conversation_ids']), 'messages' => $messages];
    }

    /**
     * Each ticket they requested or that came from their conversations, with
     * its notes, where each note was sent and how that went, and the issues
     * it is linked to.
     *
     * @param  array{ticket_ids: list<int>}  $scope
     * @return array<string, int>
     */
    private function writeTickets(StoredZipWriter $zip, array $scope): array
    {
        foreach (array_chunk($scope['ticket_ids'], 500) as $chunk) {
            foreach (Ticket::query()->whereIn('id', $chunk)->orderBy('id')->get() as $ticket) {
                $id = (int) $ticket->id;

                $this->json($zip, "tickets/{$id}.json", [
                    'ticket' => [...$this->row('tickets', $ticket), 'assignee' => $this->userRole($ticket->assignee_id)],
                    'notes' => $this->rows(
                        AuditEvent::query()
                            ->where('subject_type', $ticket->getMorphClass())
                            ->where('subject_id', $id)
                            ->where('action', 'ticket.note_added'),
                        'audit_events',
                        $this->auditRow(...),
                    ),
                    'note_deliveries' => $this->rows(TicketExternalCommentDelivery::query()->where('ticket_id', $id), 'ticket_external_comment_deliveries', fn (TicketExternalCommentDelivery $delivery, array $row): array => [
                        ...$row,
                        'actor' => $this->userRole($delivery->actor_id),
                    ]),
                    'external_links' => $this->rows(TicketExternalLink::query()->where('ticket_id', $id), 'ticket_external_links'),
                ]);
            }
        }

        return ['tickets' => count($scope['ticket_ids'])];
    }

    /**
     * @param  array{anonymous_ids: list<string>}  $scope
     * @return array<string, int>
     */
    private function writeProactive(StoredZipWriter $zip, Site $site, Visitor $visitor, array $scope): array
    {
        $ids = $this->footprint->proactiveDeliveryIds((int) $site->id, (int) $visitor->id, $scope['anonymous_ids']);

        $this->json($zip, 'proactive.json', [
            'deliveries' => $this->rowsById(ProactiveMessageDelivery::query(), $ids, 'proactive_message_deliveries'),
        ]);

        return ['proactive_deliveries' => count($ids)];
    }

    /**
     * @param  array{conversation_ids: list<int>, ticket_ids: list<int>}  $scope
     * @return array<string, int>
     */
    private function writeAlerts(StoredZipWriter $zip, Site $site, array $scope): array
    {
        $ids = $this->footprint->notificationIds((int) $site->account_id, $scope['conversation_ids'], $scope['ticket_ids']);

        $this->json($zip, 'alerts.json', [
            'alerts' => $this->rowsById(DatabaseNotification::query(), $ids, 'notifications', fn (DatabaseNotification $notification, array $row): array => [
                ...$row,
                'recipient' => 'agent',
            ]),
        ]);

        return ['alerts' => count($ids)];
    }

    /**
     * The events about them, their conversations and their tickets (§2), and
     * those they acted in. A macro's event names the work it was applied to
     * only inside its metadata, so the account's are read in PHP.
     *
     * @param  array{visitor_id: int, conversation_ids: list<int>, message_ids: list<int>, attachment_ids: list<int>, cobrowse_ids: list<int>, ticket_ids: list<int>}  $scope
     * @return array<string, int>
     */
    private function writeAudit(StoredZipWriter $zip, Site $site, Visitor $visitor, array $scope): array
    {
        $ids = [];
        $subjects = [
            (new Visitor)->getMorphClass() => [$scope['visitor_id']],
            (new Conversation)->getMorphClass() => $scope['conversation_ids'],
            (new ConversationMessage)->getMorphClass() => $scope['message_ids'],
            (new ConversationMessageAttachment)->getMorphClass() => $scope['attachment_ids'],
            (new CobrowseSession)->getMorphClass() => $scope['cobrowse_ids'],
            (new Ticket)->getMorphClass() => $scope['ticket_ids'],
        ];

        foreach ($subjects as $type => $subjectIds) {
            foreach ($this->idsIn(AuditEvent::query()->where('subject_type', $type), 'subject_id', $subjectIds) as $id) {
                $ids[$id] = true;
            }
        }

        foreach (AuditEvent::query()->where('actor_type', $visitor->getMorphClass())->where('actor_id', $visitor->id)->pluck('id') as $id) {
            $ids[(int) $id] = true;
        }

        $work = [
            (new Conversation)->getMorphClass() => array_flip($scope['conversation_ids']),
            (new Ticket)->getMorphClass() => array_flip($scope['ticket_ids']),
        ];

        AuditEvent::query()
            ->where('account_id', $site->account_id)
            ->where('action', 'automation_macro.applied')
            ->select(['id', 'metadata'])
            ->chunkById(500, function ($events) use ($work, &$ids): void {
                foreach ($events as $event) {
                    $type = data_get($event->metadata, 'support_subject_type');

                    if (is_string($type) && isset($work[$type][(int) data_get($event->metadata, 'support_subject_id')])) {
                        $ids[(int) $event->id] = true;
                    }
                }
            });

        $ids = array_keys($ids);
        sort($ids);
        $exported = 0;

        $this->json($zip, 'audit.json', [
            'events' => $this->rowsById(AuditEvent::query(), $ids, 'audit_events', function (AuditEvent $event, array $row) use ($visitor, &$exported): ?array {
                if (! $this->auditAboutTheirFiles($event, $visitor)) {
                    return null;
                }

                $exported++;

                return $this->auditRow($event, $row);
            }),
        ]);

        return ['audit_events' => $exported];
    }

    /**
     * Text Wayfindr kept in passing that can quote them (§7).
     *
     * @param  array{conversation_ids: list<int>, ticket_ids: list<int>, support_codes: list<string>}  $scope
     * @return array<string, int>
     */
    private function writeIncidental(StoredZipWriter $zip, Site $site, Visitor $visitor, array $scope): array
    {
        $accountId = (int) $site->account_id;
        $webhooks = $this->footprint->webhookDeliveryIdsWithResponse((int) $site->id, $scope['support_codes'], $scope['ticket_ids']);
        // Only runs that provably selected their work: an older run that
        // cannot say whom it found may hold a search about someone else.
        $runs = array_values(array_filter(
            $this->footprint->bulkRunsSelecting($accountId, $scope['conversation_ids'], $scope['ticket_ids']),
            fn (array $run): bool => $run['attributed'],
        ));
        // By their support codes only. Erasure also removes a job that names
        // their email address, to be safe, but another contact can share it.
        $jobs = $this->footprint->failedJobsNaming($this->footprint->uniqueIdentifiers($scope['support_codes']));

        // The runs on their conversations go with them; those on their
        // tickets keep everything but the error text.
        $automation = [];

        foreach ([[(new Conversation)->getMorphClass(), $scope['conversation_ids'], false], [(new Ticket)->getMorphClass(), $scope['ticket_ids'], true]] as [$type, $subjectIds, $errorsOnly]) {
            $query = AutomationRuleExecution::query()->where('subject_type', $type);

            if ($errorsOnly) {
                $query->whereNotNull('error_message');
            }

            $automation = [...$automation, ...$this->idsIn($query, 'subject_id', $subjectIds)];
        }

        sort($automation);
        $searches = fn (string $table) => (function () use ($runs, $table) {
            $model = $table === 'conversation_bulk_action_runs' ? ConversationBulkActionRun::class : TicketBulkActionRun::class;

            foreach ($runs as $run) {
                if ($run['table'] !== $table) {
                    continue;
                }

                $found = $model::query()->find($run['id']);

                if ($found instanceof Model) {
                    yield [
                        ...$this->row($table, $found),
                        'triggered_by' => $this->userRole($found->getAttribute('triggered_by_user_id')),
                        'search' => $run['return_query'][$run['search_key']] ?? null,
                    ];
                }
            }
        })();

        $this->json($zip, 'incidental.json', [
            'webhook_responses' => $this->rowsById(OutboundWebhookDelivery::query(), $webhooks, 'outbound_webhook_deliveries'),
            'automation_runs' => $this->rowsById(AutomationRuleExecution::query(), $automation, 'automation_rule_executions', fn (AutomationRuleExecution $execution, array $row): array => [
                ...$row,
                'triggered_by' => $this->userRole($execution->triggered_by_user_id),
            ]),
            'conversation_searches' => $searches('conversation_bulk_action_runs'),
            'ticket_searches' => $searches('ticket_bulk_action_runs'),
            'failed_jobs' => array_map(fn (array $job): array => [
                'failed_at' => $job['failed_at'],
                'job' => data_get(json_decode($job['payload'], true), 'displayName'),
                'error' => strtok($job['exception'], "\r\n") ?: '',
            ], $jobs),
        ]);

        return [
            'webhook_responses' => count($webhooks),
            'automation_runs' => count($automation),
            'saved_searches' => count($runs),
            'failed_jobs' => count($jobs),
        ];
    }

    /**
     * Platform-operator access to their data: grants scoped to one of their
     * conversations, and views of their conversations or tickets under any
     * grant, each with the grant's scope, reason and length.
     *
     * @param  array{conversation_ids: list<int>, ticket_ids: list<int>}  $scope
     * @return array<string, int>
     */
    private function writeBreakGlass(StoredZipWriter $zip, Site $site, array $scope): array
    {
        $grantIds = $this->footprint->breakGlassGrantIds((int) $site->account_id, $scope['conversation_ids']);
        $resources = ['conversation' => array_flip($scope['conversation_ids']), 'ticket' => array_flip($scope['ticket_ids'])];
        $views = [];

        AuditEvent::query()
            ->where('account_id', $site->account_id)
            ->where('subject_type', (new BreakGlassGrant)->getMorphClass())
            ->where('action', 'break_glass.resource_viewed')
            ->select(['id', 'metadata'])
            ->chunkById(500, function ($events) use ($resources, &$views): void {
                foreach ($events as $event) {
                    $type = data_get($event->metadata, 'resource_type');

                    if (is_string($type) && isset($resources[$type][(int) data_get($event->metadata, 'resource_id')])) {
                        $views[] = (int) $event->id;
                    }
                }
            });

        $this->json($zip, 'break_glass.json', [
            'grants' => $this->rowsById(BreakGlassGrant::query(), $grantIds, 'break_glass_grants', fn (BreakGlassGrant $grant, array $row): array => [
                ...$row,
                'requester' => $this->userRole($grant->requester_id),
                'approver' => $this->userRole($grant->approver_id),
            ]),
            'views' => $this->rowsById(AuditEvent::query(), $views, 'audit_events', function (AuditEvent $event, array $row): array {
                $grant = BreakGlassGrant::query()->find($event->subject_id);

                return [
                    ...$this->auditRow($event, $row),
                    'grant' => $grant instanceof BreakGlassGrant ? [
                        'scope_type' => $grant->getRawOriginal('scope_type'),
                        'reason' => $grant->reason,
                        'requested_minutes' => $grant->requested_minutes,
                        'approved_at' => $grant->getRawOriginal('approved_at'),
                        'expires_at' => $grant->getRawOriginal('expires_at'),
                        'closed_at' => $grant->getRawOriginal('closed_at'),
                    ] : null,
                ];
            }),
        ]);

        return ['break_glass_grants' => count($grantIds), 'break_glass_views' => count($views)];
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<int, string>  $pruned
     * @param  array<int, string>  $withheld
     */
    private function readme(array $counts, array $pruned, array $withheld): string
    {
        $lines = [
            __('visitor_export.readme.title'),
            '',
            __('visitor_export.readme.intro'),
            __('visitor_export.readme.moment', ['time' => now()->toIso8601ZuluString()]),
            '',
            __('visitor_export.readme.contents_heading'),
            ...array_map(fn (string $line): string => '- '.$line, (array) __('visitor_export.readme.contents')),
            '',
            __('visitor_export.readme.roles'),
            '',
            __('visitor_export.readme.review'),
            '',
            __('visitor_export.readme.not_reached_heading'),
            ...array_map(fn (string $line): string => '- '.$line, (array) __('visitor_export.readme.not_reached')),
            '',
            __('visitor_export.readme.counts_heading'),
        ];

        foreach ($counts as $key => $count) {
            $lines[] = "- {$key}: {$count}";
        }

        foreach (['pruned' => $pruned, 'withheld' => $withheld] as $kind => $names) {
            if ($names === []) {
                continue;
            }

            $lines[] = '';
            $lines[] = __('visitor_export.readme.'.$kind);

            foreach ($names as $name) {
                $lines[] = '- '.$name;
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * A JSON object written section by section: a list is streamed a row at
     * a time, so a long history never sits in memory whole.
     *
     * @param  array<string, mixed>  $sections
     */
    private function json(StoredZipWriter $zip, string $name, array $sections): void
    {
        $zip->begin($name);
        $zip->write("{\n");
        $first = true;

        foreach ($sections as $key => $value) {
            $zip->write(($first ? '' : ",\n").'  '.self::encode($key).': ');
            $first = false;

            if (! is_iterable($value) || (is_array($value) && ! array_is_list($value))) {
                $zip->write(self::encode($value));

                continue;
            }

            $zip->write('[');
            $empty = true;

            foreach ($value as $row) {
                $zip->write(($empty ? "\n    " : ",\n    ").self::encode($row));
                $empty = false;
            }

            $zip->write($empty ? ']' : "\n  ]");
        }

        $zip->write("\n}\n");
        $zip->end();
    }

    /**
     * A decorator adds to a row, or returns null to leave it out.
     *
     * @param  Builder<covariant Model>  $query
     * @param  (callable(Model, array<string, mixed>): ?array<string, mixed>)|null  $decorate
     * @return iterable<array<string, mixed>>
     */
    private function rows(Builder $query, string $table, ?callable $decorate = null): iterable
    {
        foreach ($query->lazyById(500) as $model) {
            $row = $this->row($table, $model);
            $row = $decorate === null ? $row : $decorate($model, $row);

            if ($row !== null) {
                yield $row;
            }
        }
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @param  list<int>|list<string>  $ids
     * @param  (callable(Model, array<string, mixed>): array<string, mixed>)|null  $decorate
     * @return iterable<array<string, mixed>>
     */
    private function rowsById(Builder $query, array $ids, string $table, ?callable $decorate = null): iterable
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ((clone $query)->whereIn('id', $chunk)->orderBy('id')->get() as $model) {
                $row = $this->row($table, $model);
                $row = $decorate === null ? $row : $decorate($model, $row);

                if ($row !== null) {
                    yield $row;
                }
            }
        }
    }

    /**
     * The exported columns of one row, JSON and encrypted columns decoded, and
     * identities in the columns that can carry them replaced by roles.
     *
     * @return array<string, mixed>
     */
    private function row(string $table, Model $model): array
    {
        $row = [];

        foreach (self::COLUMNS[$table] as $column => $exported) {
            if ($exported !== true) {
                continue;
            }

            $value = $this->value($model, $column);

            if (in_array($column, self::IDENTITY_COLUMNS[$table] ?? [], true)) {
                $value = self::withoutIdentities($value);
            }

            $row[$column] = $value;
        }

        return $row;
    }

    private function value(Model $model, string $column): mixed
    {
        $cast = $model->getCasts()[$column] ?? null;
        $raw = $model->getAttributes()[$column] ?? null;

        if ($cast === null || $raw === null) {
            return $raw;
        }

        $base = strtolower(explode(':', (string) $cast)[0]);

        if (in_array($base, ['int', 'integer', 'real', 'float', 'double', 'decimal', 'string', 'bool', 'boolean', 'date', 'datetime', 'immutable_date', 'immutable_datetime', 'timestamp'], true)
            || enum_exists((string) $cast)) {
            return $raw;
        }

        try {
            return self::plain($model->getAttribute($column));
        } catch (DecryptException) {
            // Written under another key, or before the column was encrypted:
            // what is stored is still what is held about them.
            return $raw;
        }
    }

    private static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Arrayable => self::plain($value->toArray()),
            $value instanceof ArrayObject => self::plain($value->getArrayCopy()),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof JsonSerializable => self::plain($value->jsonSerialize()),
            is_array($value) => array_map(self::plain(...), $value),
            is_object($value) => self::plain(get_object_vars($value)),
            default => $value,
        };
    }

    private static function withoutIdentities(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $original = $value;

        foreach ($value as $key => $item) {
            if (! is_string($key) || ! array_key_exists($key, self::IDENTITY_KEYS)) {
                $value[$key] = self::withoutIdentities($item);

                continue;
            }

            $kind = $original[(string) preg_replace('/_(id|name)$/', '', $key).'_type'] ?? null;
            $value[$key] = $item === null ? null : (is_string($kind) && $kind !== '' ? $kind : self::IDENTITY_KEYS[$key]);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function auditRow(AuditEvent $event, array $row): array
    {
        return [...$row, 'actor' => $this->role($event->actor_type, $event->actor_id, null)];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function requestedBy(Model $model, array $row): array
    {
        return [...$row, 'requested_by' => $this->userRole($model->getAttribute('requested_by_id'))];
    }

    /**
     * An event about a file is theirs when the file is (an agent's upload
     * not yet sent is a draft of a reply), and an event about a file no
     * longer held, or never stored, is theirs when they did it: a file sent
     * with a message is never removed on its own, so one that is gone was
     * an upload that was not sent. Every other event is theirs.
     */
    private function auditAboutTheirFiles(AuditEvent $event, Visitor $visitor): bool
    {
        if (! str_starts_with((string) $event->action, 'attachment.')) {
            return true;
        }

        $attachment = ConversationMessageAttachment::query()->find(data_get($event->metadata, 'attachment_id'));

        if ($attachment instanceof ConversationMessageAttachment) {
            return self::belongsToThem($attachment, $visitor);
        }

        return $event->actor_type === $visitor->getMorphClass() && (int) $event->actor_id === (int) $visitor->id;
    }

    /**
     * A file that is part of their conversation: sent with a message, or
     * their own upload not sent yet. An agent's upload not yet sent is a
     * draft of a reply, which the download path shows to nobody but its
     * uploader, so it is not theirs to receive.
     */
    private static function belongsToThem(ConversationMessageAttachment $attachment, Visitor $visitor): bool
    {
        return $attachment->isBound() || $attachment->wasUploadedBy($visitor);
    }

    /** Who acted, by role: the person themselves, or which kind of user. */
    private function role(?string $type, mixed $id, ?int $visitorId): string
    {
        return match (true) {
            $type === null || $id === null => 'system',
            $type === (new Visitor)->getMorphClass() => $visitorId === null || (int) $id === $visitorId ? 'visitor' : 'another visitor',
            $type === (new User)->getMorphClass() => (string) $this->userRole($id),
            $type === (new ApiToken)->getMorphClass() => 'integration',
            default => 'system',
        };
    }

    private function userRole(mixed $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return $this->userRoles[(int) $id] ??= User::query()->whereKey((int) $id)->value('platform_role') === PlatformRole::Operator->value
            ? 'platform operator'
            : 'agent';
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /** One path segment, safe on every system the archive is opened on. */
    private static function safeName(string $name, string $fallback): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '-', $name);
        $name = trim($name, " .-\t");
        $name = mb_substr($name, 0, 120);

        return $name === '' || $name === '.' || $name === '..' ? $fallback : $name;
    }
}
