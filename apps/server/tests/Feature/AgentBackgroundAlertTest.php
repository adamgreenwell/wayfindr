<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

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
        ->assertSee('agent.alert.stored', false)
        ->assertSee('wayfindr:agent-alert-stored', false)
        ->assertSee('reconcileAlerts(message.target)', false)
        ->assertSee('reconcileAlertPage(activeSocket)', false)
        ->assertSee('seenAlertVersions', false)
        ->assertSee('dashboard\/alerts\/reconcile', false)
        ->assertSee("document.title = '(' + count + ') ' + originalTitle", false)
        ->assertSee("favicon.setAttribute('data-agent-alert-state', 'attention')", false)
        ->assertSee('audioContext.createOscillator()', false)
        ->assertSee('"soundEnabled":true', false);
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
