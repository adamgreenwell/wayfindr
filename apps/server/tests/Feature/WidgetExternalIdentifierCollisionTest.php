<?php

use App\Models\Conversation;
use App\Models\Site;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * `(site_id, external_id)` is unique, and the guard in front of it is a read
 * followed by a write with nothing holding the gap. Two browsers presenting the
 * same unheld identifier at once can both pass the check, and the loser's write
 * violates the constraint.
 *
 * A single process cannot run two requests at once, so the collision is forced:
 * a query listener inserts a competing holder the moment the exclusivity check
 * has run. The competitor joins the request's own transaction and rolls back
 * with it, which is NOT what a real second browser would do -- but the
 * constraint violation it provokes is genuine, and that violation is the whole
 * defect. These assert what the visitor gets when it happens.
 */
function collideOnExternalId(Site $site, string $externalId): void
{
    $fired = false;

    DB::listen(function ($query) use ($site, $externalId, &$fired): void {
        if ($fired || ! str_contains($query->sql, 'select exists') || ! str_contains($query->sql, '"external_id"')) {
            return;
        }

        $fired = true;

        DB::table('visitors')->insert([
            'site_id' => $site->id,
            'anonymous_id' => 'anon-collider',
            'external_id' => $externalId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
}

test('opening a conversation survives a collision on the identifier', function (): void {
    $site = Site::factory()->create();

    $token = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-writer',
    ])->json('data.visitor.token');

    collideOnExternalId($site, 'customer-collide');

    // Before the retry this was a 500: the visitor's first message failed to
    // send because somebody else was claiming an identifier at the same moment.
    $this->postJson('/api/conversations', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-writer',
        'visitor_token' => $token,
        'subject' => 'Need help installing',
        'external_id' => 'customer-collide',
    ])->assertCreated();

    // The message is what the visitor came to send, so it has to exist.
    expect(Conversation::query()->count())->toBe(1);
});

test('bootstrapping survives a collision on the identifier', function (): void {
    $site = Site::factory()->create();

    collideOnExternalId($site, 'customer-collide-2');

    // BootstrapController has carried this retry for the sibling
    // `(site_id, anonymous_id)` constraint, and it covers this one too. Asserted
    // rather than assumed, so that removing it is loud.
    $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-boot',
        'external_id' => 'customer-collide-2',
    ])->assertSuccessful();

    expect(Visitor::query()->where('anonymous_id', 'anon-boot')->exists())->toBeTrue();
});
