<?php

use App\Models\Account;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketAssigned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('authenticated dashboard pages connect the account alert stream when Reverb is configured', function (): void {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'reverb-key');
    config()->set('broadcasting.connections.reverb.options.client_host', 'desk.example.test');
    config()->set('broadcasting.connections.reverb.options.client_port', '443');
    config()->set('broadcasting.connections.reverb.options.client_scheme', 'https');

    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create([
        'alert_preferences' => ['sound' => true],
    ]);

    $response = $this->actingAs($agent)->get('/dashboard');

    $response
        ->assertOk()
        ->assertSee('data-agent-alert-favicon', false)
        ->assertSee('data-agent-alert-stream', false)
        ->assertSee(sprintf('private-accounts.%d.agents.%d.alerts', $account->id, $agent->id), false)
        ->assertSee('presence-agents.'.$agent->id, false)
        ->assertSee('dashboard\/alerts\/realtime-receipt', false)
        ->assertSee('agent.alert.stored', false)
        ->assertSee('wayfindr:agent-alert-stored', false)
        ->assertSee('reconcileAlerts(message.target)', false)
        ->assertSee('reconcileAlertPage(activeSocket)', false)
        ->assertSee('overlappingReconcileSince(data.watermark)', false)
        ->assertSee('activeSocket.wayfindrNeedsFreshReconcile = Boolean(reconcileThrough || reconcileCursor)', false)
        ->assertSee('if (activeSocket.wayfindrNeedsFreshReconcile)', false)
        ->assertSee('response.status === 429', false)
        ->assertSee("response.headers.get('Retry-After')", false)
        ->assertSee('activeSocket.wayfindrReconcileRetryTimer', false)
        ->assertSee('scheduleReconcileRetry(activeSocket, error.retryAfterMilliseconds)', false)
        ->assertSee('seenAlertVersions', false)
        ->assertSee('liveAlertVersionKeepUntil', false)
        ->assertSee('liveAlertVersionRetentionMilliseconds = 5 * 60 * 1000', false)
        ->assertSee('dashboard\/alerts\/reconcile', false)
        ->assertSee("document.title = '(' + count + ') ' + originalTitle", false)
        ->assertSee("favicon.setAttribute('data-agent-alert-state', 'attention')", false)
        ->assertSee('audioContext.createOscillator()', false)
        ->assertSee('"soundEnabled":true', false)
        ->assertSee('"quietHours":{"enabled":false,"start":"22:00","end":"07:00","timezone":"UTC"}', false)
        ->assertSee('quietHoursActive()', false)
        ->assertSee('timeZone: quietHours.timezone', false);
});

test('the alert stream acknowledges exact live deliveries instead of trusting presence', function (): void {
    $source = file_get_contents(resource_path('views/components/agent-alert-stream.blade.php'));

    expect($source)
        ->toContain('fetch(config.realtimeReceiptEndpoint')
        ->toContain("document.visibilityState === 'visible'")
        ->toContain('keepalive: true')
        ->toContain('alert_id: alert.id')
        ->toContain('version: alert.version')
        ->toContain("document.addEventListener('visibilitychange', foregroundStateChanged)")
        ->not->toContain('visibleChannelName')
        ->not->toContain('presence-visible-agents');
});

test('an agent can acknowledge only the current version of their own realtime alert', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $other = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create();
    $version = (string) Str::uuid();
    $alert = $agent->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => TicketAssigned::class,
        'data' => ['kind' => 'ticket_assigned', 'ticket_id' => $ticket->id],
        'agent_alert_version' => $version,
        'read_at' => null,
    ]);

    $this->actingAs($other)
        ->postJson(route('dashboard.alerts.realtime-receipt'), [
            'alert_id' => $alert->id,
            'version' => $version,
        ])
        ->assertNoContent();
    expect($alert->fresh()->getAttribute('agent_alert_realtime_received_version'))->toBeNull();

    $this->actingAs($agent)
        ->postJson(route('dashboard.alerts.realtime-receipt'), [
            'alert_id' => $alert->id,
            'version' => (string) Str::uuid(),
        ])
        ->assertNoContent();
    expect($alert->fresh()->getAttribute('agent_alert_realtime_received_version'))->toBeNull();

    $this->actingAs($agent)
        ->postJson(route('dashboard.alerts.realtime-receipt'), [
            'alert_id' => $alert->id,
            'version' => $version,
        ])
        ->assertNoContent();
    expect($alert->fresh()->getAttribute('agent_alert_realtime_received_version'))->toBe($version);
});

test('duplicate tab receipts cannot exhaust the quota for a later alert', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create();
    $firstVersion = (string) Str::uuid();
    $secondVersion = (string) Str::uuid();
    $makeAlert = fn (string $version) => $agent->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => TicketAssigned::class,
        'data' => ['kind' => 'ticket_assigned', 'ticket_id' => $ticket->id],
        'agent_alert_version' => $version,
        'read_at' => null,
    ]);
    $first = $makeAlert($firstVersion);
    $second = $makeAlert($secondVersion);

    foreach (range(1, 120) as $duplicateTabReceipt) {
        $this->actingAs($agent)
            ->postJson(route('dashboard.alerts.realtime-receipt'), [
                'alert_id' => $first->id,
                'version' => $firstVersion,
            ])
            ->assertNoContent();
    }

    $this->actingAs($agent)
        ->postJson(route('dashboard.alerts.realtime-receipt'), [
            'alert_id' => $first->id,
            'version' => $firstVersion,
        ])
        ->assertStatus(429);

    $this->actingAs($agent)
        ->postJson(route('dashboard.alerts.realtime-receipt'), [
            'alert_id' => $second->id,
            'version' => $secondVersion,
        ])
        ->assertNoContent();

    expect($first->fresh()->getAttribute('agent_alert_realtime_received_version'))->toBe($firstVersion)
        ->and($second->fresh()->getAttribute('agent_alert_realtime_received_version'))->toBe($secondVersion);
});

test('visitor index connects the authenticated agent alert stream', function (): void {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'reverb-key');
    config()->set('broadcasting.connections.reverb.options.client_host', 'desk.example.test');
    config()->set('broadcasting.connections.reverb.options.client_port', '443');
    config()->set('broadcasting.connections.reverb.options.client_scheme', 'https');

    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();

    $this->actingAs($agent)
        ->get('/dashboard/visitors')
        ->assertOk()
        ->assertSee('data-agent-alert-stream', false)
        ->assertSee(sprintf('private-accounts.%d.agents.%d.alerts', $account->id, $agent->id), false);
});

test('dashboard alert stream degrades quietly when Reverb is unavailable', function (): void {
    config()->set('broadcasting.default', 'null');

    $agent = User::factory()->for(Account::factory())->create();

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('data-agent-alert-favicon', false)
        ->assertDontSee('data-agent-alert-stream', false);
});

test('guest layouts do not render an agent alert stream', function (): void {
    $html = Blade::render('<x-layouts.app title="Guest">Guest</x-layouts.app>');

    expect($html)
        ->toContain('data-agent-alert-favicon')
        ->not->toContain('data-agent-alert-stream');
});
