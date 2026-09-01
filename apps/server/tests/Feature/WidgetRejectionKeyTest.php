<?php

// An attachment rejection reaches the widget as a KEY, not only a sentence.
//
// The server can only know a visitor's language when the site pins one.
// `WidgetLanguage::for($site)` is null by default, and null does not mean
// English -- it means the widget is following the visitor's BROWSER, a choice
// made on the other side of the wire that the server never sees. So a
// German-speaking visitor on an unpinned site gets a German widget and, today,
// an English rejection.
//
// The widget already knows how to say its own failures in the visitor's
// language: they carry `wayfindrKey` and are resolved from its own catalogue.
// Rejections arrived as finished English sentences and could not join that
// path. They now carry the key and its parameters too, so the widget can say
// them in the language it is actually speaking.

use App\Models\Conversation;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\VisitorSessionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function rejectionFixture(array $siteAttributes = []): array
{
    Storage::fake('attachments');

    $site = Site::factory()->create(array_merge(['public_key' => 'site_public_reject'], $siteAttributes));
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-reject']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['status' => 'open']);

    return [
        'site' => $site,
        'visitor' => $visitor,
        'conversation' => $conversation,
        'token' => app(VisitorSessionToken::class)->issue($site, $visitor),
    ];
}

function uploadRejectedAttachment(array $f, array $extra = []): TestResponse
{
    // A disallowed TYPE rather than an oversized file. The framework's own
    // `max:` rule fires before the service is reached, so a big file exercises
    // Laravel's validator instead of `AttachmentRejected` -- the type check
    // lives in the upload service and is the rejection this is about.
    return test()->postJson(
        "/api/conversations/{$f['conversation']->support_code}/attachments",
        array_merge([
            'site_public_key' => $f['site']->public_key,
            'anonymous_id' => $f['visitor']->anonymous_id,
            'visitor_token' => $f['token'],
            'file' => UploadedFile::fake()->create('payload.bin', 8, 'application/x-msdownload'),
        ], $extra)
    );
}

test('a rejected upload carries the key and parameters, not only a sentence', function (): void {
    $f = rejectionFixture();

    $response = uploadRejectedAttachment($f)->assertStatus(422);

    // The sentence stays, so an older widget and any non-widget client keep
    // working exactly as before.
    expect($response->json('message'))->toBeString()->not->toBeEmpty();

    $response
        ->assertJsonPath('error_key', 'composer.rejected.type')
        ->assertJsonStructure(['message', 'errors', 'error_key']);
});

test('the sentence still answers in a pinned site language', function (): void {
    // Nothing changes for a site that DOES pin one: the server knows the
    // language and answers in it, and the key rides along.
    $f = rejectionFixture(['settings' => ['locale' => 'de']]);

    $response = uploadRejectedAttachment($f)->assertStatus(422);

    expect($response->json('message'))->toContain('Dateityp');
    expect($response->json('error_key'))->toBe('composer.rejected.type');
});

test('a rejection when binding attachments to a message carries its key too', function (): void {
    // The other of the two widget surfaces that can reject: the binder, when a
    // message names attachments it may not have.
    $f = rejectionFixture();

    $response = test()->postJson(
        "/api/conversations/{$f['conversation']->support_code}/messages",
        [
            'site_public_key' => $f['site']->public_key,
            'anonymous_id' => $f['visitor']->anonymous_id,
            'visitor_token' => $f['token'],
            'body' => 'here you go',
            'attachment_ids' => [999999],
        ]
    )->assertStatus(422);

    expect($response->json('error_key'))->toBe('composer.rejected.unavailable');
});

test('a validation failure that is not a rejection carries no key', function (): void {
    // The key is a promise that the widget can translate it. A framework
    // validation message has no catalogue entry the widget knows, so claiming
    // one would make the widget show a raw key to a visitor.
    $f = rejectionFixture();

    $response = test()->postJson(
        "/api/conversations/{$f['conversation']->support_code}/attachments",
        [
            'site_public_key' => $f['site']->public_key,
            'anonymous_id' => $f['visitor']->anonymous_id,
            'visitor_token' => $f['token'],
        ]
    )->assertStatus(422);

    expect($response->json('error_key'))->toBeNull();
});
