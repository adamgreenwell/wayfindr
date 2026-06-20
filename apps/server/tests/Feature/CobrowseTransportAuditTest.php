<?php

use App\Events\CobrowseStateUpdated;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\VisitorSessionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('agent snapshot resync requests create metadata-only audit events', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-20 14:00:00', 'UTC'));
    Event::fake([CobrowseStateUpdated::class]);

    try {
        [$agent, $site, $visitor, $conversation, $session] = cobrowseTransportAuditFixture('WF-AUDIT1');

        $this->actingAs($agent)
            ->from('/dashboard/conversations/WF-AUDIT1')
            ->post('/dashboard/conversations/WF-AUDIT1/cobrowse/resync')
            ->assertRedirect('/dashboard/conversations/WF-AUDIT1')
            ->assertSessionHas('status', 'Fresh cobrowse snapshot requested.');

        $session->refresh();
        $requestId = $session->metadata['resync_request']['id'];

        $auditEvent = AuditEvent::query()
            ->where('action', 'cobrowse.resync_requested')
            ->sole();

        expect($auditEvent->account_id)->toBe($site->account_id)
            ->and($auditEvent->site_id)->toBe($site->id)
            ->and($auditEvent->actor->is($agent))->toBeTrue()
            ->and($auditEvent->subject->is($session))->toBeTrue()
            ->and($auditEvent->metadata)->toMatchArray([
                'support_code' => $conversation->support_code,
                'request_id' => $requestId,
                'requested_at' => now()->toJSON(),
                'previous_request_id' => null,
            ]);

        expectCobrowseAuditMetadataIsControlPlaneOnly($auditEvent);
    } finally {
        Carbon::setTestNow();
    }
});

test('visitor snapshot resync fulfillment creates a metadata-only audit event', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-20 14:05:00', 'UTC'));

    try {
        [, $site, $visitor, $conversation, $session] = cobrowseTransportAuditFixture('WF-AUDIT2', [
            'resync_request' => [
                'id' => 'resync_current',
                'requested_by_id' => 1,
                'requested_by_name' => 'Ada Agent',
                'requested_at' => now()->subSeconds(20)->toJSON(),
                'fulfilled_at' => null,
            ],
        ]);

        $this->postJson("/api/conversations/{$conversation->support_code}/cobrowse-snapshot", cobrowseSnapshotPayload($site, $visitor, [
            'resync_request_id' => 'resync_current',
            'html' => '<main><h1>Billing</h1><p>Card 4242 4242 4242 4242.</p></main>',
            'text' => 'Billing Card 4242 4242 4242 4242.',
            'page_url' => 'https://docs.example.test/billing?token=secret',
        ]))->assertOk();

        $auditEvent = AuditEvent::query()
            ->where('action', 'cobrowse.resync_fulfilled')
            ->sole();

        expect($auditEvent->account_id)->toBe($site->account_id)
            ->and($auditEvent->site_id)->toBe($site->id)
            ->and($auditEvent->actor->is($visitor))->toBeTrue()
            ->and($auditEvent->subject->is($session))->toBeTrue()
            ->and($auditEvent->metadata)->toMatchArray([
                'support_code' => $conversation->support_code,
                'request_id' => 'resync_current',
                'fulfilled_at' => now()->toJSON(),
                'snapshot_reported_at' => now()->toJSON(),
            ]);

        expectCobrowseAuditMetadataIsControlPlaneOnly($auditEvent);
    } finally {
        Carbon::setTestNow();
    }
});

test('ignored visitor resync responses create metadata-only audit events', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-20 14:10:00', 'UTC'));

    try {
        [, $site, $visitor, $conversation, $session] = cobrowseTransportAuditFixture('WF-AUDIT3', [
            'resync_request' => [
                'id' => 'resync_current',
                'requested_by_id' => 1,
                'requested_by_name' => 'Ada Agent',
                'requested_at' => now()->subSeconds(30)->toJSON(),
                'fulfilled_at' => null,
            ],
        ]);

        $this->postJson("/api/conversations/{$conversation->support_code}/cobrowse-snapshot", cobrowseSnapshotPayload($site, $visitor, [
            'resync_request_id' => 'resync_wrong',
            'html' => '<main><h1>Checkout</h1><p>Password hunter2.</p></main>',
            'text' => 'Checkout Password hunter2.',
            'page_url' => 'https://docs.example.test/checkout?session=secret',
        ]))->assertOk();

        $auditEvent = AuditEvent::query()
            ->where('action', 'cobrowse.resync_ignored')
            ->sole();

        expect($auditEvent->account_id)->toBe($site->account_id)
            ->and($auditEvent->site_id)->toBe($site->id)
            ->and($auditEvent->actor->is($visitor))->toBeTrue()
            ->and($auditEvent->subject->is($session))->toBeTrue()
            ->and($auditEvent->metadata)->toMatchArray([
                'support_code' => $conversation->support_code,
                'active_request_id' => 'resync_current',
                'response_request_id' => 'resync_wrong',
                'reason' => 'mismatched',
                'ignored_at' => now()->toJSON(),
            ]);

        expectCobrowseAuditMetadataIsControlPlaneOnly($auditEvent);
    } finally {
        Carbon::setTestNow();
    }
});

test('visitor resync exhaustion creates one metadata-only audit event per request', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-20 14:15:00', 'UTC'));

    try {
        [, $site, $visitor, $conversation, $session] = cobrowseTransportAuditFixture('WF-AUDIT4', [
            'resync_request' => [
                'id' => 'resync_tired',
                'requested_by_id' => 1,
                'requested_by_name' => 'Ada Agent',
                'requested_at' => now()->subSeconds(45)->toJSON(),
                'fulfilled_at' => null,
            ],
        ]);
        $payload = [
            'site_public_key' => $site->public_key,
            'anonymous_id' => $visitor->anonymous_id,
            'visitor_token' => app(VisitorSessionToken::class)->issue($site, $visitor),
            'resync_request_id' => 'resync_tired',
            'resync_attempts_exhausted' => true,
            'rtt_ms' => 880,
            'payload_bytes' => 2048,
            'dropped_batches' => 3,
            'reconnects' => 2,
        ];

        $this->postJson("/api/conversations/{$conversation->support_code}/cobrowse-telemetry", $payload)
            ->assertOk();

        $this->postJson("/api/conversations/{$conversation->support_code}/cobrowse-telemetry", $payload)
            ->assertOk();

        expect(AuditEvent::query()->where('action', 'cobrowse.resync_exhausted')->count())->toBe(1);

        $auditEvent = AuditEvent::query()
            ->where('action', 'cobrowse.resync_exhausted')
            ->sole();

        expect($auditEvent->account_id)->toBe($site->account_id)
            ->and($auditEvent->site_id)->toBe($site->id)
            ->and($auditEvent->actor->is($visitor))->toBeTrue()
            ->and($auditEvent->subject->is($session))->toBeTrue()
            ->and($auditEvent->metadata)->toMatchArray([
                'support_code' => $conversation->support_code,
                'request_id' => 'resync_tired',
                'attempts_exhausted_at' => now()->toJSON(),
                'dropped_batches' => 3,
                'reconnects' => 2,
            ]);

        expectCobrowseAuditMetadataIsControlPlaneOnly($auditEvent);
    } finally {
        Carbon::setTestNow();
    }
});

/**
 * @param  array<string, mixed>  $sessionMetadata
 * @return array{0: User, 1: Site, 2: Visitor, 3: Conversation, 4: CobrowseSession}
 */
function cobrowseTransportAuditFixture(string $supportCode, array $sessionMetadata = []): array
{
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create([
        'name' => 'Acme Docs',
        'public_key' => 'site_'.strtolower($supportCode),
    ]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-'.strtolower($supportCode)]);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => $supportCode,
        'subject' => 'Cobrowse audit',
    ]);
    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'requested_by_id' => $agent->id,
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => $sessionMetadata,
    ]);

    return [$agent, $site, $visitor, $conversation, $session];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cobrowseSnapshotPayload(Site $site, Visitor $visitor, array $overrides = []): array
{
    return array_merge([
        'site_public_key' => $site->public_key,
        'anonymous_id' => $visitor->anonymous_id,
        'visitor_token' => app(VisitorSessionToken::class)->issue($site, $visitor),
        'page_url' => 'https://docs.example.test/install',
        'title' => 'Install Guide',
        'html' => '<main><h1>Install Guide</h1><p>Hello visitor.</p></main>',
        'text' => 'Install Guide Hello visitor.',
        'node_count' => 3,
        'masked_count' => 0,
    ], $overrides);
}

function expectCobrowseAuditMetadataIsControlPlaneOnly(AuditEvent $auditEvent): void
{
    $metadata = $auditEvent->metadata ?? [];
    $encoded = json_encode($metadata, JSON_THROW_ON_ERROR);

    expect($metadata)
        ->not->toHaveKey('html')
        ->not->toHaveKey('text')
        ->not->toHaveKey('page_url')
        ->not->toHaveKey('snapshot')
        ->not->toHaveKey('page_state')
        ->not->toHaveKey('mutations')
        ->not->toHaveKey('mutation_batch')
        ->and($encoded)
        ->not->toContain('4242 4242 4242 4242')
        ->not->toContain('hunter2')
        ->not->toContain('secret')
        ->not->toContain('<main>');
}
