<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('conversation and ticket queues expose one guarded keyboard navigation layer', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    Conversation::factory()->for($site)->create(['subject' => 'Keyboard conversation']);
    Ticket::factory()->for($account)->for($site)->create(['subject' => 'Keyboard ticket']);

    foreach (['dashboard.conversations.index', 'dashboard.tickets.index'] as $routeName) {
        $response = $this->actingAs($agent)->get(route($routeName));

        $response
            ->assertOk()
            ->assertSee('data-agent-shortcut-search-primary', false)
            ->assertSee('data-agent-shortcut-queue', false)
            ->assertSee('data-agent-shortcut-row', false)
            ->assertSee('data-agent-shortcut-open', false)
            ->assertSee('window.WayfindrAgentShortcuts', false)
            ->assertSee("next: 'Alt+J'", false)
            ->assertSee("search: 'Alt+/'", false)
            ->assertSee('actionsByKey', false)
            ->assertSee('navigator.keyboard.getLayoutMap()', false)
            ->assertSee('! isPlainLayoutCharacter && layoutMap', false)
            ->assertSee("event.shiftKey && action !== 'search'", false)
            ->assertSee('event.isComposing', false)
            ->assertSee('eventOwnsText(event)', false)
            ->assertSee("target.closest('[role=\"dialog\"], [aria-modal=\"true\"]')", false);
    }
});

test('conversation detail shortcuts map only actions the agent can take', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    Conversation::factory()->for($site)->create([
        'subject' => 'Newest',
        'created_at' => now()->subMinute(),
    ]);
    $middle = Conversation::factory()->for($site)->create([
        'subject' => 'Middle',
        'created_at' => now()->subMinutes(2),
    ]);
    Conversation::factory()->for($site)->create([
        'subject' => 'Oldest',
        'created_at' => now()->subMinutes(3),
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.conversations.show', [
            'supportCode' => $middle->support_code,
            'from_queue' => '1',
        ]))
        ->assertOk()
        ->assertSee('data-agent-shortcut-previous', false)
        ->assertSee('data-agent-shortcut-next', false)
        ->assertSee('data-agent-shortcut-claim', false)
        ->assertSee('data-agent-shortcut-reply', false)
        ->assertSee('data-agent-shortcut-close', false);
});

test('ticket shortcuts can claim an unassigned ticket and reveal its visitor reply', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create([
        'conversation_id' => $conversation->id,
        'assignee_id' => null,
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('data-agent-shortcut-claim', false)
        ->assertSee('data-agent-shortcut-value="'.$agent->id.'"', false)
        ->assertSee('data-agent-shortcut-reply', false)
        ->assertSee('data-agent-shortcut-close', false)
        ->assertSee("'[role=\"tabpanel\"][hidden]'", false)
        ->assertSee("'[role=\"tab\"][aria-controls=\"'", false);
});
