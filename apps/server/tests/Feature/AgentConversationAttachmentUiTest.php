<?php

// The agent-side attachment surface: the transcript partial (used by both the
// full conversation page and the live-refresh endpoint) renders attachment
// rows/inline previews, and an agent retrieving a file leaves a deduped
// accountability audit trail.

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('attachments');
});

function agentAttachmentFixture(): array
{
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-AGENTATT',
        'status' => 'open',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Here is the file.',
    ]);

    return compact('account', 'agent', 'site', 'visitor', 'conversation', 'message');
}

test('the transcript renders an inline image attachment with the agent download url', function (): void {
    $f = agentAttachmentFixture();
    $attachment = ConversationMessageAttachment::factory()->forMessage($f['message'])->create([
        'original_filename' => 'diagram.png',
        'mime_type' => 'image/png',
    ]);

    $url = route('dashboard.conversations.attachments.show', [
        'supportCode' => $f['conversation']->support_code,
        'attachment' => $attachment->id,
    ]);

    $this->actingAs($f['agent'])
        ->get(route('dashboard.conversations.messages.index', $f['conversation']->support_code))
        ->assertOk()
        ->assertSee('message-attachment-image', false)
        ->assertSee($url, false)
        ->assertSee('diagram.png');
});

test('the transcript renders a non-image attachment as a download row', function (): void {
    $f = agentAttachmentFixture();
    $attachment = ConversationMessageAttachment::factory()->forMessage($f['message'])->create([
        'original_filename' => 'report.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $this->actingAs($f['agent'])
        ->get(route('dashboard.conversations.messages.index', $f['conversation']->support_code))
        ->assertOk()
        ->assertSee('message-attachment-file', false)
        ->assertSee('report.pdf');
});

test('an agent downloading a file records a deduped accountability audit', function (): void {
    $f = agentAttachmentFixture();
    $attachment = ConversationMessageAttachment::factory()->forMessage($f['message'])->create([
        'original_filename' => 'proof.png',
        'mime_type' => 'image/png',
    ]);
    Storage::disk('attachments')->put($attachment->storage_key, 'bytes');

    $url = route('dashboard.conversations.attachments.show', [
        'supportCode' => $f['conversation']->support_code,
        'attachment' => $attachment->id,
    ]);

    // Two retrievals (e.g. inline preview + an explicit open) audit only once.
    $this->actingAs($f['agent'])->get($url)->assertOk();
    $this->actingAs($f['agent'])->get($url)->assertOk();

    $events = AuditEvent::where('action', 'attachment.downloaded')
        ->where('actor_type', (new User)->getMorphClass())
        ->where('actor_id', $f['agent']->id)
        ->where('metadata->attachment_id', $attachment->id)
        ->get();

    expect($events)->toHaveCount(1)
        ->and(data_get($events->first()->metadata, 'filename'))->toBe('proof.png');
});

test('a download that 404s on a missing binary records no audit', function (): void {
    $f = agentAttachmentFixture();
    $attachment = ConversationMessageAttachment::factory()->forMessage($f['message'])->create();
    // The row is ready/bound but the stored object is gone (e.g. swept).
    Storage::disk('attachments')->assertMissing($attachment->storage_key);

    $url = route('dashboard.conversations.attachments.show', [
        'supportCode' => $f['conversation']->support_code,
        'attachment' => $attachment->id,
    ]);

    $this->actingAs($f['agent'])->get($url)->assertNotFound();

    expect(AuditEvent::where('action', 'attachment.downloaded')->count())->toBe(0);
});

test('a different agent downloading the same file records its own audit', function (): void {
    $f = agentAttachmentFixture();
    $secondAgent = User::factory()->for($f['account'])->create();
    $attachment = ConversationMessageAttachment::factory()->forMessage($f['message'])->create();
    Storage::disk('attachments')->put($attachment->storage_key, 'bytes');

    $url = route('dashboard.conversations.attachments.show', [
        'supportCode' => $f['conversation']->support_code,
        'attachment' => $attachment->id,
    ]);

    $this->actingAs($f['agent'])->get($url)->assertOk();
    $this->actingAs($secondAgent)->get($url)->assertOk();

    expect(AuditEvent::where('action', 'attachment.downloaded')->where('metadata->attachment_id', $attachment->id)->count())->toBe(2);
});

test('a linked-ticket transcript renders the conversation attachments', function (): void {
    $f = agentAttachmentFixture();
    $attachment = ConversationMessageAttachment::factory()->forMessage($f['message'])->create([
        'original_filename' => 'ticketed.png',
        'mime_type' => 'image/png',
    ]);
    $ticket = Ticket::factory()->for($f['account'])->for($f['site'])->create([
        'conversation_id' => $f['conversation']->id,
    ]);

    $url = route('dashboard.conversations.attachments.show', [
        'supportCode' => $f['conversation']->support_code,
        'attachment' => $attachment->id,
    ]);

    $this->actingAs($f['agent'])
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('message-attachment-image', false)
        ->assertSee($url, false)
        ->assertSee('ticketed.png');
});

test('the full conversation page renders attachments', function (): void {
    $f = agentAttachmentFixture();
    $attachment = ConversationMessageAttachment::factory()->forMessage($f['message'])->create([
        'original_filename' => 'screenshot.png',
        'mime_type' => 'image/png',
    ]);

    $this->actingAs($f['agent'])
        ->get(route('dashboard.conversations.show', $f['conversation']->support_code))
        ->assertOk()
        ->assertSee('message-attachment-image', false)
        ->assertSee('screenshot.png')
        // The composer gains the attach control.
        ->assertSee('data-reply-attach', false)
        ->assertSee('data-attachments-url', false);
});
