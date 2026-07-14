<?php

// The access boundary for conversation message attachments (ADR 0007), local
// storage surface. Isolation is the whole point of this feature: a visitor
// reaches only their own conversation's attachments; an agent only a
// conversation they support; and there is no unauthenticated or public path.
// These tests assert every leg of that boundary — cross-session, cross-visitor,
// cross-site-agent, cross-account, deactivated, and unauthenticated — plus the
// hardened response headers.

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

/**
 * Build a site + visitor + conversation + message + stored attachment. Returns
 * everything a test needs to exercise either access path.
 */
function attachmentFixture(array $siteOverrides = [], array $visitorOverrides = [], array $conversationOverrides = []): array
{
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create(array_replace([
        'public_key' => 'site_public_'.Str::lower((string) Str::ulid()),
    ], $siteOverrides));
    $visitor = Visitor::factory()->for($site)->create(array_replace([
        'anonymous_id' => 'anon-'.Str::lower((string) Str::ulid()),
    ], $visitorOverrides));
    $conversation = Conversation::factory()->for($site)->create(array_replace([
        'visitor_id' => $visitor->id,
        'support_code' => 'WF-'.strtoupper(Str::random(8)),
    ], $conversationOverrides));
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
    ]);

    $key = 'attachments/'.Str::lower((string) Str::ulid()).'/proof.png';
    $bytes = 'PNG-BYTES-'.$conversation->support_code;
    Storage::disk('attachments')->put($key, $bytes);

    $attachment = ConversationMessageAttachment::factory()->forMessage($message)->create([
        'storage_disk' => 'attachments',
        'storage_key' => $key,
        'original_filename' => 'proof.png',
        'mime_type' => 'image/png',
    ]);

    return compact('account', 'site', 'visitor', 'conversation', 'message', 'attachment', 'bytes');
}

function visitorToken(Site $site, Visitor $visitor): string
{
    return app(VisitorSessionToken::class)->issue($site, $visitor);
}

function visitorAttachmentUrl(Conversation $conversation, ConversationMessageAttachment $attachment, array $query): string
{
    return "/api/conversations/{$conversation->support_code}/attachments/{$attachment->id}?".http_build_query($query);
}

function agentAttachmentUrl(Conversation $conversation, ConversationMessageAttachment $attachment): string
{
    return "/dashboard/conversations/{$conversation->support_code}/attachments/{$attachment->id}";
}

// --- Visitor path ---------------------------------------------------------

test('the owning visitor downloads their attachment with hardened headers', function (): void {
    $fixture = attachmentFixture();

    $response = $this->get(visitorAttachmentUrl($fixture['conversation'], $fixture['attachment'], [
        'site_public_key' => $fixture['site']->public_key,
        'anonymous_id' => $fixture['visitor']->anonymous_id,
        'visitor_token' => visitorToken($fixture['site'], $fixture['visitor']),
    ]));

    $response->assertOk()
        ->assertDownload('proof.png')
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->streamedContent())->toBe($fixture['bytes'])
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment');
});

test('an unauthenticated visitor request is rejected', function (): void {
    $fixture = attachmentFixture();

    // No visitor_token at all.
    $this->get(visitorAttachmentUrl($fixture['conversation'], $fixture['attachment'], [
        'site_public_key' => $fixture['site']->public_key,
        'anonymous_id' => $fixture['visitor']->anonymous_id,
    ]))->assertStatus(401);
});

test('a token issued for another site cannot reach the attachment', function (): void {
    $fixture = attachmentFixture();
    $otherSite = Site::factory()->create(['public_key' => 'site_public_intruder']);
    $otherVisitor = Visitor::factory()->for($otherSite)->create(['anonymous_id' => $fixture['visitor']->anonymous_id]);

    // Present the victim site's public key but a token minted for another site.
    $this->get(visitorAttachmentUrl($fixture['conversation'], $fixture['attachment'], [
        'site_public_key' => $fixture['site']->public_key,
        'anonymous_id' => $fixture['visitor']->anonymous_id,
        'visitor_token' => visitorToken($otherSite, $otherVisitor),
    ]))->assertStatus(403);
});

test('a different visitor cannot fetch another session\'s attachment via the support code', function (): void {
    $fixture = attachmentFixture();

    // A second visitor on the SAME site with their own valid token.
    $intruder = Visitor::factory()->for($fixture['site'])->create(['anonymous_id' => 'anon-intruder']);

    $this->get(visitorAttachmentUrl($fixture['conversation'], $fixture['attachment'], [
        'site_public_key' => $fixture['site']->public_key,
        'anonymous_id' => $intruder->anonymous_id,
        'visitor_token' => visitorToken($fixture['site'], $intruder),
    ]))->assertNotFound();
});

test('a visitor cannot fetch an attachment from a conversation that is not theirs', function (): void {
    $victim = attachmentFixture();

    // The intruder has their own conversation on the same site, and asks for it
    // by their own support code — but with the victim's attachment id. Their own
    // conversation resolves fine; the victim's attachment simply isn't in it.
    $intruderVisitor = Visitor::factory()->for($victim['site'])->create(['anonymous_id' => 'anon-own']);
    $intruderConversation = Conversation::factory()->for($victim['site'])->create([
        'visitor_id' => $intruderVisitor->id,
        'support_code' => 'WF-OWNCONV',
    ]);

    $this->get(visitorAttachmentUrl($intruderConversation, $victim['attachment'], [
        'site_public_key' => $victim['site']->public_key,
        'anonymous_id' => $intruderVisitor->anonymous_id,
        'visitor_token' => visitorToken($victim['site'], $intruderVisitor),
    ]))->assertNotFound();
});

test('a quarantined attachment is not downloadable even by its owner', function (): void {
    $fixture = attachmentFixture();
    $fixture['attachment']->update(['status' => ConversationMessageAttachment::STATUS_QUARANTINED]);

    $this->get(visitorAttachmentUrl($fixture['conversation'], $fixture['attachment'], [
        'site_public_key' => $fixture['site']->public_key,
        'anonymous_id' => $fixture['visitor']->anonymous_id,
        'visitor_token' => visitorToken($fixture['site'], $fixture['visitor']),
    ]))->assertNotFound();
});

test('a row whose binary is gone returns not found rather than erroring', function (): void {
    $fixture = attachmentFixture();
    Storage::disk('attachments')->delete($fixture['attachment']->storage_key);

    $this->get(visitorAttachmentUrl($fixture['conversation'], $fixture['attachment'], [
        'site_public_key' => $fixture['site']->public_key,
        'anonymous_id' => $fixture['visitor']->anonymous_id,
        'visitor_token' => visitorToken($fixture['site'], $fixture['visitor']),
    ]))->assertNotFound();
});

// --- Agent path -----------------------------------------------------------

test('an agent who supports the site downloads the attachment', function (): void {
    $fixture = attachmentFixture();
    $agent = User::factory()->create(['account_id' => $fixture['account']->id]);

    $response = $this->actingAs($agent)->get(agentAttachmentUrl($fixture['conversation'], $fixture['attachment']));

    $response->assertOk()
        ->assertDownload('proof.png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->streamedContent())->toBe($fixture['bytes']);
});

test('an agent in the account but outside the site support scope gets a 404', function (): void {
    $fixture = attachmentFixture();

    // Make support explicit and assign a DIFFERENT agent, so the site no longer
    // supports every account agent by default.
    $supporting = User::factory()->create(['account_id' => $fixture['account']->id]);
    $fixture['site']->supportAgents()->attach($supporting);

    $outsider = User::factory()->create(['account_id' => $fixture['account']->id]);

    $this->actingAs($outsider)
        ->get(agentAttachmentUrl($fixture['conversation'], $fixture['attachment']))
        ->assertNotFound();
});

test('an agent from another account cannot reach the attachment', function (): void {
    $fixture = attachmentFixture();
    $otherAccount = Account::factory()->create();
    $foreignAgent = User::factory()->create(['account_id' => $otherAccount->id]);

    $this->actingAs($foreignAgent)
        ->get(agentAttachmentUrl($fixture['conversation'], $fixture['attachment']))
        ->assertNotFound();
});

test('a deactivated agent is bounced to login, never served the file', function (): void {
    $fixture = attachmentFixture();
    $agent = User::factory()->create([
        'account_id' => $fixture['account']->id,
        'deactivated_at' => now(),
    ]);

    $this->actingAs($agent)
        ->get(agentAttachmentUrl($fixture['conversation'], $fixture['attachment']))
        ->assertRedirect(route('login'));
});

test('an unauthenticated agent request is redirected to login', function (): void {
    $fixture = attachmentFixture();

    $this->get(agentAttachmentUrl($fixture['conversation'], $fixture['attachment']))
        ->assertRedirect(route('login'));
});

test('a supporting agent cannot fetch an attachment from another conversation via a mismatched id', function (): void {
    $fixture = attachmentFixture();
    $agent = User::factory()->create(['account_id' => $fixture['account']->id]);

    // A second conversation the agent DOES support (same account/site), but the
    // requested attachment id belongs to the first conversation.
    $otherVisitor = Visitor::factory()->for($fixture['site'])->create(['anonymous_id' => 'anon-second']);
    $otherConversation = Conversation::factory()->for($fixture['site'])->create([
        'visitor_id' => $otherVisitor->id,
        'support_code' => 'WF-SECOND',
    ]);

    $this->actingAs($agent)
        ->get(agentAttachmentUrl($otherConversation, $fixture['attachment']))
        ->assertNotFound();
});
