<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

uses(RefreshDatabase::class);

test('the agent shell exposes an accessible command palette with authorized navigation', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();

    $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-command-palette-open', false)
        ->assertSee('aria-haspopup="dialog"', false)
        ->assertSee('aria-controls="agent-command-palette"', false)
        ->assertSee('data-command-palette', false)
        ->assertSee('aria-labelledby="agent-command-palette-title"', false)
        ->assertSee('data-command-query', false)
        ->assertSee('data-command-action="next"', false)
        ->assertSee('data-command-action="search"', false)
        ->assertSee('data-command-action="reference"', false)
        ->assertSee('data-command-label="Dashboard"', false)
        ->assertSee('href="'.route('dashboard.conversations.index').'"', false)
        ->assertSee('href="'.route('dashboard.tickets.index').'"', false)
        ->assertSee('aria-current="page"', false);
});

test('the command palette renders in the agents dashboard language', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['locale' => 'de']);

    $this->actingAs($agent)
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->assertSee('Befehlspalette')
        ->assertSee('Befehl suchen')
        ->assertSee('Nächstes Element')
        ->assertSee('Seitennavigation');
});

test('the command palette filters available actions and restores focus when it closes', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    Conversation::factory()->for($site)->create();

    $this->actingAs($agent)
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->assertSee("palette: 'Alt+P'", false)
        ->assertSee('available: available', false)
        ->assertSee("new CustomEvent('wayfindr:agent-shortcuts-ready')", false)
        ->assertSee('dialog.showModal()', false)
        ->assertSee("dialog.addEventListener('close'", false)
        ->assertSee('opener.focus()', false)
        ->assertSee("event.key === 'ArrowDown'", false)
        ->assertSee("event.key === 'ArrowUp'", false)
        ->assertSee("event.key === 'Escape'", false)
        ->assertSee('registry.available(action)', false)
        ->assertSee('! label.includes(needle) && ! shortcut.includes(needle)', false)
        ->assertSee('registry.run(action)', false);
});

test('the unauthenticated shell does not expose agent commands', function (): void {
    $html = Blade::render('<x-layouts.app title="Guest">Guest</x-layouts.app>');

    expect($html)
        ->not->toContain('data-command-palette')
        ->not->toContain('WayfindrAgentShortcuts');
});
