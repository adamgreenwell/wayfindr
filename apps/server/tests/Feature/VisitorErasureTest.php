<?php

// Erasing a contact (ADR 0026). The claim under test is not "the visitor row
// is gone" -- a plain delete manages that and leaves the person in tickets,
// audit metadata, agent notifications and attachment storage. The claim is
// that nothing Wayfindr stores still carries them, so the central test plants
// a marker everywhere a visitor's data can live and then reads every table.

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Enums\AutomationRuleEvent;
use App\Events\VisitorPresenceUpdated;
use App\Http\Controllers\AgentConversationAttachmentController;
use App\Jobs\DeliverTicketExternalComment;
use App\Jobs\GenerateConversationCopilotKnowledgeSuggestion;
use App\Jobs\GenerateConversationCopilotReplyDraft;
use App\Jobs\GenerateConversationCopilotSummary;
use App\Jobs\GenerateConversationCopilotTicketSuggestion;
use App\Jobs\SendConversationReplyDelivery;
use App\Listeners\MarkSlaMailTransportStarted;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\AutomationMacro;
use App\Models\AutomationRule;
use App\Models\AutomationRuleExecution;
use App\Models\BreakGlassGrant;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\ConversationRating;
use App\Models\ConversationReplyDelivery;
use App\Models\CustomRole;
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
use App\Models\VisitorErasure;
use App\Models\VisitorIdentityAlias;
use App\Models\VisitorNote;
use App\Notifications\AutomationRuleMatched;
use App\Notifications\Channels\ErasureAwareDatabaseChannel;
use App\Notifications\ConversationNeedsReply;
use App\Notifications\SlaDeadlineAlert;
use App\Notifications\TicketAssigned;
use App\Support\AgentAlertDeliveryCoordinator;
use App\Support\Automation\AutomationMacroRunFailed;
use App\Support\Automation\AutomationMacroRunner;
use App\Support\Automation\AutomationRuleEngine;
use App\Support\BreakGlass\BreakGlassGrants;
use App\Support\CobrowseAuditTrail;
use App\Support\ExternalIssues\InboundCommentSync;
use App\Support\ProactiveMessages\ProactiveVisitorKey;
use App\Support\Visitors\VisitorEraser;
use App\Support\Visitors\VisitorIdentityMerger;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Email;

uses(RefreshDatabase::class);

const ERASURE_MARKER = 'QZERASEMEQZ';
const ERASURE_KEEPER = 'QZKEEPMEQZ';

/**
 * A contact with a history in every store erasure has to reach, each piece
 * carrying the marker, plus a same-site bystander carrying another marker
 * that must survive untouched.
 *
 * @return array<string, mixed>
 */
function erasureFixture(): array
{
    Storage::fake('attachments');

    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();
    $m = ERASURE_MARKER;

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

    $bystander = Visitor::factory()->for($site)->create(['name' => 'Kept '.ERASURE_KEEPER]);
    $kept = Conversation::factory()->for($site)->for($bystander)->create(['subject' => 'Kept '.ERASURE_KEEPER]);
    ConversationMessage::factory()->for($kept)->create(['body' => 'Kept '.ERASURE_KEEPER]);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SendConversationReplyDelivery']),
        'exception' => 'TransportException: 550 5.1.1 "Kept '.ERASURE_KEEPER.'" <kept-'.strtolower(ERASURE_KEEPER).'@example.test>: Recipient address rejected',
        'failed_at' => now(),
    ]);
    DB::table('conversation_bulk_action_runs')->insert([
        'account_id' => $account->id, 'triggered_by_user_id' => $admin->id, 'action' => 'close',
        'item_count' => 1, 'changed_count' => 1,
        'changes' => json_encode([['conversation_id' => $kept->id, 'before' => ['status' => 'open'], 'after' => ['status' => 'closed']]]),
        'return_query' => json_encode(['conversation_search' => 'kept '.ERASURE_KEEPER]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $keptEcho = OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create([
        'site_id' => $site->id, 'sequence' => 12,
        'event' => OutboundWebhookEndpoint::EVENT_CONVERSATION_OPENED,
        'payload' => ['resource' => ['type' => 'conversation', 'support_code' => $kept->support_code]],
        'response_status' => 200, 'response_body' => 'Kept '.ERASURE_KEEPER, 'delivered_at' => now(),
    ]);

    return compact('account', 'admin', 'site', 'visitor', 'conversation', 'attachment', 'ticket', 'label', 'link', 'note', 'pending', 'echoed', 'echoedTicket', 'bystander', 'kept', 'keptEcho');
}

/**
 * Every table whose rows contain the needle anywhere, read the dumbest way
 * possible so it cannot share a blind spot with the code under test.
 *
 * @return list<string>
 */
function erasureTablesContaining(string $needle): array
{
    $found = [];

    foreach (Schema::getTableListing() as $table) {
        $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

        foreach (DB::table($table)->get() as $row) {
            if (str_contains(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '', $needle)) {
                $found[] = $table;

                break;
            }
        }
    }

    sort($found);

    return $found;
}

function eraseThroughTheDashboard(User $actor, Visitor $visitor): TestResponse
{
    return test()->actingAs($actor)->post(route('dashboard.visitors.erasure.store', $visitor), [
        'confirmation' => 'ERASE',
        'current_password' => 'password',
    ]);
}

test('erasing a contact leaves no trace of them anywhere Wayfindr stores data', function (): void {
    $f = erasureFixture();

    // The sweep has to see the marker before it can be trusted not to.
    $before = erasureTablesContaining(ERASURE_MARKER);
    foreach (['visitors', 'visitor_identity_aliases', 'visitor_notes', 'conversations', 'conversation_messages', 'conversation_message_attachments', 'conversation_ratings', 'cobrowse_sessions', 'tickets', 'ticket_external_comment_deliveries', 'audit_events', 'notifications', 'automation_rule_executions', 'conversation_bulk_action_runs', 'ticket_bulk_action_runs', 'failed_jobs'] as $table) {
        expect(in_array($table, $before, true))->toBeTrue("the sweep did not find the marker in {$table} before erasure, so it proves nothing after");
    }

    eraseThroughTheDashboard($f['admin'], $f['visitor'])
        ->assertRedirect(route('dashboard.visitors.index'))
        ->assertSessionHas('status', 'visitor_erasure.flash.erased');

    expect(erasureTablesContaining(ERASURE_MARKER))->toBe([], 'the erased person is still stored somewhere')
        ->and(Storage::disk('attachments')->exists($f['attachment']->storage_key))->toBeFalse('the uploaded file outlived the erasure')
        ->and(VisitorErasure::query()->sole()->pending_files)->toBeNull('a removed file is still listed as pending')
        ->and(ProactiveMessageDelivery::query()->count())->toBe(0, 'a proactive delivery keyed to their browser survived')
        ->and(DB::table('sla_clocks')->count())->toBe(0, 'an SLA clock for an erased conversation survived')
        ->and(erasureTablesContaining(ERASURE_KEEPER))->toBe(['conversation_bulk_action_runs', 'conversation_messages', 'conversations', 'failed_jobs', 'visitors'], 'the bystander on the same site was touched');
});

test('tickets stay as work items with the person stripped out', function (): void {
    $f = erasureFixture();

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect();

    $ticket = Ticket::query()->findOrFail($f['ticket']->id);

    expect($ticket->subject)->toBe('Ticket #'.$ticket->id.' (requester erased)')
        ->and($ticket->description)->toBeNull()
        ->and($ticket->requester_id)->toBeNull()
        ->and($ticket->conversation_id)->toBeNull()
        ->and($ticket->status)->toBe('open', 'the work item lost its status')
        ->and($ticket->metadata)->toBe(['source' => 'conversation', 'requester_erased' => true])
        ->and($ticket->labels()->pluck('ticket_labels.id')->all())->toBe([$f['label']->id], 'the work item lost its labels')
        ->and(TicketExternalLink::query()->whereKey($f['link']->id)->exists())->toBeTrue('the external issue link was dropped')
        ->and(DB::table('ticket_external_comment_deliveries')->count())->toBe(0, 'a note body posted to the provider was kept');

    // Its automation history stays, with the rule's own text; only the raw
    // error that quoted the ticket goes.
    $execution = DB::table('automation_rule_executions')->where('subject_type', $ticket->getMorphClass())->where('subject_id', $ticket->id)->sole();
    expect($execution->error_message)->toBeNull('a failed run still quotes the ticket')
        ->and(json_decode((string) $execution->conditions, true))->toBe([['field' => 'priority', 'operator' => 'equals', 'value' => 'urgent']], 'the run lost its copy of the rule');

    // The ticket's own page renders its activity from audit entries whose
    // metadata is now only `{"erased": true}`: it has to cope with that.
    $this->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Ticket #'.$ticket->id.' (requester erased)')
        ->assertDontSee(ERASURE_MARKER);
});

test('a note already being posted to a linked issue holds the erasure, so none is sent after it', function (): void {
    $f = erasureFixture();
    DB::table('ticket_external_comment_deliveries')->update(['started_at' => now()->subSeconds(10)]);

    eraseThroughTheDashboard($f['admin'], $f['visitor'])
        ->assertSessionHasErrors(['confirmation' => __('visitor_erasure.errors.note_posting')]);

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeTrue('a refused erasure still deleted the contact')
        ->and(DB::table('ticket_external_comment_deliveries')->count())->toBe(1, 'a refused erasure still deleted the delivery');

    // Past the window the post has ended one way or the other: the note is in
    // the tracker or nowhere, so the erasure goes ahead and takes the copy.
    $this->travel(VisitorEraser::IN_FLIGHT_WINDOW_SECONDS + 1)->seconds();

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    expect(DB::table('ticket_external_comment_deliveries')->count())->toBe(0);
});

test('a note the provider already accepted does not hold the erasure', function (): void {
    $f = erasureFixture();
    DB::table('ticket_external_comment_deliveries')->update(['started_at' => now()->subSeconds(10), 'accepted_at' => now()->subSeconds(5)]);

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeFalse();
});

test('a stripped ticket stops mirroring comments from its linked issue', function (): void {
    $f = erasureFixture();
    $sync = app(InboundCommentSync::class);

    // A comment that arrives first is recorded, and goes with the rest.
    expect($sync->record($f['link'], 'before-erasure', 'Before '.ERASURE_MARKER, 'Engineer', 'webhook'))->toBeTrue();

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    expect($sync->record($f['link']->fresh(), 'after-erasure', 'After '.ERASURE_MARKER, 'Engineer', 'webhook'))
        ->toBeFalse('the tracker wrote a new copy onto the stripped ticket')
        ->and(erasureTablesContaining(ERASURE_MARKER))->toBe([]);
});

test('the break-glass trail keeps its reason and who acted, and stops naming an erased conversation', function (): void {
    $f = erasureFixture();
    $grants = app(BreakGlassGrants::class);
    $operator = User::factory()->create(['platform_role' => 'operator']);
    $scoped = BreakGlassGrant::factory()->create([
        'account_id' => $f['account']->id,
        'scope_type' => BreakGlassGrant::SCOPE_CONVERSATION,
        'conversation_id' => $f['conversation']->id,
        'requester_id' => $operator->id,
        'reason' => 'The customer asked us to look',
    ]);
    $wide = BreakGlassGrant::factory()->create(['account_id' => $f['account']->id, 'requester_id' => $operator->id]);
    $grants->recordResourceViewed($scoped, $operator, 'conversation', (int) $f['conversation']->id, 'Conversation '.$f['conversation']->support_code);
    $grants->recordResourceViewed($wide, $operator, 'conversation', (int) $f['conversation']->id, 'Conversation '.$f['conversation']->support_code);
    $grants->recordResourceViewed($wide, $operator, 'conversation', (int) $f['kept']->id, 'Conversation '.$f['kept']->support_code);
    $approved = $scoped->auditEvents()->create([
        'account_id' => $f['account']->id, 'actor_type' => $f['admin']->getMorphClass(), 'actor_id' => $f['admin']->id,
        'action' => 'break_glass.approved', 'occurred_at' => now(),
        'metadata' => ['scope_type' => 'conversation', 'scope_label' => $scoped->scopeLabel(), 'reason' => $scoped->reason],
    ]);
    $code = (string) $f['conversation']->support_code;

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    $trail = AuditEvent::query()->where('subject_type', (new BreakGlassGrant)->getMorphClass())->get();

    expect($trail)->toHaveCount(4, 'the record of operator access lost an entry')
        ->and($trail->filter(fn (AuditEvent $event): bool => str_contains((string) json_encode($event->metadata), $code))->count())
        ->toBe(0, 'the trail still names the erased conversation')
        ->and($approved->fresh()->metadata)->toBe(['scope_type' => 'conversation', 'scope_label' => 'Conversation (deleted)', 'reason' => 'The customer asked us to look'])
        ->and((int) $approved->fresh()->actor_id)->toBe((int) $f['admin']->id, 'the trail lost who approved')
        ->and($trail->contains(fn (AuditEvent $event): bool => ($event->metadata['resource_label'] ?? null) === 'Conversation '.$f['kept']->support_code))
        ->toBeTrue('a view of the bystander\'s conversation was relabelled');
});

test('an SLA alert a mail worker already holds for a stripped ticket is stopped before SMTP', function (): void {
    $f = erasureFixture();
    $clockId = DB::table('sla_clocks')->insertGetId([
        'account_id' => $f['account']->id, 'site_id' => $f['site']->id,
        'subject_type' => $f['ticket']->getMorphClass(), 'subject_id' => $f['ticket']->id,
        'metric' => 'resolution', 'priority' => 'normal', 'target_seconds' => 60, 'warning_seconds' => 30,
        'started_at' => now(), 'last_counted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $delivery = fn (array $state): string => tap((string) Str::uuid(), fn (string $id) => DB::table('sla_alert_deliveries')->insert([
        'public_id' => $id, 'sla_clock_id' => $clockId, 'user_id' => $f['admin']->id,
        'stage' => 'breach', 'channel' => 'mail', 'created_at' => now(), 'updated_at' => now(), ...$state,
    ]));
    $claimed = $delivery(['claimed_at' => now(), 'stage' => 'breach']);
    $sent = $delivery(['claimed_at' => now(), 'started_at' => now(), 'stage' => 'warning']);

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    // The worker already holds the message, with the ticket's original
    // subject; the check it makes immediately before SMTP must refuse it.
    $email = (new Email)->to('agent@example.test')->text('breach');
    $email->getHeaders()->addTextHeader(SlaDeadlineAlert::DELIVERY_HEADER, $claimed);

    expect(fn () => app(MarkSlaMailTransportStarted::class)->handle(new MessageSending($email)))
        ->toThrow(LogicException::class)
        ->and(DB::table('sla_alert_deliveries')->where('public_id', $sent)->value('cancelled_at'))
        ->toBeNull('a send already handed to the mail server was rewritten');
});

test('an alert raised just before an erasure cannot land after it quoting the person', function (): void {
    $f = erasureFixture();

    // A request that committed its change just before the erasure still holds
    // these in memory. Queued alerts re-read their models first, which leaves
    // only the moment between that read and the insert; the database channel
    // is sent them at exactly that point.
    $assigned = new TicketAssigned(Ticket::query()->findOrFail($f['ticket']->id), $f['admin']);
    $needsReply = new ConversationNeedsReply(ConversationMessage::query()->where('conversation_id', $f['conversation']->id)->firstOrFail());

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    $channel = app(ChannelManager::class)->driver('database');
    expect($channel)->toBeInstanceOf(ErasureAwareDatabaseChannel::class, 'notify() does not store alerts through the guarded channel');

    // The sender stamps each alert with its id before any channel sees it.
    $assigned->id = (string) Str::uuid();
    $needsReply->id = (string) Str::uuid();
    $channel->send($f['admin'], $assigned);
    $channel->send($f['admin'], $needsReply);

    $alerts = DB::table('notifications')->get(['type', 'data']);

    expect(erasureTablesContaining(ERASURE_MARKER))->toBe([], 'a late alert wrote the person back')
        ->and($alerts->where('type', ConversationNeedsReply::class))->toHaveCount(0, 'an alert about an erased conversation was stored')
        ->and(json_decode((string) $alerts->firstWhere('type', TicketAssigned::class)?->data, true)['subject'] ?? null)
        ->toBe('Ticket #'.$f['ticket']->id.' (requester erased)', 'the stripped ticket stopped alerting, or alerted under its old subject');
});

test('alert mail built before an erasure is stopped before SMTP, and a stripped ticket still alerts', function (): void {
    $f = erasureFixture();
    $build = function (MailMessage $message): Email {
        $email = (new Email)->to('agent@example.test')->text('alert');

        foreach ($message->callbacks as $callback) {
            $callback($email);
        }

        return $email;
    };
    $reachesSmtp = function (Email $email): bool {
        try {
            app(MarkSlaMailTransportStarted::class)->handle(new MessageSending($email));

            return true;
        } catch (LogicException) {
            return false;
        }
    };
    $ticket = Ticket::query()->with('site')->findOrFail($f['ticket']->id);
    $message = ConversationMessage::query()->where('conversation_id', $f['conversation']->id)->firstOrFail();

    // Workers that read the work before the erasure and reach SMTP after it.
    $early = [
        'ticket assigned' => $build((new TicketAssigned($ticket, $f['admin']))->toMail($f['admin'])),
        'automation matched' => $build((new AutomationRuleMatched($ticket, 'Tag refunds'))->toMail($f['admin'])),
        'reply needed' => $build((new ConversationNeedsReply($message))->toMail($f['admin'])),
    ];

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    $sent = array_keys(array_filter($early, $reachesSmtp));
    $stripped = Ticket::query()->with('site')->findOrFail($f['ticket']->id);

    expect($sent)->toBe([], 'alert mail built before the erasure reached SMTP after it')
        ->and($reachesSmtp($build((new TicketAssigned($stripped, $f['admin']))->toMail($f['admin']))))
        ->toBeTrue('a stripped ticket stopped alerting by mail');
});

test('an alert mail on its way to the mail server holds the erasure until it is sent', function (string $about): void {
    $f = erasureFixture();
    $notification = $about === 'ticket'
        ? new TicketAssigned(Ticket::query()->with('site')->findOrFail($f['ticket']->id), $f['admin'])
        : new ConversationNeedsReply(ConversationMessage::query()->where('conversation_id', $f['conversation']->id)->firstOrFail());
    $email = (new Email)->from('alerts@example.test')->to('agent@example.test')->text('alert');

    foreach ($notification->toMail($f['admin'])->callbacks as $callback) {
        $callback($email);
    }

    // Past its last check, not yet at the mail server.
    app(MarkSlaMailTransportStarted::class)->handle(new MessageSending($email));

    eraseThroughTheDashboard($f['admin'], $f['visitor'])
        ->assertSessionHasErrors(['confirmation' => __('visitor_erasure.errors.alert_mail_sending')]);
    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeTrue('the erasure went ahead while the mail was in flight');

    event(new MessageSent(new SentMessage(new SymfonySentMessage($email, Envelope::create($email)))));

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));
    expect(DB::table('alert_mail_sends')->count())->toBe(0, 'a sent mail was still on record');
})->with(['ticket', 'conversation']);

test('a send that never reported back stops holding the erasure after the in-flight window', function (): void {
    $f = erasureFixture();
    DB::table('alert_mail_sends')->insert([
        'subject_type' => $f['conversation']->getMorphClass(), 'subject_id' => $f['conversation']->id,
        'started_at' => now()->subSeconds(VisitorEraser::IN_FLIGHT_WINDOW_SECONDS + 1),
    ]);

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    expect(DB::table('alert_mail_sends')->count())->toBe(0, 'the stale record outlived the erasure it named');
});

test('an alert mail refused after its check leaves no send on record', function (): void {
    $f = erasureFixture();
    $email = (new Email)->to('agent@example.test')->text('alert');

    foreach ((new TicketAssigned(Ticket::query()->with('site')->findOrFail($f['ticket']->id), $f['admin']))->toMail($f['admin'])->callbacks as $callback) {
        $callback($email);
    }

    // A later boundary refuses it: here, a delivery claim that does not parse.
    $email->getHeaders()->addTextHeader(AgentAlertDeliveryCoordinator::ID_HEADER, 'not-a-claim');

    expect(fn () => app(MarkSlaMailTransportStarted::class)->handle(new MessageSending($email)))->toThrow(LogicException::class)
        ->and(DB::table('alert_mail_sends')->count())->toBe(0, 'a mail that never left kept holding erasure');
});

test('an attachment download recorded just after an erasure keeps no filename', function (): void {
    $f = erasureFixture();
    // The download resolved these before the erasure, and audits after serving.
    $conversation = Conversation::query()->with('site')->findOrFail($f['conversation']->id);
    $attachment = ConversationMessageAttachment::query()->findOrFail($f['attachment']->id);

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));
    $events = AuditEvent::query()->count();

    (new ReflectionMethod(AgentConversationAttachmentController::class, 'recordAgentAccess'))
        ->invoke(app(AgentConversationAttachmentController::class), $conversation, $attachment, $f['admin']);

    expect(AuditEvent::query()->count())->toBe($events, 'a download from the erased conversation was recorded after it')
        ->and(erasureTablesContaining(ERASURE_MARKER))->toBe([], 'the late download record kept the file\'s name');
});

test('a cobrowse answer recorded just after an erasure does not name the person', function (): void {
    $f = erasureFixture();
    // The consent controller commits a decline, then audits it: the erasure
    // lands in between, with the session and visitor still in its hands.
    $session = CobrowseSession::query()->with('conversation')->where('conversation_id', $f['conversation']->id)->firstOrFail();
    $visitor = Visitor::query()->findOrFail($f['visitor']->id);

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));
    $events = AuditEvent::query()->count();

    app(CobrowseAuditTrail::class)->consentAnswered($session, $visitor, 'granted', false);

    expect(AuditEvent::query()->count())->toBe($events, 'a cobrowse answer about the erased conversation was recorded after it')
        ->and(AuditEvent::query()->where('actor_type', $visitor->getMorphClass())->where('actor_id', $visitor->id)->exists())
        ->toBeFalse('an audit event named the erased visitor as its actor');
});

test('a break-glass view recorded just after an erasure does not name the conversation', function (): void {
    $f = erasureFixture();
    $operator = User::factory()->create(['platform_role' => 'operator']);
    $grant = BreakGlassGrant::factory()->create([
        'account_id' => $f['account']->id,
        'scope_type' => BreakGlassGrant::SCOPE_CONVERSATION,
        'conversation_id' => $f['conversation']->id,
        'requester_id' => $operator->id,
    ]);
    // The viewer loaded the grant and the conversation before the erasure.
    $grant->load('conversation');
    $code = (string) $f['conversation']->support_code;

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    app(BreakGlassGrants::class)->recordOpened($grant, $operator);
    app(BreakGlassGrants::class)->recordResourceViewed($grant, $operator, 'conversation', (int) $f['conversation']->id, 'Conversation '.$code);

    $opened = AuditEvent::query()->where('action', 'break_glass.opened')->sole();
    $viewed = AuditEvent::query()->where('action', 'break_glass.resource_viewed')->sole();

    expect($opened->metadata['scope_label'])->toBe('Conversation (deleted)', 'the late opened event named the erased conversation')
        ->and($viewed->metadata['resource_label'])->toBe('Conversation (deleted)', 'the late view named the erased conversation')
        ->and($viewed->metadata['scope_label'])->toBe('Conversation (deleted)', 'the late view kept the stale scope label');
});

test('failed jobs are matched only by what names this person, whole', function (): void {
    $f = erasureFixture();
    $email = (string) $f['visitor']->email;
    $code = (string) $f['conversation']->support_code;
    $job = fn (string $exception): int => DB::table('failed_jobs')->insertGetId([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\DeliverOutboundWebhook']),
        'exception' => $exception, 'failed_at' => now(),
    ]);
    $theirs = [
        $job("550 5.1.1 <{$email}>: Recipient address rejected"),
        $job("Webhook for {$code} was refused."),
    ];
    // Another mailbox and another code that merely contain theirs, and a host
    // ID, which another site's visitor may share: failed-job text cannot say
    // which site it was about.
    $others = [
        $job("550 5.1.1 <x{$email}>: Recipient address rejected"),
        $job("550 5.1.1 <{$email}.example.org>: Recipient address rejected"),
        $job("Webhook for {$code}9 was refused."),
        $job('Host lookup for '.$f['visitor']->external_id.' failed.'),
    ];

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    $left = DB::table('failed_jobs')->pluck('id')->all();

    expect(array_values(array_intersect($theirs, $left)))->toBe([], 'a failed job naming the person was kept')
        ->and(array_values(array_diff($others, $left)))->toBe([], 'a failed job that only resembles the person went too');
});

test('failed jobs are erased from wherever the operator keeps them', function (string $store): void {
    $f = erasureFixture();
    $address = (string) $f['visitor']->email;

    if ($store === 'database connection') {
        config()->set('database.connections.failed_jobs_store', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        config()->set('queue.failed.database', 'failed_jobs_store');
        Schema::connection('failed_jobs_store')->create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    } else {
        config()->set('queue.failed.driver', 'file');
        config()->set('queue.failed.path', storage_path('framework/testing/failed-jobs-'.Str::uuid().'.json'));
    }

    app()->forgetInstance('queue.failer');
    $failer = app('queue.failer');
    $log = fn (string $exception) => $failer->log('database', 'default', json_encode([
        'uuid' => (string) Str::uuid(), 'displayName' => SendConversationReplyDelivery::class,
    ]), new RuntimeException($exception));
    $log("550 5.1.1 <{$address}>: Recipient address rejected");
    $log('550 5.1.1 <kept-'.strtolower(ERASURE_KEEPER).'@example.test>: Recipient address rejected');

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    $left = collect($failer->all())->map(fn (object $job): string => (string) $job->exception);

    expect($left->filter(fn (string $exception): bool => str_contains($exception, $address)))
        ->toHaveCount(0, "a failed job in the {$store} store still named the person")
        ->and($left->filter(fn (string $exception): bool => str_contains($exception, strtolower(ERASURE_KEEPER))))
        ->toHaveCount(1, "a stranger's failed job in the {$store} store went too");
})->with(['database connection', 'file']);

test('a reply that fails for good after an erasure cannot record the address it was refused', function (): void {
    $f = erasureFixture();
    $address = (string) $f['visitor']->email;
    $deliveryId = (int) ConversationReplyDelivery::query()->create([
        'conversation_message_id' => ConversationMessage::query()->where('conversation_id', $f['conversation']->id)->firstOrFail()->id,
        'recipient' => $address,
        'message_id' => '<reply-'.Str::uuid().'@example.test>',
    ])->id;
    Exceptions::fake();
    config()->set('mail.default', 'smtp');
    Mail::shouldReceive('to')->once()->with($address)
        ->andThrow(new TransportException("550 5.1.1 <{$address}>: Recipient address rejected"));

    $thrown = null;

    try {
        (new SendConversationReplyDelivery($deliveryId))->handle();
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull('the refused send did not fail the attempt');

    // The erasure deletes the row while the send holds it, and commits before
    // the worker's own bookkeeping can run, so the worker records the final
    // attempt's failure after the sweep.
    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    $job = new SendConversationReplyDelivery($deliveryId);
    $uuid = app('queue.failer')->log('database', 'default', json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => $job::class,
        'data' => ['commandName' => $job::class, 'command' => serialize($job)],
    ]), $thrown);

    expect(erasureTablesContaining($address))->toBe([], 'the late failure record named the address the mail server refused')
        ->and(str_contains((string) DB::table('failed_jobs')->where('uuid', $uuid)->value('exception'), TransportException::class))
        ->toBeTrue('the failure record no longer says what failed');

    Exceptions::assertReported(fn (TransportException $exception): bool => str_contains($exception->getMessage(), '550 5.1.1'));
});

test('an automation failure recorded just after an erasure keeps nothing of the person', function (string $runner, string $subject): void {
    $f = erasureFixture();
    $earlier = AutomationRuleExecution::query()->pluck('id');
    $target = $subject === 'ticket'
        ? Ticket::query()->findOrFail($f['ticket']->id)
        : Conversation::query()->findOrFail($f['conversation']->id);
    // Another account's agent: assigning them fails inside the run's transaction.
    $actions = [['type' => 'assign_agent', 'value' => User::factory()->create()->id]];

    // The erasure lands after the run rolls back, before its failure is recorded.
    $level = DB::transactionLevel();
    $erased = false;
    Event::listen(TransactionRolledBack::class, function () use (&$erased, $f, $level): void {
        if ($erased || DB::transactionLevel() !== $level) {
            return;
        }

        $erased = true;
        app(VisitorEraser::class)->erase($f['admin'], $f['visitor']);
    });

    if ($runner === 'macro') {
        $macro = AutomationMacro::factory()->for($f['account'])->enabled()->create(['subject_type' => $subject, 'actions' => $actions]);

        expect(fn () => app(AutomationMacroRunner::class)->run($f['admin'], $macro, $target))
            ->toThrow(AutomationMacroRunFailed::class);
    } else {
        $event = $subject === 'ticket' ? AutomationRuleEvent::TicketCreated : AutomationRuleEvent::ConversationCreated;
        AutomationRule::factory()->for($f['account'])->enabled()->create(['event' => $event, 'actions' => $actions]);

        app(AutomationRuleEngine::class)->handle($event, $target);
    }

    $executions = AutomationRuleExecution::query()
        ->whereKeyNot($earlier)
        ->where('subject_type', $target->getMorphClass())
        ->where('subject_id', $target->id)
        ->get();

    expect($erased)->toBeTrue('the run never failed, so the erasure never landed mid-run');

    if ($subject === 'conversation') {
        expect($executions)->toHaveCount(0, 'a failure about the erased conversation was recorded after it');
    } else {
        expect($executions)->toHaveCount(1, 'the failure on the stripped ticket was not recorded')
            ->and($executions->sole()->error_message)->toBeNull('the failure on the stripped ticket kept its raw error text');
    }
})->with(['rule', 'macro'])->with(['ticket', 'conversation']);

test('a long history is summarized and erased in chunks the database can bind', function (): void {
    $f = erasureFixture();
    $now = now();

    // An account's agents are not bounded either.
    foreach (User::factory()->count(600)->for($f['account'])->make()->chunk(200) as $agents) {
        DB::table('users')->insert($agents->map(fn (User $agent): array => [...$agent->getAttributes(), 'created_at' => $now, 'updated_at' => $now])->values()->all());
    }

    foreach (array_chunk(range(1, 1100), 200) as $batch) {
        DB::table('tickets')->insert(array_map(fn (int $n): array => [
            'account_id' => $f['account']->id, 'site_id' => $f['site']->id, 'requester_id' => $f['visitor']->id,
            'subject' => "Old ticket {$n}", 'metadata' => '{}', 'created_at' => $now, 'updated_at' => $now,
        ], $batch));
        DB::table('visitor_identity_aliases')->insert(array_map(fn (int $n): array => [
            'site_id' => $f['site']->id, 'visitor_id' => $f['visitor']->id,
            'anonymous_id' => "browser-{$n}", 'created_at' => $now, 'updated_at' => $now,
        ], $batch));
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $summary = app(VisitorEraser::class)->summarize($f['visitor']);
    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    $widest = collect(DB::getQueryLog())->max(fn (array $query): int => count($query['bindings']));
    DB::disableQueryLog();

    // A driver caps the values one statement can bind (PostgreSQL at 65,535),
    // so no statement may bind a whole history, only a chunk of it.
    expect($widest)->toBeLessThanOrEqual(520, 'a statement bound the whole history at once')
        ->and($summary['tickets'])->toHaveCount(1101, 'the summary lost tickets across chunks')
        ->and(DB::table('tickets')->where('subject', 'like', 'Old ticket%')->count())
        ->toBe(0, 'a ticket past the first chunk kept its subject');
});

test('a bulk review opened before an erasure cannot save the search that found the person', function (): void {
    $f = erasureFixture();
    $label = TicketLabel::factory()->for($f['account'])->create(['name' => 'Callback']);
    $target = User::factory()->for($f['account'])->create(['account_role' => AccountRole::Admin]);
    $search = 'Robin '.ERASURE_MARKER;
    $runs = fn (): array => [DB::table('ticket_bulk_action_runs')->count(), DB::table('conversation_bulk_action_runs')->count()];

    // Both pages carry the search back to the server from the agent's browser.
    $tickets = $this->actingAs($f['admin'])->post(route('dashboard.tickets.bulk.preview'), [
        'ticket_ids' => [$f['ticket']->id], 'action' => 'add_label', 'value' => (string) $label->id,
        'return_query' => ['ticket_status' => 'all', 'ticket_search' => $search],
    ]);
    $conversations = $this->actingAs($f['admin'])->post(route('dashboard.conversations.bulk.preview'), [
        'conversation_ids' => [$f['conversation']->id], 'action' => 'assign_agent', 'value' => (string) $target->id,
        'return_query' => ['conversation_filter' => 'all', 'conversation_search' => $search],
    ]);
    [$ticketRuns, $conversationRuns] = $runs();

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    $this->actingAs($f['admin'])->post(route('dashboard.tickets.bulk.store'), [
        'preview_token' => $tickets->viewData('token'), 'return_search' => $search,
    ])->assertRedirect();
    // The erased conversation is gone, so its page no longer applies at all.
    $this->actingAs($f['admin'])->post(route('dashboard.conversations.bulk.store'), [
        'preview_token' => $conversations->viewData('token'), 'return_search' => $search,
    ])->assertNotFound();

    expect($runs())->toBe([$ticketRuns + 1, $conversationRuns], 'the stripped ticket\'s run was refused, or the erased conversation\'s was not')
        ->and(erasureTablesContaining(ERASURE_MARKER))->toBe([], 'a run saved after the erasure kept the search that found the person');
});

test('a bulk run that selected the person but skipped them loses its search, and one that never selected them keeps it', function (): void {
    $f = erasureFixture();
    $target = User::factory()->for($f['account'])->create(['account_role' => AccountRole::Admin]);
    [$keptTicket, $keptTicketToo] = Ticket::factory()->count(2)->for($f['account'])->for($f['site'])->create()->all();
    $keptToo = Conversation::factory()->for($f['site'])->for($f['bystander'])->create();
    // The person's work already holds each value, so their runs skip it.
    DB::table('conversations')->where('id', $f['conversation']->id)->update(['assigned_agent_id' => $target->id]);
    $theirs = 'Robin '.ERASURE_MARKER;
    $others = 'Kept '.ERASURE_KEEPER;

    $review = function (string $queue, array $ids, string $action, string $value, string $search) use ($f): void {
        $item = $queue === 'tickets' ? 'ticket' : 'conversation';
        $preview = $this->actingAs($f['admin'])->post(route("dashboard.{$queue}.bulk.preview"), [
            "{$item}_ids" => $ids, 'action' => $action, 'value' => $value,
            'return_query' => ["{$item}_search" => $search],
        ]);
        $this->actingAs($f['admin'])->post(route("dashboard.{$queue}.bulk.store"), [
            'preview_token' => $preview->viewData('token'), 'return_search' => $search,
        ])->assertRedirect();
    };

    // Each pair skips its first item, already labelled or assigned.
    $review('tickets', [$f['ticket']->id, $keptTicket->id], 'add_label', (string) $f['label']->id, $theirs);
    $review('tickets', [$keptTicket->id, $keptTicketToo->id], 'add_label', (string) $f['label']->id, $others);
    $review('conversations', [$f['conversation']->id, $f['kept']->id], 'assign_agent', (string) $target->id, $theirs);
    $review('conversations', [$f['kept']->id, $keptToo->id], 'assign_agent', (string) $target->id, $others);

    $searches = fn (string $table, string $key): array => DB::table($table)
        ->where('item_count', 2)->where('changed_count', 1)->orderBy('id')->pluck('return_query')
        ->map(fn (mixed $query): mixed => json_decode((string) $query, true)[$key] ?? null)->all();

    expect($searches('ticket_bulk_action_runs', 'ticket_search'))->toBe([$theirs, $others], 'the runs did not each skip one item')
        ->and($searches('conversation_bulk_action_runs', 'conversation_search'))->toBe([$theirs, $others], 'the runs did not each skip one item');

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    expect($searches('ticket_bulk_action_runs', 'ticket_search'))
        ->toBe([null, $others], 'a ticket run that skipped the person kept its search, or one that never selected them lost it')
        ->and($searches('conversation_bulk_action_runs', 'conversation_search'))
        ->toBe([null, $others], 'a conversation run that skipped the person kept its search, or one that never selected them lost it');
});

test('an older run that cannot say what it skipped loses its search, and one that skipped nothing keeps it', function (): void {
    $f = erasureFixture();
    $kept = Ticket::factory()->for($f['account'])->for($f['site'])->create();
    // Runs from before item_ids: only what they changed is on record.
    $run = fn (int $selected, string $search): int => DB::table('ticket_bulk_action_runs')->insertGetId([
        'account_id' => $f['account']->id, 'triggered_by_user_id' => $f['admin']->id, 'action' => 'set_priority',
        'item_count' => $selected, 'changed_count' => 1,
        'changes' => json_encode([['ticket_id' => $kept->id, 'before' => ['priority' => 'normal'], 'after' => ['priority' => 'high']]]),
        'return_query' => json_encode(['ticket_search' => $search]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $skipped = $run(2, 'Robin '.ERASURE_MARKER);
    $complete = $run(1, 'Kept '.ERASURE_KEEPER);

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    $search = fn (int $id): mixed => json_decode((string) DB::table('ticket_bulk_action_runs')->where('id', $id)->value('return_query'), true)['ticket_search'] ?? null;

    expect($search($skipped))->toBeNull('an older run that skipped an item it never recorded kept its search')
        ->and($search($complete))->toBe('Kept '.ERASURE_KEEPER, 'an older run that recorded all it selected, none of it theirs, lost its search');
});

test('the receipt counts each scrubbed audit event once', function (): void {
    $f = erasureFixture();
    // A reply the visitor sent: about their conversation, and them as its actor.
    AuditEvent::query()->create([
        'account_id' => $f['account']->id, 'site_id' => $f['site']->id,
        'actor_type' => $f['visitor']->getMorphClass(), 'actor_id' => $f['visitor']->id,
        'subject_type' => $f['conversation']->getMorphClass(), 'subject_id' => $f['conversation']->id,
        'action' => 'conversation.visitor_replied', 'metadata' => ['body' => 'Body '.ERASURE_MARKER], 'occurred_at' => now(),
    ]);

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    $scrubbed = AuditEvent::query()->get()->filter(fn (AuditEvent $event): bool => $event->metadata === ['erased' => true])->count();

    expect($scrubbed)->toBeGreaterThan(1)
        ->and(VisitorErasure::query()->sole()->counts['audit_events_scrubbed'])
        ->toBe($scrubbed, 'an event scrubbed as both subject and actor was counted twice');
});

test('a stripped ticket takes the install language, not the erasing agent\'s', function (): void {
    $f = erasureFixture();
    $f['admin']->forceFill(['locale' => 'de'])->save();

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    expect(Ticket::query()->findOrFail($f['ticket']->id)->subject)
        ->toBe('Ticket #'.$f['ticket']->id.' (requester erased)', 'the shared subject was stored in the erasing agent\'s language');
});

test('the in-flight window outlasts every job whose timeout bounds a call to an outside service', function (string $job): void {
    $timeout = (new ReflectionProperty($job, 'timeout'))->getDefaultValue();

    // Twice, not merely longer: margin for a start stamped just after the
    // call began, and for the worker's clock.
    expect(VisitorEraser::IN_FLIGHT_WINDOW_SECONDS)->toBeGreaterThanOrEqual(2 * $timeout);
})->with([
    'note post' => [DeliverTicketExternalComment::class],
    'copilot summary' => [GenerateConversationCopilotSummary::class],
    'copilot reply draft' => [GenerateConversationCopilotReplyDraft::class],
    'copilot ticket suggestion' => [GenerateConversationCopilotTicketSuggestion::class],
    'copilot knowledge suggestion' => [GenerateConversationCopilotKnowledgeSuggestion::class],
]);

test('a copilot request already sending the transcript holds the erasure', function (): void {
    $f = erasureFixture();
    DB::table('conversation_copilot_summaries')->insert([
        'conversation_id' => $f['conversation']->id, 'requested_by_id' => $f['admin']->id,
        'generation' => (string) Str::uuid(), 'status' => 'running',
        'requested_at' => now()->subSeconds(12), 'started_at' => now()->subSeconds(10),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    eraseThroughTheDashboard($f['admin'], $f['visitor'])
        ->assertSessionHasErrors(['confirmation' => __('visitor_erasure.errors.copilot_running')]);

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeTrue('a refused erasure still deleted the contact');

    // Past the window the job has timed out: the request has ended, and the
    // erasure goes ahead and takes the row with the conversation.
    $this->travel(VisitorEraser::IN_FLIGHT_WINDOW_SECONDS + 1)->seconds();

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    expect(DB::table('conversation_copilot_summaries')->count())->toBe(0);
});

test('the audit log names the erasure, and still renders after the scrub', function (): void {
    // What the scrub removes is proven by the sweep above: the account audit
    // views never display note bodies, so a marker check here would pass with
    // or without it. This holds what they do show: a labelled erasure, and no
    // failure on entries whose metadata is now only `{"erased": true}`.
    $f = erasureFixture();

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect();

    $this->get(route('dashboard.account.audit.index'))
        ->assertOk()
        ->assertSee('Contact erased');

    expect($this->get(route('dashboard.account.audit.export'))->assertOk()->streamedContent())
        ->toContain('visitor.erased');
});

test('audit history keeps who did what, and loses what was said', function (): void {
    $f = erasureFixture();

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect();

    $note = AuditEvent::query()->findOrFail($f['note']->id);
    $created = AuditEvent::query()->where('action', 'conversation.created')->sole();

    expect($note->action)->toBe('ticket.note_added')
        ->and((int) $note->actor_id)->toBe((int) $f['admin']->id, 'the agent who acted was forgotten')
        ->and($note->metadata)->toBe(['erased' => true])
        ->and($created->actor_type)->toBeNull('the erased visitor is still named as an actor')
        ->and($created->actor_id)->toBeNull()
        ->and($created->metadata)->toBe(['erased' => true]);
});

test('the erasure is recorded, with a receipt, without recording who was erased', function (): void {
    $f = erasureFixture();

    $response = eraseThroughTheDashboard($f['admin'], $f['visitor']);

    $receipt = VisitorErasure::query()->sole();
    $event = AuditEvent::query()->where('action', 'visitor.erased')->sole();

    $response->assertSessionHas('erasure_receipt', $receipt->public_id);

    expect((int) $receipt->erased_visitor_id)->toBe((int) $f['visitor']->id)
        ->and((int) $receipt->actor_id)->toBe((int) $f['admin']->id)
        ->and($receipt->counts)->toMatchArray([
            'conversations' => 1, 'messages' => 1, 'attachments' => 1, 'ratings' => 1,
            'notes' => 1, 'cobrowse_sessions' => 1, 'tickets_stripped' => 1,
            'notifications' => 2, 'proactive_deliveries' => 2, 'webhook_deliveries_cancelled' => 1,
        ])
        ->and($event->metadata['receipt'])->toBe($receipt->public_id)
        ->and($event->metadata['erased'])->toBe($receipt->counts);

    $this->get(route('dashboard.visitors.index'))
        ->assertOk()
        ->assertSee($receipt->public_id)
        ->assertSee('Contact erased.');
});

test('the ledger names every contact merged into the person, so a restore from before a merge finds them', function (): void {
    $f = erasureFixture();
    $merger = app(VisitorIdentityMerger::class);

    // A chain: the first has no browser ID, so no alias ever records it and
    // only the re-anchored audit history can.
    $first = Visitor::factory()->for($f['site'])->create(['anonymous_id' => null, 'external_id' => null, 'email' => null]);
    $second = Visitor::factory()->for($f['site'])->create(['anonymous_id' => 'browser-second', 'external_id' => null, 'email' => null]);
    $merger->merge($f['admin'], $first, (int) $second->id);
    $merger->merge($f['admin'], $second->refresh(), (int) $f['visitor']->id);

    // History the audit trail does not hold is still read from the alias the
    // widget follows.
    $aliasOnly = Visitor::factory()->for($f['site'])->create();
    $aliasOnlyId = (int) $aliasOnly->id;
    $aliasOnly->delete();
    VisitorIdentityAlias::query()->create([
        'site_id' => $f['site']->id,
        'visitor_id' => $f['visitor']->id,
        'anonymous_id' => 'browser-alias-only',
        'previous_visitor_ids' => [$aliasOnlyId],
    ]);

    eraseThroughTheDashboard($f['admin'], $f['visitor']->refresh())->assertRedirect(route('dashboard.visitors.index'));

    $expected = [(int) $first->id, (int) $second->id, $aliasOnlyId];
    sort($expected);

    expect(VisitorErasure::query()->sole()->merged_visitor_ids)->toBe($expected);
});

test('a receipt the erasure cannot shorten afterwards still reports the erasure as done', function (): void {
    $f = erasureFixture();
    Exceptions::fake();
    // The erasure has committed; then the database refuses the receipt update.
    VisitorErasure::updating(fn () => throw new RuntimeException('the database went away'));

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeFalse()
        ->and(VisitorErasure::query()->sole()->pending_files)->not->toBeEmpty('the receipt lost the files the scheduled run still has to remove');
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'the database went away');
});

test('a file storage would not remove stays on the receipt until the scheduled run removes it', function (): void {
    $f = erasureFixture();
    // The binary lives on a disk that is unreachable at erasure time.
    $f['attachment']->forceFill(['storage_disk' => 'attachments-offline'])->save();

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect(route('dashboard.visitors.index'));

    $receipt = VisitorErasure::query()->sole();
    expect($receipt->pending_files)->toBe([['disk' => 'attachments-offline', 'key' => $f['attachment']->storage_key]], 'a file the erasure could not remove was forgotten');

    // Storage comes back, holding the binary; the next scheduled run takes it.
    Storage::fake('attachments-offline')->put($f['attachment']->storage_key, 'binary');

    $this->artisan('wayfindr:finish-erasures')->assertSuccessful();

    expect(Storage::disk('attachments-offline')->exists($f['attachment']->storage_key))->toBeFalse('the retry left the binary behind')
        ->and($receipt->fresh()->pending_files)->toBeNull();
});

test('a pending webhook delivery for an erased conversation is cancelled, and history stays', function (): void {
    $f = erasureFixture();
    $delivered = OutboundWebhookDelivery::factory()->for($f['pending']->endpoint, 'endpoint')->create([
        'site_id' => $f['site']->id,
        'sequence' => 2,
        'payload' => ['resource' => ['type' => 'conversation', 'support_code' => $f['conversation']->support_code]],
        'delivered_at' => now()->subMinute(),
    ]);

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect();

    expect($f['pending']->fresh()->cancelled_at)->not->toBeNull('a delivery would still announce a conversation that no longer exists')
        ->and($delivered->fresh()->cancelled_at)->toBeNull('delivered history was rewritten');
});

test('webhook replies that could echo the person are cleared, whatever the delivery state', function (): void {
    $f = erasureFixture();

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect();

    expect($f['echoed']->fresh()->response_body)->toBeNull('a delivered reply about their conversation was kept')
        ->and($f['echoedTicket']->fresh()->response_body)->toBeNull('a failed reply about their ticket was kept')
        ->and($f['echoedTicket']->fresh()->payload['resource'])->toBe(['type' => 'ticket', 'id' => $f['ticket']->id], 'delivery history lost its identifiers')
        ->and($f['keptEcho']->fresh()->response_body)->toBe('Kept '.ERASURE_KEEPER, 'a bystander\'s delivery was cleared');
});

test('bulk runs can still be undone, without the search that found the person', function (): void {
    $f = erasureFixture();

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect();

    $runs = DB::table('conversation_bulk_action_runs')->orderBy('id')->get();

    expect(json_decode((string) $runs[0]->return_query, true))->toBe(['conversation_filter' => 'open'], 'the way back lost more than the search')
        ->and(json_decode((string) $runs[0]->changes, true)[0]['conversation_id'])->toBe($f['conversation']->id, 'the run lost what it changed')
        ->and(json_decode((string) DB::table('ticket_bulk_action_runs')->sole()->return_query, true))->toBe(['ticket_status' => 'open'])
        ->and(json_decode((string) $runs[1]->return_query, true))->toBe(['conversation_search' => 'kept '.ERASURE_KEEPER], 'a bystander\'s run lost its search');
});

test('live boards are told to drop the erased contact', function (): void {
    $f = erasureFixture();
    $f['site']->forceFill(['settings' => ['presence' => ['enabled' => true, 'page_urls' => false]]])->save();
    Event::fake([VisitorPresenceUpdated::class]);

    eraseThroughTheDashboard($f['admin'], $f['visitor'])->assertRedirect();

    // Dispatched is not delivered: the event decides for itself whether to
    // broadcast, and it once declined for a visitor that no longer exists --
    // which, after an erasure, is always.
    Event::assertDispatched(
        VisitorPresenceUpdated::class,
        fn (VisitorPresenceUpdated $event): bool => $event->removedVisitorId === (int) $f['visitor']->id
            && $event->broadcastWhen()
            && $event->broadcastWith() === ['visitor' => null, 'removed_visitor_id' => (int) $f['visitor']->id],
    );
});

test('only a data-request handler who supports the site can erase a contact', function (): void {
    $f = erasureFixture();
    $agent = User::factory()->for($f['account'])->create(['account_role' => AccountRole::Agent]);
    $contactsOnly = User::factory()->for($f['account'])->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => CustomRole::factory()->for($f['account'])->create([
            'permissions' => [AccountPermission::ViewConversations->value, AccountPermission::ManageContacts->value],
        ])->id,
    ]);
    $elsewhere = User::factory()->for($f['account'])->create(['account_role' => AccountRole::Admin]);
    $otherSite = Site::factory()->for($f['account'])->create();
    // Both lacking the permission support the site, so their refusal is the
    // permission's; the admin elsewhere lacks the site, so theirs is 404.
    $f['site']->supportAgents()->attach([$f['admin']->id, $agent->id, $contactsOnly->id]);
    $otherSite->supportAgents()->attach($elsewhere);

    foreach ([[$agent, 403], [$contactsOnly, 403], [$elsewhere, 404]] as [$actor, $status]) {
        $this->actingAs($actor)->get(route('dashboard.visitors.erasure.show', $f['visitor']))->assertStatus($status);
        eraseThroughTheDashboard($actor, $f['visitor'])->assertStatus($status);
    }

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeTrue('a refused erasure deleted the contact');

    $this->actingAs($f['admin'])
        ->get(route('dashboard.visitors.show', $f['visitor']))
        ->assertOk()
        ->assertSee(route('dashboard.visitors.erasure.show', $f['visitor']));
    $this->actingAs($contactsOnly)
        ->get(route('dashboard.visitors.show', $f['visitor']))
        ->assertOk()
        ->assertDontSee(route('dashboard.visitors.erasure.show', $f['visitor']));
});

test('erasure needs the typed word and the current password', function (array $input, string $field): void {
    $f = erasureFixture();

    $this->actingAs($f['admin'])
        ->from(route('dashboard.visitors.erasure.show', $f['visitor']))
        ->post(route('dashboard.visitors.erasure.store', $f['visitor']), $input)
        ->assertRedirect(route('dashboard.visitors.erasure.show', $f['visitor']))
        ->assertSessionHasErrors($field);

    expect(Visitor::query()->whereKey($f['visitor']->id)->exists())->toBeTrue('an unconfirmed erasure deleted the contact')
        ->and(VisitorErasure::query()->count())->toBe(0);
})->with([
    'the word in lower case' => [['confirmation' => 'erase', 'current_password' => 'password'], 'confirmation'],
    'no word' => [['current_password' => 'password'], 'confirmation'],
    'a wrong password' => [['confirmation' => 'ERASE', 'current_password' => 'not-it'], 'current_password'],
]);

test('the summary shows what goes, what stays, and what erasure cannot reach', function (): void {
    $f = erasureFixture();

    $this->actingAs($f['admin'])
        ->get(route('dashboard.visitors.erasure.show', $f['visitor']))
        ->assertOk()
        ->assertSee('data-erasure-count="conversations">1<', false)
        ->assertSee('data-erasure-count="attachments">1<', false)
        ->assertSee('data-erasure-ticket="'.$f['ticket']->id.'"', false)
        ->assertSee('https://github.example/acme/app/issues/7')
        ->assertSee('Backups taken before now still hold this person’s data');
});

test('a custom role can hold the permission only alongside managing contacts', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);

    $this->actingAs($owner)
        ->from(route('dashboard.account.roles.index'))
        ->post(route('dashboard.account.roles.store'), [
            'name' => 'Privacy desk',
            'permissions' => [AccountPermission::HandleDataRequests->value],
        ])
        ->assertSessionHasErrors('permissions');

    expect(AccountRole::Admin->permissions())->toContain(AccountPermission::HandleDataRequests)
        ->and(AccountRole::Agent->permissions())->not->toContain(AccountPermission::HandleDataRequests);
});
