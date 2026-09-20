<?php

// Deleting a not-yet-sent upload (ADR 0007): when a party removes an attachment
// chip before sending, the client deletes the unbound upload so it stops
// counting against the per-conversation quota. Only an unbound attachment the
// requester uploaded may be deleted — a bound attachment is transcript content,
// and another party's upload is not theirs to remove.

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\VisitorSessionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('attachments');
});

function deleteFixture(): array
{
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create(['public_key' => 'site_public_'.Str::lower((string) Str::ulid())]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-'.Str::lower((string) Str::ulid())]);
    $conversation = Conversation::factory()->for($site)->create([
        'visitor_id' => $visitor->id,
        'support_code' => 'WF-'.strtoupper(Str::random(8)),
    ]);

    return compact('account', 'site', 'visitor', 'conversation');
}

function deleteTestToken(Site $site, Visitor $visitor): string
{
    return app(VisitorSessionToken::class)->issue($site, $visitor);
}

function visitorDeleteUrl(Conversation $conversation, ConversationMessageAttachment $attachment, array $query): string
{
    return "/api/conversations/{$conversation->support_code}/attachments/{$attachment->id}?".http_build_query($query);
}

function seedAttachmentFile(ConversationMessageAttachment $attachment): void
{
    Storage::disk('attachments')->put($attachment->storage_key, 'bytes');
}

// --- Visitor delete -------------------------------------------------------

test('a visitor deletes their own unbound upload and frees its quota', function (): void {
    $f = deleteFixture();
    $attachment = ConversationMessageAttachment::factory()->pendingFor($f['conversation'], $f['visitor'])->create(['size_bytes' => 5000]);
    seedAttachmentFile($attachment);
    $token = deleteTestToken($f['site'], $f['visitor']);
    conversationOwnedBySession($f['conversation'], $token);

    expect((int) ConversationMessageAttachment::where('conversation_id', $f['conversation']->id)->sum('size_bytes'))->toBe(5000);

    $this->delete(visitorDeleteUrl($f['conversation'], $attachment, [
        'site_public_key' => $f['site']->public_key,
        'anonymous_id' => $f['visitor']->anonymous_id,
        'visitor_token' => $token,
    ]))->assertNoContent();

    expect(ConversationMessageAttachment::whereKey($attachment->id)->exists())->toBeFalse();
    Storage::disk('attachments')->assertMissing($attachment->storage_key);
    // Quota reclaimed.
    expect((int) ConversationMessageAttachment::where('conversation_id', $f['conversation']->id)->sum('size_bytes'))->toBe(0);
});

test('a visitor cannot delete a bound (sent) attachment', function (): void {
    $f = deleteFixture();
    $message = ConversationMessage::factory()->for($f['conversation'])->create([
        'sender_type' => Visitor::class,
        'sender_id' => $f['visitor']->id,
    ]);
    $attachment = ConversationMessageAttachment::factory()->forMessage($message)->create();
    seedAttachmentFile($attachment);
    // Owned by the requesting session, so the refusal below is the bound-attachment
    // rule this test names and not the conversation-ownership gate in front of it.
    $token = deleteTestToken($f['site'], $f['visitor']);
    conversationOwnedBySession($f['conversation'], $token);

    $this->delete(visitorDeleteUrl($f['conversation'], $attachment, [
        'site_public_key' => $f['site']->public_key,
        'anonymous_id' => $f['visitor']->anonymous_id,
        'visitor_token' => $token,
    ]))->assertNotFound();

    expect(ConversationMessageAttachment::whereKey($attachment->id)->exists())->toBeTrue();
    Storage::disk('attachments')->assertExists($attachment->storage_key);
});

test('a visitor cannot delete an upload another visitor made', function (): void {
    $f = deleteFixture();
    $intruder = Visitor::factory()->for($f['site'])->create(['anonymous_id' => 'anon-intruder']);
    // A pending upload owned by the intruder, but living in the same conversation.
    $attachment = ConversationMessageAttachment::factory()->pendingFor($f['conversation'], $intruder)->create();
    seedAttachmentFile($attachment);
    // The requester owns the CONVERSATION -- they reach it legitimately. What they
    // do not own is this upload, which is the whole point of the test.
    $token = deleteTestToken($f['site'], $f['visitor']);
    conversationOwnedBySession($f['conversation'], $token);

    $this->delete(visitorDeleteUrl($f['conversation'], $attachment, [
        'site_public_key' => $f['site']->public_key,
        'anonymous_id' => $f['visitor']->anonymous_id,
        'visitor_token' => $token,
    ]))->assertNotFound();

    expect(ConversationMessageAttachment::whereKey($attachment->id)->exists())->toBeTrue();
});

test('an unauthenticated visitor cannot delete an upload', function (): void {
    $f = deleteFixture();
    $attachment = ConversationMessageAttachment::factory()->pendingFor($f['conversation'], $f['visitor'])->create();
    // The conversation has an owning session, as one opened through the widget
    // always does. This request simply presents no credential for it, so the
    // refusal is the missing token rather than an unowned fixture.
    conversationOwnedBySession($f['conversation'], deleteTestToken($f['site'], $f['visitor']));

    $this->delete(visitorDeleteUrl($f['conversation'], $attachment, [
        'site_public_key' => $f['site']->public_key,
        'anonymous_id' => $f['visitor']->anonymous_id,
    ]))->assertStatus(401);

    expect(ConversationMessageAttachment::whereKey($attachment->id)->exists())->toBeTrue();
});

// --- Agent delete ---------------------------------------------------------

test('an agent deletes their own unbound upload', function (): void {
    $f = deleteFixture();
    $agent = User::factory()->for($f['account'])->create();
    $attachment = ConversationMessageAttachment::factory()->pendingFor($f['conversation'], $agent)->create();
    seedAttachmentFile($attachment);

    $this->actingAs($agent)
        ->delete(route('dashboard.conversations.attachments.destroy', [
            'supportCode' => $f['conversation']->support_code,
            'attachment' => $attachment->id,
        ]))->assertNoContent();

    expect(ConversationMessageAttachment::whereKey($attachment->id)->exists())->toBeFalse();
    Storage::disk('attachments')->assertMissing($attachment->storage_key);
});

test('an agent cannot delete the visitor\'s upload', function (): void {
    $f = deleteFixture();
    $agent = User::factory()->for($f['account'])->create();
    $attachment = ConversationMessageAttachment::factory()->pendingFor($f['conversation'], $f['visitor'])->create();
    seedAttachmentFile($attachment);

    $this->actingAs($agent)
        ->delete(route('dashboard.conversations.attachments.destroy', [
            'supportCode' => $f['conversation']->support_code,
            'attachment' => $attachment->id,
        ]))->assertNotFound();

    expect(ConversationMessageAttachment::whereKey($attachment->id)->exists())->toBeTrue();
});

test('an agent outside the site support scope cannot delete', function (): void {
    $f = deleteFixture();
    $supporting = User::factory()->for($f['account'])->create();
    $f['site']->supportAgents()->attach($supporting);
    $outsider = User::factory()->for($f['account'])->create();
    $attachment = ConversationMessageAttachment::factory()->pendingFor($f['conversation'], $outsider)->create();
    seedAttachmentFile($attachment);

    $this->actingAs($outsider)
        ->delete(route('dashboard.conversations.attachments.destroy', [
            'supportCode' => $f['conversation']->support_code,
            'attachment' => $attachment->id,
        ]))->assertNotFound();

    expect(ConversationMessageAttachment::whereKey($attachment->id)->exists())->toBeTrue();
});
