<?php

// The agent transcript (ADR 0014, step 6). The widget always rendered this as a
// conversation; the agent's own view stacked both sides full width, so the two
// halves of the same exchange used opposite metaphors.

use App\Enums\SiteColor;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function transcriptFixture(SiteColor $color = SiteColor::Pine): array
{
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs', 'color' => $color]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-transcript']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TRANS01',
        'subject' => 'Checkout fails',
        'status' => 'open',
    ]);

    return [$agent, $conversation, $visitor];
}

test('the visitor and the agent are rendered as two sides of a conversation', function (): void {
    [$agent, $conversation, $visitor] = transcriptFixture();

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Checkout fails for me.',
    ]);
    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'Looking into it now.',
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/conversations/{$conversation->support_code}")
        ->assertOk()
        ->assertSee('class="message visitor"', false)
        ->assertSee('class="message agent"', false);
});

test('the transcript carries the site colour the queue and the widget use', function (): void {
    [$agent, $conversation, $visitor] = transcriptFixture(SiteColor::Pine);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Hello.',
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/conversations/{$conversation->support_code}")
        ->assertOk()
        ->assertSee('--wf-conversation-site: var(--wf-site-pine)', false);
});

test('a message with no text and no attachment says so instead of rendering an empty box', function (): void {
    // This is what the original review found on staging: a bordered box
    // containing nothing but a timestamp, which reads as a rendering bug.
    [$agent, $conversation, $visitor] = transcriptFixture();

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => '',
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/conversations/{$conversation->support_code}")
        ->assertOk()
        ->assertSee('class="message-empty"', false)
        ->assertSee('This message has no text or attachment.');
});

test('an attachment uses the icon set rather than an emoji', function (): void {
    // An emoji renders differently on every platform and cannot inherit the
    // colour around it.
    $transcript = (string) file_get_contents(
        base_path('resources/views/agent/conversations/partials/message-list.blade.php')
    );

    expect($transcript)->toContain('<x-icon name="attachment"')
        ->and($transcript)->not->toContain('📎');
});
