<?php

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A consent answer names the request it answers.
 *
 * Without that the server picks the target itself -- `latest('id')` among rows
 * with no `ended_at` -- so an answer captured for one request grants whichever
 * request is open when it is replayed. The whole file is about that one
 * property and the things it must not break: a stop must never need a ticket,
 * and two tabs answering one prompt must still work.
 */
function consentBindingWorld(): array
{
    $site = Site::factory()->for(Account::factory())->create(['public_key' => 'site_public_consent']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-consent']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['support_code' => 'WF-CONSENT']);

    return [$site, $visitor, $conversation];
}

function consentBindingToken($test, Site $site, Conversation $conversation): string
{
    $token = $test->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-consent',
    ])->assertSuccessful()->json('data.visitor.token');

    conversationOwnedBySession($conversation, $token);

    return $token;
}

function consentBindingRequest(Site $site, Visitor $visitor, Conversation $conversation): CobrowseSession
{
    return CobrowseSession::factory()->create([
        'conversation_id' => $conversation->id,
        'site_id' => $site->id,
        'visitor_id' => $visitor->id,
        'status' => 'requested',
        'consented_at' => null,
        'ended_at' => null,
    ]);
}

function consentBindingAnswer($test, string $token, Conversation $conversation, array $payload)
{
    return $test->withToken($token)->postJson(
        "/api/conversations/{$conversation->support_code}/cobrowse-consent",
        array_merge([
            'site_public_key' => 'site_public_consent',
            'anonymous_id' => 'anon-consent',
        ], $payload),
    );
}

test('an answer captured for one request does not grant a later one', function (): void {
    [$site, $visitor, $conversation] = consentBindingWorld();
    $token = consentBindingToken($this, $site, $conversation);

    $first = consentBindingRequest($site, $visitor, $conversation);

    $ticket = $this->withToken($token)->getJson(
        "/api/conversations/{$conversation->support_code}/cobrowse?".http_build_query([
            'site_public_key' => $site->public_key,
            'anonymous_id' => 'anon-consent',
        ])
    )->assertOk()->json('data.cobrowse.consent_ticket');

    $answer = ['granted' => true, 'consent_ticket' => $ticket];

    consentBindingAnswer($this, $token, $conversation, $answer)->assertOk();
    expect($first->refresh()->status)->toBe('granted');

    // The agent ends it and asks again. This is a NEW request, and the visitor
    // has not seen it.
    $first->forceFill(['status' => 'ended', 'ended_at' => now()])->save();
    $second = consentBindingRequest($site, $visitor, $conversation);

    consentBindingAnswer($this, $token, $conversation, $answer)->assertStatus(422);

    expect($second->refresh()->status)->toBe('requested',
        'A grant captured for one request must not grant a later one the visitor never saw.')
        ->and($second->consented_at)->toBeNull();
});

test('a grant naming no request is accepted where there is nothing to replay', function (): void {
    // A widget loaded before this shipped never fetches `widget.js` again, so
    // its script is fixed until the visitor reloads -- `max-age` bounds the next
    // page LOAD, not a tab that is already open. The same is true of a
    // `createClient()` integration calling the two-argument form. Refusing them
    // outright would leave long-lived tabs unable to grant at all, for a window
    // nothing bounds.
    //
    // On a conversation whose only request is this one, an answer can have been
    // captured from no other request, so there is nothing a replay could carry.
    [$site, $visitor, $conversation] = consentBindingWorld();
    $token = consentBindingToken($this, $site, $conversation);
    $request = consentBindingRequest($site, $visitor, $conversation);

    consentBindingAnswer($this, $token, $conversation, ['granted' => true])->assertOk();

    expect($request->refresh()->status)->toBe('granted');

    // And the row says the answer did not name its request, which is the
    // evidence for deciding when the allowance can be withdrawn.
    $event = AuditEvent::query()->where('action', 'cobrowse.consent_granted')->sole();

    expect($event->metadata['named_its_request'] ?? null)->toBeFalse();
});

test('a grant naming no request is refused once the conversation has had another', function (): void {
    // A second request is exactly where a captured answer becomes dangerous, so
    // that is exactly where the name stops being optional.
    [$site, $visitor, $conversation] = consentBindingWorld();
    $token = consentBindingToken($this, $site, $conversation);

    $first = consentBindingRequest($site, $visitor, $conversation);
    $first->forceFill(['status' => 'ended', 'ended_at' => now()])->save();

    $second = consentBindingRequest($site, $visitor, $conversation);

    consentBindingAnswer($this, $token, $conversation, ['granted' => true])->assertStatus(422);

    expect($second->refresh()->status)->toBe('requested');
});

test('another conversation\'s requests do not make this one look replayable', function (): void {
    // The allowance asks whether THIS conversation has had another request.
    // Scoped to the conversation, or any cobrowse row anywhere would answer yes
    // -- which is always, on any real install -- and the allowance that keeps
    // already-loaded widgets working would never apply to anyone.
    [$site, $visitor, $conversation] = consentBindingWorld();
    $token = consentBindingToken($this, $site, $conversation);

    $elsewhere = Conversation::factory()->for($site)->for($visitor)->create(['support_code' => 'WF-OTHER']);
    consentBindingRequest($site, $visitor, $elsewhere);
    consentBindingRequest($site, $visitor, $elsewhere);

    $ours = consentBindingRequest($site, $visitor, $conversation);

    consentBindingAnswer($this, $token, $conversation, ['granted' => true])->assertOk();

    expect($ours->refresh()->status)->toBe('granted');
});

test('a named answer records that it named its request', function (): void {
    [$site, $visitor, $conversation] = consentBindingWorld();
    $token = consentBindingToken($this, $site, $conversation);
    $request = consentBindingRequest($site, $visitor, $conversation);

    consentBindingAnswer($this, $token, $conversation, [
        'granted' => true,
        'consent_ticket' => $request->consentTicket(),
    ])->assertOk();

    $event = AuditEvent::query()->where('action', 'cobrowse.consent_granted')->sole();

    expect($event->metadata)->not->toHaveKey('named_its_request');
});

test('the ticket the prompt publishes is the one the answer needs', function (): void {
    // The status GET selects `latest('id')` with no `ended_at` filter; the write
    // path selects `latest('id')` AND `whereNull('ended_at')`. They agree today
    // only because an agent cannot open a second request while one is open. This
    // pins the agreement itself: take the ticket from the response a widget
    // actually reads, and feed it to the endpoint a widget actually posts to.
    [$site, $visitor, $conversation] = consentBindingWorld();
    $token = consentBindingToken($this, $site, $conversation);
    consentBindingRequest($site, $visitor, $conversation);

    $published = $this->withToken($token)->getJson(
        "/api/conversations/{$conversation->support_code}/cobrowse?".http_build_query([
            'site_public_key' => $site->public_key,
            'anonymous_id' => 'anon-consent',
        ])
    )->assertOk()->json('data.cobrowse.consent_ticket');

    expect($published)->toBeString()->not->toBe('');

    consentBindingAnswer($this, $token, $conversation, [
        'granted' => true,
        'consent_ticket' => $published,
    ])->assertOk();
});

test('stopping never needs a ticket', function (): void {
    // The worst outcome this endpoint has is a share that will not stop.
    // Refusing a stop for want of a value the visitor cannot see would
    // manufacture exactly that, so the gate is one-directional by design.
    [$site, $visitor, $conversation] = consentBindingWorld();
    $token = consentBindingToken($this, $site, $conversation);

    $granted = CobrowseSession::factory()->create([
        'conversation_id' => $conversation->id,
        'site_id' => $site->id,
        'visitor_id' => $visitor->id,
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
    ]);

    consentBindingAnswer($this, $token, $conversation, ['granted' => false])->assertOk();

    expect($granted->refresh()->status)->toBe('revoked')
        ->and($granted->ended_at)->not->toBeNull();
});

test('declining an unanswered request never needs a ticket either', function (): void {
    [$site, $visitor, $conversation] = consentBindingWorld();
    $token = consentBindingToken($this, $site, $conversation);
    $request = consentBindingRequest($site, $visitor, $conversation);

    consentBindingAnswer($this, $token, $conversation, ['granted' => false])->assertOk();

    expect($request->refresh()->status)->toBe('revoked');
});

test('a second tab answering the same prompt still works', function (): void {
    // Two tabs hold the same ticket, because it names the request rather than
    // the answerer. The second answer changes nothing and must not be refused --
    // which is already the pinned behaviour for a repeat grant.
    [$site, $visitor, $conversation] = consentBindingWorld();
    $token = consentBindingToken($this, $site, $conversation);
    $request = consentBindingRequest($site, $visitor, $conversation);

    $ticket = $request->consentTicket();

    consentBindingAnswer($this, $token, $conversation, ['granted' => true, 'consent_ticket' => $ticket])->assertOk();
    $consentedAt = $request->refresh()->consented_at;

    consentBindingAnswer($this, $token, $conversation, ['granted' => true, 'consent_ticket' => $ticket])->assertOk();

    expect($request->refresh()->status)->toBe('granted')
        ->and($request->consented_at?->toJSON())->toBe($consentedAt?->toJSON(),
            'A repeat grant must not move the stamp the audit row records.');
});

test('the prompt is the only state that publishes a ticket', function (): void {
    [$site, $visitor, $conversation] = consentBindingWorld();
    $token = consentBindingToken($this, $site, $conversation);

    $granted = CobrowseSession::factory()->create([
        'conversation_id' => $conversation->id,
        'site_id' => $site->id,
        'visitor_id' => $visitor->id,
        'status' => 'granted',
        'consented_at' => now(),
        'ended_at' => null,
    ]);

    $payload = $this->withToken($token)->getJson(
        "/api/conversations/{$conversation->support_code}/cobrowse?".http_build_query([
            'site_public_key' => $site->public_key,
            'anonymous_id' => 'anon-consent',
        ])
    )->assertOk()->json('data.cobrowse');

    expect($payload['consent_ticket'])->toBeNull()
        ->and($granted->refresh()->consentTicket())->toBeNull();
});
