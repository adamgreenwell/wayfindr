<?php

// Page-level background capture (#535).
//
// The widget reports the page's background family (color, gradient-only
// image, tile size) as snapshot.body_style so the replay preview can render
// on the visitor page's actual background instead of bare white. The server
// stores it bounded and sanitizes it at render time through the same
// declaration allowlist as element styles — the sanitizer stays the single
// enforcement boundary — and the retention pruner strips it with the rest of
// the raw page content.

use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\CobrowseReplayPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function pageBackgroundFixture(): array
{
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-bg']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-PAGEBG',
    ]);
    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [],
    ]);

    return compact('site', 'visitor', 'conversation', 'session');
}

function reportPageBackgroundSnapshot($test, Conversation $conversation, array $extra = [])
{
    $token = $test->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-bg',
        'page_url' => 'https://docs.example.test/install',
    ])->assertSuccessful()->json('data.visitor.token');

    return $test->postJson("/api/conversations/{$conversation->support_code}/cobrowse-snapshot", array_merge([
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-bg',
        'visitor_token' => $token,
        'page_url' => 'https://docs.example.test/install',
        'title' => 'Install Guide',
        'html' => '<main><p>Hello.</p></main>',
        'text' => 'Hello.',
        'node_count' => 2,
        'masked_count' => 0,
    ], $extra));
}

test('stores the reported page background and renders it on the preview body', function (): void {
    $fixture = pageBackgroundFixture();

    reportPageBackgroundSnapshot($this, $fixture['conversation'], [
        'body_style' => 'background-color:rgb(250,247,242);background-size:24px 24px',
    ])->assertOk();

    $metadata = $fixture['session']->fresh()->metadata;

    expect($metadata['snapshot']['body_style'])->toBe('background-color:rgb(250,247,242);background-size:24px 24px');

    $preview = (new CobrowseReplayPreview)->fromMetadata($metadata);

    expect($preview['srcdoc'])->toContain('body{background-color:rgb(250,247,242);background-size:24px 24px}');
});

test('snapshots without a page background store none and render the default shell', function (): void {
    $fixture = pageBackgroundFixture();

    reportPageBackgroundSnapshot($this, $fixture['conversation'])->assertOk();

    $metadata = $fixture['session']->fresh()->metadata;

    expect($metadata['snapshot'])->not->toHaveKey('body_style');

    $preview = (new CobrowseReplayPreview)->fromMetadata($metadata);

    expect(substr_count($preview['srcdoc'], 'body{'))->toBe(1);
});

test('oversized page backgrounds are rejected, not truncated', function (): void {
    $fixture = pageBackgroundFixture();

    reportPageBackgroundSnapshot($this, $fixture['conversation'], [
        'body_style' => 'background-color:rgb(0,0,0);'.str_repeat('a', 1000),
    ])->assertStatus(422);
});
