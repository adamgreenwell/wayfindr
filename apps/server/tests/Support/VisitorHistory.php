<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\ConversationRating;
use App\Models\ExternalIssueProviderConnection;
use App\Models\OutboundWebhookDelivery;
use App\Models\OutboundWebhookEndpoint;
use App\Models\ProactiveMessageDelivery;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use App\Models\TicketLabel;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorIdentityAlias;
use App\Models\VisitorNote;
use App\Support\ProactiveMessages\ProactiveVisitorKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A contact with a history in every store erasure has to reach, and export
 * has to read, each piece carrying the marker, plus a same-site bystander
 * carrying another marker that must survive untouched and never be exported.
 *
 * A class rather than a Pest helper: the erasure and export tests both use
 * it, and a global function defined in one test file is missing when the
 * other runs on its own.
 */
final class VisitorHistory
{
    public const MARKER = 'QZERASEMEQZ';

    public const KEEPER = 'QZKEEPMEQZ';

    /**
     * @return array<string, mixed>
     */
    public static function create(): array
    {
        Storage::fake('attachments');

        $account = Account::factory()->create();
        $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
        $site = Site::factory()->for($account)->create();
        $m = self::MARKER;

        $visitor = Visitor::factory()->for($site)->create([
            'name' => "Robin {$m}",
            'email' => strtolower($m).'@example.test',
            'external_id' => "host-{$m}",
            'anonymous_id' => "browser-{$m}",
            'metadata' => ['last_page_url' => "https://shop.example/{$m}", 'context' => ['plan' => $m]],
        ]);
        VisitorIdentityAlias::query()->create([
            'site_id' => $site->id,
            'visitor_id' => $visitor->id,
            'anonymous_id' => "alias-{$m}",
        ]);
        VisitorNote::factory()->create(['account_id' => $account->id, 'visitor_id' => $visitor->id, 'author_id' => $admin->id, 'body' => "Note {$m}"]);

        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'subject' => "Subject {$m}",
            'metadata' => ['started_page_url' => "https://shop.example/start/{$m}"],
        ]);
        $message = ConversationMessage::factory()->for($conversation)->create(['body' => "Body {$m}"]);
        $attachment = ConversationMessageAttachment::factory()->pendingFor($conversation, $visitor)->create([
            'conversation_message_id' => $message->id,
            'original_filename' => "{$m}.png",
        ]);
        Storage::disk('attachments')->put($attachment->storage_key, 'binary');
        ConversationRating::factory()->for($conversation)->create(['comment' => "Rating {$m}"]);
        CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'metadata' => ['page_state' => ['url' => "https://shop.example/cobrowse/{$m}"]],
        ]);

        ProactiveMessageDelivery::factory()->for($site)->for($visitor)->create();
        // Already detached from a pruned presence row, but keyed by this person's browser.
        ProactiveMessageDelivery::factory()->for($site)->create([
            'visitor_id' => null,
            'visitor_key' => ProactiveVisitorKey::for((int) $site->id, "alias-{$m}"),
        ]);

        $ticket = Ticket::factory()->for($account)->for($site)->for($visitor, 'requester')->create([
            'conversation_id' => $conversation->id,
            'status' => 'open',
            'subject' => "Ticket {$m}",
            'description' => "Visitor: Body {$m}",
            'metadata' => [
                'source' => 'conversation',
                'support_code' => $conversation->support_code,
                'visitor_context' => ['last_page_url' => "https://shop.example/{$m}"],
            ],
        ]);
        $label = TicketLabel::factory()->for($account)->create();
        $ticket->labels()->attach($label);
        $connection = ExternalIssueProviderConnection::factory()->for($account)->create();
        $link = TicketExternalLink::factory()->create([
            'account_id' => $account->id,
            'site_id' => $site->id,
            'ticket_id' => $ticket->id,
            'url' => 'https://github.example/acme/app/issues/7',
        ]);
        $note = AuditEvent::query()->create([
            'account_id' => $account->id, 'site_id' => $site->id,
            'actor_type' => $admin->getMorphClass(), 'actor_id' => $admin->id,
            'subject_type' => $ticket->getMorphClass(), 'subject_id' => $ticket->id,
            'action' => 'ticket.note_added', 'metadata' => ['body' => "Ticket note {$m}"],
            'occurred_at' => now(),
        ]);
        DB::table('ticket_external_comment_deliveries')->insert([
            'public_id' => (string) Str::uuid(),
            'account_id' => $account->id, 'site_id' => $site->id, 'ticket_id' => $ticket->id,
            'ticket_external_link_id' => $link->id, 'provider_connection_id' => $connection->id,
            'note_audit_event_id' => $note->id, 'body' => "Comment {$m}",
            'created_at' => now(), 'updated_at' => now(),
        ]);
        AuditEvent::query()->create([
            'account_id' => $account->id, 'site_id' => $site->id,
            'actor_type' => $visitor->getMorphClass(), 'actor_id' => $visitor->id,
            'subject_type' => $conversation->getMorphClass(), 'subject_id' => $conversation->id,
            'action' => 'conversation.created', 'metadata' => ['reason' => "Reason {$m}"],
            'occurred_at' => now(),
        ]);

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\ConversationNeedsReply',
            'notifiable_type' => $admin->getMorphClass(), 'notifiable_id' => $admin->id,
            'data' => json_encode(['conversation_id' => $conversation->id, 'message_preview' => "Preview {$m}"]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // A stripped ticket's alerts store its old subject, which may be the
        // person's words, so they go even though the ticket stays.
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\TicketAssigned',
            'notifiable_type' => $admin->getMorphClass(), 'notifiable_id' => $admin->id,
            'data' => json_encode(['ticket_id' => $ticket->id, 'subject' => "Ticket {$m}"]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sla_clocks')->insert([
            'account_id' => $account->id, 'site_id' => $site->id,
            'subject_type' => $conversation->getMorphClass(), 'subject_id' => $conversation->id,
            'metric' => 'first_response', 'priority' => 'normal', 'target_seconds' => 60, 'warning_seconds' => 30,
            'started_at' => now(), 'last_counted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('automation_rule_executions')->insert([
            'account_id' => $account->id,
            'subject_type' => $conversation->getMorphClass(), 'subject_id' => $conversation->id,
            'rule_name' => 'Route', 'event' => 'conversation.created', 'status' => 'matched',
            'conditions' => '[]', 'actions' => '[]', 'action_results' => '[]',
            'metadata' => json_encode(['message_id' => $message->id, 'note' => "Automation {$m}"]),
            'started_at' => now(), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // A stripped ticket keeps its executions; a failed one's error text is the
        // exception's own, and a failed query quotes the values it was writing.
        DB::table('automation_rule_executions')->insert([
            'account_id' => $account->id,
            'subject_type' => $ticket->getMorphClass(), 'subject_id' => $ticket->id,
            'rule_name' => 'Escalate', 'event' => 'ticket.updated', 'status' => 'failed',
            'conditions' => json_encode([['field' => 'priority', 'operator' => 'equals', 'value' => 'urgent']]),
            'actions' => '[]', 'action_results' => '[]', 'metadata' => '{}',
            'error_message' => "SQLSTATE[23000]: update \"tickets\" set \"subject\" = 'Ticket {$m}'",
            'started_at' => now(), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Bulk runs keep the queue search the agent typed to find the work.
        DB::table('conversation_bulk_action_runs')->insert([
            'account_id' => $account->id, 'triggered_by_user_id' => $admin->id, 'action' => 'close',
            'item_count' => 1, 'changed_count' => 1,
            'changes' => json_encode([['conversation_id' => $conversation->id, 'before' => ['status' => 'open'], 'after' => ['status' => 'closed']]]),
            'return_query' => json_encode(['conversation_filter' => 'open', 'conversation_search' => "robin {$m}"]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ticket_bulk_action_runs')->insert([
            'account_id' => $account->id, 'triggered_by_user_id' => $admin->id, 'action' => 'set_priority',
            'item_count' => 1, 'changed_count' => 1,
            'changes' => json_encode([['ticket_id' => $ticket->id, 'before' => ['priority' => 'normal'], 'after' => ['priority' => 'high']]]),
            'return_query' => json_encode(['ticket_status' => 'open', 'ticket_search' => "robin {$m}"]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A reply that exhausted its retries: the mail server's rejection
        // quotes the address it refused, and failed_jobs keeps it.
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\SendConversationReplyDelivery']),
            'exception' => 'Symfony\\Component\\Mailer\\Exception\\TransportException: 550 5.1.1 "'.$visitor->name.'" <'.$visitor->email.'>: Recipient address rejected',
            'failed_at' => now(),
        ]);

        $endpoint = OutboundWebhookEndpoint::factory()->for($account)->create();
        $pending = OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create([
            'site_id' => $site->id,
            'event' => OutboundWebhookEndpoint::EVENT_CONVERSATION_OPENED,
            'payload' => ['resource' => ['type' => 'conversation', 'support_code' => $conversation->support_code]],
        ]);
        // Response samples are encrypted, so the raw-table sweep cannot see
        // them; the webhook test reads them back through the model.
        $echoed = OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create([
            'site_id' => $site->id, 'sequence' => 10,
            'event' => OutboundWebhookEndpoint::EVENT_CONVERSATION_OPENED,
            'payload' => ['resource' => ['type' => 'conversation', 'support_code' => $conversation->support_code]],
            'response_status' => 200, 'response_body' => "{\"fetched\":\"Robin {$m}\"}", 'delivered_at' => now(),
        ]);
        $echoedTicket = OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create([
            'site_id' => $site->id, 'sequence' => 11,
            'payload' => ['resource' => ['type' => 'ticket', 'id' => $ticket->id]],
            'response_status' => 500, 'response_body' => "could not sync Ticket {$m}", 'failed_at' => now(),
        ]);

        $bystander = Visitor::factory()->for($site)->create(['name' => 'Kept '.self::KEEPER]);
        $kept = Conversation::factory()->for($site)->for($bystander)->create(['subject' => 'Kept '.self::KEEPER]);
        ConversationMessage::factory()->for($kept)->create(['body' => 'Kept '.self::KEEPER]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\SendConversationReplyDelivery']),
            'exception' => 'TransportException: 550 5.1.1 "Kept '.self::KEEPER.'" <kept-'.strtolower(self::KEEPER).'@example.test>: Recipient address rejected',
            'failed_at' => now(),
        ]);
        DB::table('conversation_bulk_action_runs')->insert([
            'account_id' => $account->id, 'triggered_by_user_id' => $admin->id, 'action' => 'close',
            'item_count' => 1, 'changed_count' => 1,
            'changes' => json_encode([['conversation_id' => $kept->id, 'before' => ['status' => 'open'], 'after' => ['status' => 'closed']]]),
            'return_query' => json_encode(['conversation_search' => 'kept '.self::KEEPER]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $keptEcho = OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create([
            'site_id' => $site->id, 'sequence' => 12,
            'event' => OutboundWebhookEndpoint::EVENT_CONVERSATION_OPENED,
            'payload' => ['resource' => ['type' => 'conversation', 'support_code' => $kept->support_code]],
            'response_status' => 200, 'response_body' => 'Kept '.self::KEEPER, 'delivered_at' => now(),
        ]);

        return compact('account', 'admin', 'site', 'visitor', 'conversation', 'attachment', 'ticket', 'label', 'link', 'note', 'pending', 'echoed', 'echoedTicket', 'bystander', 'kept', 'keptEcho');
    }
}
