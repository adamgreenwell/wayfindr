<?php

// The two-step upload for conversation message attachments (ADR 0007): a file
// is uploaded first as a pending (unbound) attachment, then a message send
// binds it. These tests cover the server-side validation (byte-sniffed MIME
// allowlist, size and per-conversation limits), the scoped upload endpoints,
// bind-on-send for both visitor and agent, idempotency, and the rule that a
// not-yet-sent upload is visible only to its uploader.

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\VisitorSessionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('attachments');
});

function uploadFixture(array $siteOverrides = []): array
{
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create(array_replace([
        'public_key' => 'site_public_'.Str::lower((string) Str::ulid()),
    ], $siteOverrides));
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-'.Str::lower((string) Str::ulid())]);
    $conversation = Conversation::factory()->for($site)->create([
        'visitor_id' => $visitor->id,
        'support_code' => 'WF-'.strtoupper(Str::random(8)),
    ]);

    return compact('account', 'site', 'visitor', 'conversation');
}

function tokenFor(Site $site, Visitor $visitor): string
{
    return app(VisitorSessionToken::class)->issue($site, $visitor);
}

/**
 * A real (not faked) uploaded file whose bytes are written to disk, so
 * getMimeType() sniffs the actual content via finfo — the way production does.
 * The faked UploadedFile guesses MIME from the extension, which cannot exercise
 * byte-sniffing. Give it a deliberately misleading name to prove the server
 * trusts the bytes, not the extension.
 */
function realUpload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'wf-upload');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

function uploadUrl(Conversation $conversation): string
{
    return "/api/conversations/{$conversation->support_code}/attachments";
}

function visitorUpload(Conversation $conversation, Site $site, Visitor $visitor, UploadedFile $file, $test)
{
    // Accept JSON so a validation failure renders as 422 (as the widget's XHR
    // receives it) rather than a redirect.
    return $test->post(uploadUrl($conversation), [
        'site_public_key' => $site->public_key,
        'anonymous_id' => $visitor->anonymous_id,
        'visitor_token' => tokenFor($site, $visitor),
        'file' => $file,
    ], ['Accept' => 'application/json']);
}

// --- Visitor upload -------------------------------------------------------

test('a visitor uploads an image as a pending, unbound attachment', function (): void {
    $f = uploadFixture();

    $response = visitorUpload($f['conversation'], $f['site'], $f['visitor'], UploadedFile::fake()->image('shot.png'), $this);

    $response->assertCreated()
        ->assertJsonPath('data.attachment.filename', 'shot.png')
        ->assertJsonPath('data.attachment.mime_type', 'image/png')
        ->assertJsonPath('data.attachment.is_image', true)
        ->assertJsonPath('data.attachment.status', 'ready');

    $attachment = ConversationMessageAttachment::first();

    expect($attachment)->not->toBeNull()
        ->and($attachment->conversation_id)->toBe($f['conversation']->id)
        ->and($attachment->account_id)->toBe($f['account']->id)
        ->and($attachment->site_id)->toBe($f['site']->id)
        ->and($attachment->conversation_message_id)->toBeNull()
        ->and($attachment->uploaded_by_type)->toBe((new Visitor)->getMorphClass())
        ->and($attachment->uploaded_by_id)->toBe($f['visitor']->id)
        ->and($attachment->storage_disk)->toBe('attachments');

    Storage::disk('attachments')->assertExists($attachment->storage_key);
});

test('a plain text upload is allowed based on its sniffed content, not extension', function (): void {
    $f = uploadFixture();

    // Extension says .bin; the bytes are plain text, which is what counts.
    visitorUpload($f['conversation'], $f['site'], $f['visitor'], realUpload('data.bin', 'hello there'), $this)
        ->assertCreated()
        ->assertJsonPath('data.attachment.mime_type', 'text/plain');
});

test('an SVG disguised with an image extension is rejected by the sniffed-type allowlist', function (): void {
    $f = uploadFixture();

    // Extension says .png; the bytes are SVG (active content), so it is rejected.
    visitorUpload(
        $f['conversation'],
        $f['site'],
        $f['visitor'],
        realUpload('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        $this,
    )->assertStatus(422);

    expect(ConversationMessageAttachment::count())->toBe(0);
    expect(Storage::disk('attachments')->allFiles())->toBe([]);
});

test('an HTML file disguised with an image extension is rejected', function (): void {
    $f = uploadFixture();

    visitorUpload(
        $f['conversation'],
        $f['site'],
        $f['visitor'],
        realUpload('sneaky.png', '<!DOCTYPE html><html><head><title>x</title></head><body><script>alert(1)</script></body></html>'),
        $this,
    )->assertStatus(422);

    expect(ConversationMessageAttachment::count())->toBe(0);
});

test('an oversize file is rejected before it is stored', function (): void {
    config(['wayfindr.attachments.max_file_bytes' => 1024 * 1024]); // 1 MB
    $f = uploadFixture();

    // 2 MB fake file (size-only) trips the max rule.
    visitorUpload($f['conversation'], $f['site'], $f['visitor'], UploadedFile::fake()->create('big.png', 2048), $this)
        ->assertStatus(422);

    expect(ConversationMessageAttachment::count())->toBe(0);
});

test('the per-conversation storage cap is enforced', function (): void {
    config(['wayfindr.attachments.max_conversation_bytes' => 1000]); // tiny
    $f = uploadFixture();

    // Seed an existing attachment that already fills the cap, so any further
    // upload must be rejected.
    ConversationMessageAttachment::factory()
        ->pendingFor($f['conversation'], $f['visitor'])
        ->create(['size_bytes' => 1000]);

    visitorUpload($f['conversation'], $f['site'], $f['visitor'], UploadedFile::fake()->image('shot.png'), $this)
        ->assertStatus(422);
});

test('an unauthenticated visitor cannot upload', function (): void {
    $f = uploadFixture();

    $this->post(uploadUrl($f['conversation']), [
        'site_public_key' => $f['site']->public_key,
        'anonymous_id' => $f['visitor']->anonymous_id,
        'file' => UploadedFile::fake()->image('shot.png'),
    ])->assertStatus(401);

    expect(ConversationMessageAttachment::count())->toBe(0);
});

test('a visitor cannot upload to a conversation that is not theirs', function (): void {
    $f = uploadFixture();
    $intruder = Visitor::factory()->for($f['site'])->create(['anonymous_id' => 'anon-intruder']);

    $this->post(uploadUrl($f['conversation']), [
        'site_public_key' => $f['site']->public_key,
        'anonymous_id' => $intruder->anonymous_id,
        'visitor_token' => tokenFor($f['site'], $intruder),
        'file' => UploadedFile::fake()->image('shot.png'),
    ])->assertNotFound();
});

// --- Bind on send (visitor) ----------------------------------------------

test('sending a message binds the visitor\'s pending upload to it', function (): void {
    $f = uploadFixture();
    $attachment = ConversationMessageAttachment::factory()->pendingFor($f['conversation'], $f['visitor'])->create();

    $this->postJson("/api/conversations/{$f['conversation']->support_code}/messages", [
        'site_public_key' => $f['site']->public_key,
        'anonymous_id' => $f['visitor']->anonymous_id,
        'visitor_token' => tokenFor($f['site'], $f['visitor']),
        'body' => 'Here is the screenshot.',
        'attachment_ids' => [$attachment->id],
    ])->assertCreated()
        ->assertJsonPath('data.message.attachments.0.id', $attachment->id);

    expect($attachment->fresh()->conversation_message_id)->not->toBeNull();
});

test('a message may carry only an attachment and no text', function (): void {
    $f = uploadFixture();
    $attachment = ConversationMessageAttachment::factory()->pendingFor($f['conversation'], $f['visitor'])->create();

    $this->postJson("/api/conversations/{$f['conversation']->support_code}/messages", [
        'site_public_key' => $f['site']->public_key,
        'anonymous_id' => $f['visitor']->anonymous_id,
        'visitor_token' => tokenFor($f['site'], $f['visitor']),
        'attachment_ids' => [$attachment->id],
    ])->assertCreated();

    expect($attachment->fresh()->conversation_message_id)->not->toBeNull();
});

test('a message with neither text nor attachment is rejected', function (): void {
    $f = uploadFixture();

    $this->postJson("/api/conversations/{$f['conversation']->support_code}/messages", [
        'site_public_key' => $f['site']->public_key,
        'anonymous_id' => $f['visitor']->anonymous_id,
        'visitor_token' => tokenFor($f['site'], $f['visitor']),
    ])->assertStatus(422);
});

test('binding another visitor\'s upload is rejected and rolls the send back', function (): void {
    $f = uploadFixture();
    $other = uploadFixture(['public_key' => 'site_public_other']);
    // A pending upload owned by a different visitor in a different conversation.
    $foreign = ConversationMessageAttachment::factory()->pendingFor($other['conversation'], $other['visitor'])->create();

    $this->postJson("/api/conversations/{$f['conversation']->support_code}/messages", [
        'site_public_key' => $f['site']->public_key,
        'anonymous_id' => $f['visitor']->anonymous_id,
        'visitor_token' => tokenFor($f['site'], $f['visitor']),
        'body' => 'Trying to attach someone else\'s file.',
        'attachment_ids' => [$foreign->id],
    ])->assertStatus(422);

    // The send rolled back: no message was created in this conversation.
    expect($f['conversation']->messages()->count())->toBe(0)
        ->and($foreign->fresh()->conversation_message_id)->toBeNull();
});

test('exceeding the per-message attachment count is rejected', function (): void {
    config(['wayfindr.attachments.max_per_message' => 1]);
    $f = uploadFixture();
    $a = ConversationMessageAttachment::factory()->pendingFor($f['conversation'], $f['visitor'])->create();
    $b = ConversationMessageAttachment::factory()->pendingFor($f['conversation'], $f['visitor'])->create();

    $this->postJson("/api/conversations/{$f['conversation']->support_code}/messages", [
        'site_public_key' => $f['site']->public_key,
        'anonymous_id' => $f['visitor']->anonymous_id,
        'visitor_token' => tokenFor($f['site'], $f['visitor']),
        'body' => 'Too many.',
        'attachment_ids' => [$a->id, $b->id],
    ])->assertStatus(422);

    expect($f['conversation']->messages()->count())->toBe(0);
});

test('an idempotent retry binds the attachment exactly once', function (): void {
    $f = uploadFixture();
    $attachment = ConversationMessageAttachment::factory()->pendingFor($f['conversation'], $f['visitor'])->create();

    $payload = [
        'site_public_key' => $f['site']->public_key,
        'anonymous_id' => $f['visitor']->anonymous_id,
        'visitor_token' => tokenFor($f['site'], $f['visitor']),
        'body' => 'Screenshot attached.',
        'client_message_id' => 'client-abc',
        'attachment_ids' => [$attachment->id],
    ];

    $this->postJson("/api/conversations/{$f['conversation']->support_code}/messages", $payload)->assertCreated();
    $this->postJson("/api/conversations/{$f['conversation']->support_code}/messages", $payload)->assertCreated();

    expect($f['conversation']->messages()->count())->toBe(1)
        ->and(ConversationMessageAttachment::where('conversation_message_id', '!=', null)->count())->toBe(1);
});

test('an already-bound attachment cannot be re-bound to another message', function (): void {
    $f = uploadFixture();
    $attachment = ConversationMessageAttachment::factory()->pendingFor($f['conversation'], $f['visitor'])->create();

    $base = [
        'site_public_key' => $f['site']->public_key,
        'anonymous_id' => $f['visitor']->anonymous_id,
        'visitor_token' => tokenFor($f['site'], $f['visitor']),
        'attachment_ids' => [$attachment->id],
    ];

    $this->postJson("/api/conversations/{$f['conversation']->support_code}/messages", $base + [
        'body' => 'First.',
        'client_message_id' => 'first',
    ])->assertCreated();

    $firstMessageId = $attachment->fresh()->conversation_message_id;

    // A second, distinct send referencing the now-bound id is rejected...
    $this->postJson("/api/conversations/{$f['conversation']->support_code}/messages", $base + [
        'body' => 'Second.',
        'client_message_id' => 'second',
    ])->assertStatus(422);

    // ...and the attachment stays on the first message.
    expect($attachment->fresh()->conversation_message_id)->toBe($firstMessageId);
});

test('deleting a conversation cascades its attachments', function (): void {
    $f = uploadFixture();
    $message = ConversationMessage::factory()->for($f['conversation'])->create();
    $attachment = ConversationMessageAttachment::factory()->forMessage($message)->create();

    $f['conversation']->delete();

    expect(ConversationMessageAttachment::whereKey($attachment->id)->exists())->toBeFalse();
});

// --- Not-yet-sent visibility ---------------------------------------------

test('an uploader may preview their own unsent upload but the other party may not', function (): void {
    $f = uploadFixture();
    $agent = User::factory()->create(['account_id' => $f['account']->id]);
    $attachment = ConversationMessageAttachment::factory()->pendingFor($f['conversation'], $f['visitor'])->create();
    Storage::disk('attachments')->put($attachment->storage_key, 'bytes');

    // The uploader (visitor) can preview it.
    $this->get("/api/conversations/{$f['conversation']->support_code}/attachments/{$attachment->id}?".http_build_query([
        'site_public_key' => $f['site']->public_key,
        'anonymous_id' => $f['visitor']->anonymous_id,
        'visitor_token' => tokenFor($f['site'], $f['visitor']),
    ]))->assertOk();

    // The agent cannot — it is not bound to a message yet, and they did not
    // upload it.
    $this->actingAs($agent)
        ->get("/dashboard/conversations/{$f['conversation']->support_code}/attachments/{$attachment->id}")
        ->assertNotFound();
});

// --- Agent upload ---------------------------------------------------------

test('a supporting agent uploads a pending attachment owned by them', function (): void {
    $f = uploadFixture();
    $agent = User::factory()->create(['account_id' => $f['account']->id]);

    $this->actingAs($agent)
        ->post("/dashboard/conversations/{$f['conversation']->support_code}/attachments", [
            'file' => UploadedFile::fake()->image('reply.png'),
        ])->assertCreated()
        ->assertJsonPath('data.attachment.mime_type', 'image/png');

    $attachment = ConversationMessageAttachment::first();

    expect($attachment->uploaded_by_type)->toBe((new User)->getMorphClass())
        ->and($attachment->uploaded_by_id)->toBe($agent->id)
        ->and($attachment->conversation_message_id)->toBeNull();
});

test('an agent outside the site support scope cannot upload', function (): void {
    $f = uploadFixture();
    $supporting = User::factory()->create(['account_id' => $f['account']->id]);
    $f['site']->supportAgents()->attach($supporting);
    $outsider = User::factory()->create(['account_id' => $f['account']->id]);

    $this->actingAs($outsider)
        ->post("/dashboard/conversations/{$f['conversation']->support_code}/attachments", [
            'file' => UploadedFile::fake()->image('reply.png'),
        ])->assertNotFound();

    expect(ConversationMessageAttachment::count())->toBe(0);
});

test('an agent reply binds the agent\'s pending upload', function (): void {
    $f = uploadFixture();
    $agent = User::factory()->create(['account_id' => $f['account']->id]);
    $attachment = ConversationMessageAttachment::factory()->pendingFor($f['conversation'], $agent)->create();

    $this->actingAs($agent)
        ->post("/dashboard/conversations/{$f['conversation']->support_code}/messages", [
            'body' => 'Here you go.',
            'attachment_ids' => [$attachment->id],
        ])->assertRedirect();

    expect($attachment->fresh()->conversation_message_id)->not->toBeNull();
});
