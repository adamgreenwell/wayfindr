<?php

// The cobrowse copy of the page-address exposure #802 closed for the visitor,
// conversation and ticket copies (#805).
//
// A visitor who grants cobrowse has agreed to share the PAGE. That is the whole
// feature and ADR 0005 gates it on exactly that agreement. Agreeing to share a
// page is not agreeing to hand over the credential in its address, and this
// path keeps addresses longer than any other: the content pruner strips the
// heavy payloads on schedule and RETAINS the URLs by design, so an unsanitised
// one outlives everything around it.

use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function cobrowseSessionWithMetadata(array $metadata): CobrowseSession
{
    $site = Site::factory()->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();

    return CobrowseSession::query()->create([
        'conversation_id' => $conversation->id,
        'site_id' => $site->id,
        'visitor_id' => $visitor->id,
        'status' => 'granted',
        'consented_at' => now(),
        'metadata' => $metadata,
    ]);
}

test('every page address a cobrowse session stores is reduced on the way in', function (): void {
    // All four places the three writers put one. The mutation batches are the
    // awkward one: they are a LIST, so the path has to walk an index it cannot
    // know the name of.
    $session = cobrowseSessionWithMetadata([
        'page_state' => [
            'page_url' => 'https://shop.test/account?token=SECRET&utm=x',
            'title' => 'Account',
        ],
        'snapshot' => [
            'page_url' => 'https://shop.test/reset?token=SECRET',
            'html' => '<p>hello</p>',
        ],
        'mutations' => [
            'last_page_url' => 'https://shop.test/checkout?session=SECRET',
            'recent_batches' => [
                ['sequence' => 1, 'page_url' => 'https://shop.test/one?token=SECRET'],
                ['sequence' => 2, 'page_url' => 'https://shop.test/two?token=SECRET'],
            ],
        ],
    ]);

    $stored = $session->fresh()->metadata;

    expect($stored['page_state']['page_url'])->toBe('https://shop.test/account');
    expect($stored['snapshot']['page_url'])->toBe('https://shop.test/reset');
    expect($stored['mutations']['last_page_url'])->toBe('https://shop.test/checkout');
    expect($stored['mutations']['recent_batches'][0]['page_url'])->toBe('https://shop.test/one');
    expect($stored['mutations']['recent_batches'][1]['page_url'])->toBe('https://shop.test/two');

    // Everything beside them is untouched.
    expect($stored['page_state']['title'])->toBe('Account');
    expect($stored['snapshot']['html'])->toBe('<p>hello</p>');
    expect($stored['mutations']['recent_batches'][0]['sequence'])->toBe(1);
});

test('a later write cannot put a query string back', function (): void {
    // The reason this is a saving hook rather than a patch at each writer.
    // Several endpoints read the whole metadata document, change one flag and
    // save it all back; one holding a pre-sweep value would restore it.
    $session = cobrowseSessionWithMetadata([
        'page_state' => ['page_url' => 'https://shop.test/a'],
    ]);

    $session->forceFill([
        'metadata' => [
            'page_state' => ['page_url' => 'https://shop.test/a?token=SECRET'],
            'unrelated_flag' => true,
        ],
    ])->save();

    expect($session->fresh()->metadata['page_state']['page_url'])->toBe('https://shop.test/a');
});

test('a session with no page addresses is left alone', function (): void {
    $session = cobrowseSessionWithMetadata(['payload_budget' => ['max' => 10]]);

    expect($session->fresh()->metadata)->toBe(['payload_budget' => ['max' => 10]]);
});
