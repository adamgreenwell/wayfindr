<?php

// Cobrowse content retention boundary (#524, final slice).
//
// Raw page content (snapshot HTML, page text, retained mutation batches) is
// stripped from ended cobrowse sessions after a configurable window, while
// content-free provenance — counts, timestamps, page URLs, the snapshot hash,
// and the audit events — is kept, so the trail stays meaningful after the
// content is gone. Content keyframe storage is deliberately rejected for the
// default posture: cobrowse is shared page state, not a recording.

use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\CobrowseReplayPreview;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function retentionSession(array $overrides = []): CobrowseSession
{
    $site = Site::factory()->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();

    return CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create(array_merge([
        'status' => 'ended',
        'consented_at' => now()->subHours(80),
        'ended_at' => now()->subHours(75),
        'metadata' => [
            'snapshot' => [
                'page_url' => 'https://docs.example.test/install',
                'title' => 'Install Guide',
                'html' => '<main><p>Raw visitor page content.</p></main>',
                'text' => 'Raw visitor page content.',
                'html_hash' => hash('sha256', '<main><p>Raw visitor page content.</p></main>'),
                'html_length' => 45,
                'node_count' => 2,
                'masked_count' => 0,
                'reported_at' => now()->subHours(76)->toJSON(),
            ],
            'mutations' => [
                'batch_count' => 3,
                'mutation_count' => 5,
                'last_sequence' => 3,
                'last_page_url' => 'https://docs.example.test/install',
                'recent_batches' => [
                    ['sequence' => 3, 'mutations' => [['type' => 'text', 'text' => 'Late visitor content.']]],
                ],
            ],
        ],
    ], $overrides));
}

test('prunes raw content from ended sessions past the retention window', function (): void {
    config(['wayfindr.cobrowse.content_retention_hours' => 72]);
    $session = retentionSession();

    $this->artisan('wayfindr:prune-cobrowse-content')
        ->expectsOutputToContain('Pruned 1 cobrowse session')
        ->assertSuccessful();

    $metadata = $session->fresh()->metadata;

    // Content is gone…
    expect($metadata['snapshot'])->not->toHaveKey('html')
        ->and($metadata['snapshot'])->not->toHaveKey('text')
        ->and($metadata['mutations'])->not->toHaveKey('recent_batches')
        ->and(json_encode($metadata))->not->toContain('Raw visitor page content')
        ->and(json_encode($metadata))->not->toContain('Late visitor content')
        // …while content-free provenance stays.
        ->and($metadata['snapshot']['html_hash'])->toBe(hash('sha256', '<main><p>Raw visitor page content.</p></main>'))
        ->and($metadata['snapshot']['node_count'])->toBe(2)
        ->and($metadata['snapshot']['page_url'])->toBe('https://docs.example.test/install')
        ->and($metadata['mutations']['batch_count'])->toBe(3)
        ->and($metadata['content_pruned_at'])->not->toBeNull();

    // A pruned session renders gracefully as "no preview" rather than erroring.
    expect((new CobrowseReplayPreview)->fromMetadata($metadata))->toBeNull();
});

test('keeps content for active sessions and recently ended sessions', function (): void {
    config(['wayfindr.cobrowse.content_retention_hours' => 72]);
    $active = retentionSession(['status' => 'granted', 'ended_at' => null]);
    $recent = retentionSession(['ended_at' => now()->subHours(2)]);

    $this->artisan('wayfindr:prune-cobrowse-content')
        ->expectsOutputToContain('Pruned 0 cobrowse sessions')
        ->assertSuccessful();

    expect($active->fresh()->metadata['snapshot']['html'])->toContain('Raw visitor page content')
        ->and($recent->fresh()->metadata['snapshot']['html'])->toContain('Raw visitor page content');
});

test('dry run reports without changing anything', function (): void {
    config(['wayfindr.cobrowse.content_retention_hours' => 72]);
    $session = retentionSession();

    $this->artisan('wayfindr:prune-cobrowse-content --dry-run')
        ->expectsOutputToContain('Would prune 1 cobrowse session')
        ->assertSuccessful();

    expect($session->fresh()->metadata['snapshot']['html'])->toContain('Raw visitor page content');
});

test('pruning is idempotent', function (): void {
    config(['wayfindr.cobrowse.content_retention_hours' => 72]);
    retentionSession();

    $this->artisan('wayfindr:prune-cobrowse-content')->assertSuccessful();
    $this->artisan('wayfindr:prune-cobrowse-content')
        ->expectsOutputToContain('Pruned 0 cobrowse sessions')
        ->assertSuccessful();
});

test('the content pruner is registered with the Laravel scheduler', function (): void {
    $pruneEvent = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'wayfindr:prune-cobrowse-content'));

    expect($pruneEvent)->not->toBeNull()
        ->and($pruneEvent?->getExpression())->toBe('0 * * * *');
});
