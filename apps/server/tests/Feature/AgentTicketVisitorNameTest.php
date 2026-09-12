<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The value rendered beside a given meta-label, so a test can ask "what does the
 * Visitor row say" rather than searching the whole document for a string. The
 * browser id legitimately appears elsewhere on this page now, under its own
 * label, so substring assertions cannot tell the two apart.
 */
function metaValueFor(string $html, string $label): string
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($document);

    $node = $xpath->query(
        '//span[@class="meta-label"][normalize-space(text())="'.$label.'"]/following-sibling::span[1]'
    )?->item(0);

    return trim($node?->textContent ?? '');
}

/** @return array{agent: User, ticket: Ticket} */
function ticketWithRequester(array $visitorAttributes): array
{
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create($visitorAttributes);

    $ticket = Ticket::factory()->for($account)->for($site)->create([
        'requester_id' => $visitor->id,
        'subject' => 'A ticket about something',
    ]);

    return ['agent' => $agent, 'ticket' => $ticket];
}

test('a ticket calls its requester what every other surface calls them', function (): void {
    // The third private visitorContext(), and the fourth answer: this page put
    // the ADDRESS before the name, so a visitor with both was called one thing
    // on the list you clicked from and another here.
    $world = ticketWithRequester([
        'name' => 'Priya Raman',
        'email' => 'priya@example.test',
        'anonymous_id' => 'anon-6871486e',
    ]);

    $html = test()->actingAs($world['agent'])
        ->get(route('dashboard.tickets.show', $world['ticket']))
        ->assertOk()
        ->getContent();

    // Both places carrying the identity: the requester brief and the Visitor row.
    expect(substr_count($html, 'Priya Raman'))->toBeGreaterThanOrEqual(2)
        // the Visitor row names them, rather than printing an opaque id
        ->and(metaValueFor($html, 'Visitor'))->toBe('Priya Raman')
        // and the browser id is still on the page, under its own label. That row
        // used to be the only place it appeared, so promoting it without
        // somewhere to put this would have deleted a reference agents quote.
        ->and(metaValueFor($html, 'Visitor lookup reference'))->toBe('anon-6871486e');
});

test('a ticket from an email-only requester still has a name to show', function (): void {
    // InboundMailRouter leaves name and anonymous_id null, so the Visitor row
    // printed the browser id and fell through to "not linked" for somebody whose
    // address the install holds.
    $world = ticketWithRequester([
        'name' => null,
        'email' => 'priya@example.test',
        'anonymous_id' => null,
        'external_id' => null,
    ]);

    $html = test()->actingAs($world['agent'])
        ->get(route('dashboard.tickets.show', $world['ticket']))
        ->assertOk()
        ->getContent();

    // Twice, not once: the old requester brief already showed the address
    // because it put email FIRST, so asserting it appears at all passes against
    // the defect. The Visitor row is the half that was broken.
    expect(substr_count($html, 'priya@example.test'))->toBeGreaterThanOrEqual(2)
        ->and(metaValueFor($html, 'Visitor'))->toBe('priya@example.test');
});

test('a ticket requester the host names is not called by their browser id', function (): void {
    // The gap the conversation page had too: no name, no address, but the host
    // system calls them something.
    $world = ticketWithRequester([
        'name' => null,
        'email' => null,
        'external_id' => 'customer-123',
        'anonymous_id' => 'anon-6871486e',
    ]);

    $html = test()->actingAs($world['agent'])
        ->get(route('dashboard.tickets.show', $world['ticket']))
        ->assertOk()
        ->getContent();

    // The identity twice, plus the Host visitor ID row that already showed it.
    expect(substr_count($html, 'customer-123'))->toBeGreaterThanOrEqual(3)
        ->and(metaValueFor($html, 'Visitor'))->toBe('customer-123')
        ->and(metaValueFor($html, 'Host visitor ID'))->toBe('customer-123')
        ->and(metaValueFor($html, 'Visitor lookup reference'))->toBe('anon-6871486e');
});
