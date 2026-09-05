<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

uses(RefreshDatabase::class);

test('the agent shell exposes an accessible shortcut reference', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();

    $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-agent-shortcut-reference', false)
        ->assertSee('aria-labelledby="agent-shortcut-reference-title"', false)
        ->assertSee('aria-describedby="agent-shortcut-reference-description"', false)
        ->assertSee('data-shortcut-action="palette"', false)
        ->assertSee('data-shortcut-action="reference"', false)
        ->assertSee('data-shortcut-action="search"', false)
        ->assertSee('Keyboard shortcuts')
        ->assertSee('Only shortcuts available on this page are shown.');
});

test('the shortcut reference renders in the agents dashboard language', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['locale' => 'de']);

    $this->actingAs($agent)
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->assertSee('Tastenkürzel')
        ->assertSee('Nur die auf dieser Seite verfügbaren Kürzel werden angezeigt.')
        ->assertSee('Allgemein');
});

test('the shortcut reference is driven by the live shortcut registry', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();

    $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee("reference: '?'", false)
        ->assertSee("event.key === '?'", false)
        ->assertSee("event.key === '/' && event.shiftKey", false)
        ->assertSee("action = 'reference'", false)
        ->assertSee("new CustomEvent('wayfindr:agent-shortcut-reference-open')", false)
        ->assertSee('registry.keys[action]', false)
        ->assertSee('registry.available(action)', false)
        ->assertSee("dialog.addEventListener('cancel'", false)
        ->assertSee('opener.focus()', false);
});

test('the unauthenticated shell does not expose the shortcut reference', function (): void {
    $html = Blade::render('<x-layouts.app title="Guest">Guest</x-layouts.app>');

    expect($html)
        ->not->toContain('data-agent-shortcut-reference')
        ->not->toContain('wayfindr:agent-shortcut-reference-open');
});
