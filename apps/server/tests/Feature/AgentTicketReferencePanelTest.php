<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('ticket detail shows a compact support reference for linked tickets', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-reference',
        'email' => 'visitor@example.test',
    ]);
    $conversation = Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create([
            'support_code' => 'WF-REFERENCE',
            'subject' => 'Reference lookup',
        ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->create([
            'metadata' => [
                'visitor_context' => [
                    'last_page_url' => 'https://docs.example.test/account',
                ],
            ],
            'subject' => 'Reference ticket',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Support reference')
        ->assertSee('Ticket reference')
        ->assertSee('Ticket #'.$ticket->id)
        ->assertSee('Support code')
        ->assertSee('WF-REFERENCE')
        ->assertSee(route('dashboard.conversations.show', $conversation->support_code), false)
        ->assertSee('Acme Docs')
        ->assertSee('visitor@example.test')
        ->assertSee('Latest visitor page')
        ->assertSee('https://docs.example.test/account');
});

test('ticket detail reference handles standalone tickets without a linked conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Standalone ticket',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Support reference')
        ->assertSee('Ticket #'.$ticket->id)
        ->assertSee('Support code')
        ->assertSee('No linked conversation')
        ->assertSee('Requester')
        ->assertSee('Not linked')
        ->assertSee('Latest visitor page')
        ->assertSee('Not reported');
});
