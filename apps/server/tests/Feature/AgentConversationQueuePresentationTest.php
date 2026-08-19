<?php

// The rebuilt conversation queue (ADR 0014, step 5). These pin the decisions
// that are easy to undo by accident: colour that means something, chrome that
// does not come back, and a site rail wired to the site's own colour.

use App\Enums\SiteColor;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function queueAgentAndConversation(SiteColor $color = SiteColor::Violet): array
{
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs', 'color' => $color]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-queue']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-QUEUE01',
        'subject' => 'Checkout fails',
        'status' => 'open',
    ]);

    return [$agent, $conversation];
}

test('each row carries its site colour as a rail', function (): void {
    [$agent] = queueAgentAndConversation(SiteColor::Violet);

    $this->actingAs($agent)
        ->get('/dashboard/conversations')
        ->assertOk()
        ->assertSee('--wf-row-site: var(--wf-site-violet)', false);
});

test('the lanes carry their own counts, so the separate snapshot band is gone', function (): void {
    [$agent] = queueAgentAndConversation();

    $response = $this->actingAs($agent)->get('/dashboard/conversations')->assertOk();

    $response->assertSee('aria-label="Conversation lanes"', false)
        ->assertSee('wf-lane-count', false)
        // Three stacked bands is what this replaces.
        ->assertDontSee('Queue snapshot');
});

test('a resting cobrowse state is not coloured as a warning', function (): void {
    // Amber used to mark "Unavailable" and "Quiet" -- the resting states of
    // nearly every row -- which made a calm queue look like a problem.
    [$agent] = queueAgentAndConversation();

    $response = $this->actingAs($agent)->get('/dashboard/conversations')->assertOk();

    expect($response->getContent())
        ->toContain('wf-queue-cobrowse')
        ->toContain('Unavailable')
        // The transport is still stated; it just is not painted as a warning.
        ->not->toContain('wf-queue-cobrowse" data-tone');
});

test('the support code is set in mono beside the subject it belongs to', function (): void {
    [$agent] = queueAgentAndConversation();

    $this->actingAs($agent)
        ->get('/dashboard/conversations')
        ->assertOk()
        ->assertSee('wf-queue-code', false)
        ->assertSee('WF-QUEUE01');
});

test('the queue still says how many match and how many are shown', function (): void {
    // Dropping this in the rebuild lost the pagination context entirely, which
    // is why it is asserted rather than left to the eye.
    [$agent] = queueAgentAndConversation();

    $this->actingAs($agent)
        ->get('/dashboard/conversations')
        ->assertOk()
        ->assertSee('wf-queue-summary', false)
        ->assertSee('Showing 1 conversation matching the current queue filters.');
});

test('searchable references are explained on the field that searches them', function (): void {
    [$agent] = queueAgentAndConversation();

    $this->actingAs($agent)
        ->get('/dashboard/conversations')
        ->assertOk()
        ->assertSee('wf-filter-help', false)
        ->assertSee('Search by subject, support code, visitor ID, visitor name, or visitor email.');
});
