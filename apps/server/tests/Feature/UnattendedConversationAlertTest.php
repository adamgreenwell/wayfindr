<?php

// The unattended alert cadence: email only when a visitor message has waited
// UNSEEN past the threshold — "unseen" being the unread ConversationNeedsReply
// notification that opening the conversation marks read. One email per waiting
// episode, metadata only, and nothing while someone is actually answering.

use App\Enums\AccountRole;
use App\Events\ConversationMessageCreated;
use App\Listeners\NotifyAgentsOfVisitorMessage;
use App\Mail\UnattendedConversationAlertMessage;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use App\Support\UnattendedConversationAlertCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function unattendedAlertAgent(Account $account, array $overrides = []): User
{
    return User::factory()->for($account)->create(array_replace_recursive([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_UNATTENDED,
        ],
    ], $overrides));
}

/**
 * A visitor message lands and nobody has seen it: the notification exists,
 * unread, exactly as NotifyAgentsOfVisitorMessage leaves it.
 */
function createUnattendedWait(User $agent, Site $site, array $conversationOverrides = []): Conversation
{
    $site->supportAgents()->syncWithoutDetaching($agent->id);

    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create(array_replace([
            'support_code' => fake()->unique()->bothify('WF-######'),
            'subject' => 'Support request',
            'status' => 'open',
        ], $conversationOverrides));

    $message = ConversationMessage::factory()->for($conversation)->create([
        'body' => 'My password is hunter2 — please keep this out of email.',
        'sender_id' => $visitor->id,
        'sender_type' => Visitor::class,
    ]);

    $conversation->forceFill(['last_message_at' => $message->created_at])->save();
    $agent->notify(new ConversationNeedsReply($message));

    return $conversation;
}

test('a visitor waiting unseen past the threshold triggers one metadata-only email', function (): void {
    Mail::fake();

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account, ['email' => 'oncall@example.test', 'name' => 'On Call']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $conversation = createUnattendedWait($agent, $site, ['support_code' => 'WF-WAITING1']);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();

    expect(Artisan::call('wayfindr:send-unattended-conversation-alerts'))->toBe(0)
        ->and(Artisan::output())->toContain('Queued unattended alert for On Call <oncall@example.test> with 1 waiting conversation(s).');

    Mail::assertQueued(UnattendedConversationAlertMessage::class, function (UnattendedConversationAlertMessage $mail): bool {
        $rendered = $mail->render();

        return $mail->hasTo('oncall@example.test')
            && str_contains($rendered, 'WF-WAITING1')
            && str_contains($rendered, 'Acme Docs')
            && ! str_contains($rendered, 'hunter2');
    });

    // A second sweep re-sends nothing: one email per waiting episode.
    Artisan::call('wayfindr:send-unattended-conversation-alerts');
    Mail::assertQueuedCount(1);
});

test('nothing sends while the wait is inside the threshold', function (): void {
    Mail::fake();

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $site = Site::factory()->for($account)->create();
    createUnattendedWait($agent, $site);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES - 2)->minutes();

    Artisan::call('wayfindr:send-unattended-conversation-alerts');

    Mail::assertNothingQueued();
});

test('nothing sends once the agent has seen the conversation', function (): void {
    Mail::fake();

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $site = Site::factory()->for($account)->create();
    createUnattendedWait($agent, $site);

    // Opening the conversation marks the notification read (the dashboard's
    // behavior) — that IS "someone saw it".
    $agent->unreadNotifications()->update(['read_at' => now()]);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();

    Artisan::call('wayfindr:send-unattended-conversation-alerts');

    Mail::assertNothingQueued();
});

test('nothing sends once an agent has replied, even with the notification unread', function (): void {
    Mail::fake();

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $site = Site::factory()->for($account)->create();
    $conversation = createUnattendedWait($agent, $site);

    ConversationMessage::factory()->for($conversation)->create([
        'body' => 'On it — looking now.',
        'sender_id' => $agent->id,
        'sender_type' => User::class,
    ]);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();

    Artisan::call('wayfindr:send-unattended-conversation-alerts');

    Mail::assertNothingQueued();
});

test('a resolved conversation never alerts', function (): void {
    Mail::fake();

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $site = Site::factory()->for($account)->create();
    $conversation = createUnattendedWait($agent, $site);
    $conversation->forceFill(['status' => 'resolved'])->save();

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();

    Artisan::call('wayfindr:send-unattended-conversation-alerts');

    Mail::assertNothingQueued();
});

test('immediate- and digest-cadence agents are not touched by this command', function (): void {
    Mail::fake();

    $account = Account::factory()->create();
    $immediate = unattendedAlertAgent($account, ['alert_preferences' => ['cadence' => User::ALERT_CADENCE_IMMEDIATE]]);
    $digest = unattendedAlertAgent($account, ['alert_preferences' => ['cadence' => User::ALERT_CADENCE_DIGEST]]);
    $site = Site::factory()->for($account)->create();
    createUnattendedWait($immediate, $site);
    createUnattendedWait($digest, $site);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();

    Artisan::call('wayfindr:send-unattended-conversation-alerts');

    Mail::assertNothingQueued();
});

test('quiet-mode and deactivated agents are skipped', function (): void {
    Mail::fake();

    $account = Account::factory()->create();
    $quiet = unattendedAlertAgent($account, ['alert_preferences' => ['mode' => User::ALERT_MODE_QUIET]]);
    $deactivated = unattendedAlertAgent($account, ['deactivated_at' => now()]);
    $site = Site::factory()->for($account)->create();
    createUnattendedWait($quiet, $site);
    createUnattendedWait($deactivated, $site);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();

    Artisan::call('wayfindr:send-unattended-conversation-alerts');

    Mail::assertNothingQueued();
});

test('a follow-up message inside the same wait does not re-arm the email', function (): void {
    // The listener refreshes the unread notification's data on every new
    // visitor message; the unattended stamp must survive that refresh or a
    // chatty waiting visitor would be re-emailed every sweep.
    Mail::fake();

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $site = Site::factory()->for($account)->create();
    $conversation = createUnattendedWait($agent, $site);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();
    Artisan::call('wayfindr:send-unattended-conversation-alerts');
    Mail::assertQueuedCount(1);

    // The visitor keeps typing before anyone sees it — through the REAL
    // listener path, which merges the notification data in place.
    $followUp = ConversationMessage::factory()->for($conversation)->create([
        'body' => 'Hello? Anyone there?',
        'sender_id' => $conversation->visitor_id,
        'sender_type' => Visitor::class,
    ]);
    app(NotifyAgentsOfVisitorMessage::class)
        ->handle(new ConversationMessageCreated($followUp));

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();
    Artisan::call('wayfindr:send-unattended-conversation-alerts');

    Mail::assertQueuedCount(1);
});

test('a new visitor wait after an agent handled the last one re-arms the email', function (): void {
    // The other side of stamp preservation: an agent reply ends the episode
    // even if this recipient's notification stayed unread, so the next
    // visitor message merges WITHOUT the old stamp and emails again.
    Mail::fake();

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $colleague = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $conversation = createUnattendedWait($agent, $site);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();
    Artisan::call('wayfindr:send-unattended-conversation-alerts');
    Mail::assertQueuedCount(1);

    // A colleague answers; the recipient's notification stays unread.
    $this->travel(1)->minutes();
    ConversationMessage::factory()->for($conversation)->create([
        'body' => 'Taking this one.',
        'sender_id' => $colleague->id,
        'sender_type' => User::class,
    ]);

    // The visitor comes back — a genuinely new wait, through the real
    // listener merge path.
    $this->travel(1)->minutes();
    $newWait = ConversationMessage::factory()->for($conversation)->create([
        'body' => 'Still broken, unfortunately.',
        'sender_id' => $conversation->visitor_id,
        'sender_type' => Visitor::class,
    ]);
    app(NotifyAgentsOfVisitorMessage::class)->handle(new ConversationMessageCreated($newWait));

    // The new episode gets its own full threshold: a sweep right away sends
    // nothing, even though the notification ROW is long past it.
    $this->travel(2)->minutes();
    Artisan::call('wayfindr:send-unattended-conversation-alerts');
    Mail::assertQueuedCount(1);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES)->minutes();
    Artisan::call('wayfindr:send-unattended-conversation-alerts');

    Mail::assertQueuedCount(2);
});

test('a new wait after the first was handled alerts again', function (): void {
    Mail::fake();

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $site = Site::factory()->for($account)->create();
    createUnattendedWait($agent, $site);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();
    Artisan::call('wayfindr:send-unattended-conversation-alerts');
    Mail::assertQueuedCount(1);

    // The agent handles it (notification read), then a NEW visitor wait
    // begins in another conversation.
    $agent->unreadNotifications()->update(['read_at' => now()]);
    createUnattendedWait($agent, $site);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();
    Artisan::call('wayfindr:send-unattended-conversation-alerts');

    Mail::assertQueuedCount(2);
});

test('two waiting visitors arrive in one email, not two', function (): void {
    Mail::fake();

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $site = Site::factory()->for($account)->create();
    createUnattendedWait($agent, $site, ['support_code' => 'WF-FIRSTONE']);
    createUnattendedWait($agent, $site, ['support_code' => 'WF-SECONDTW']);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();

    Artisan::call('wayfindr:send-unattended-conversation-alerts');

    Mail::assertQueuedCount(1);
    Mail::assertQueued(UnattendedConversationAlertMessage::class, function (UnattendedConversationAlertMessage $mail): bool {
        $rendered = $mail->render();

        return str_contains($rendered, 'WF-FIRSTONE')
            && str_contains($rendered, 'WF-SECONDTW')
            && str_contains($mail->envelope()->subject, '2 visitors');
    });
});

test('a colleague opening the conversation quiets everyone\'s email', function (): void {
    // "Unseen" is account-wide: another agent's view marks only their own
    // notification read, but the wait HAS been seen — nobody needs the email.
    Mail::fake();

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $colleague = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $conversation = createUnattendedWait($agent, $site);

    $colleague->notify(new ConversationNeedsReply($conversation->messages()->firstOrFail()));
    $this->travel(1)->minutes();
    // The colleague opens the conversation — only THEIR notification reads.
    $colleague->unreadNotifications()->update(['read_at' => now()]);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES)->minutes();

    Artisan::call('wayfindr:send-unattended-conversation-alerts');

    Mail::assertNothingQueued();
});

test('a read sharing the episode\'s starting second does not suppress the email', function (): void {
    // Second-precision boundary: a read from the PREVIOUS episode can land on
    // the same second the new episode starts. Counting it as seen starves the
    // visitor — the worse error — so the comparison is strictly-after.
    Mail::fake();

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $colleague = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $conversation = createUnattendedWait($agent, $site);

    $episodeStart = $agent->unreadNotifications()->firstOrFail()->created_at;
    $conversation->markReadFor($colleague, $episodeStart);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();

    Artisan::call('wayfindr:send-unattended-conversation-alerts');

    Mail::assertQueuedCount(1);
});

test('a queue walk-in view with no notification of their own still counts as seen', function (): void {
    // ConversationReadState is written on every conversation open — including
    // by agents who were never notified. That view quiets the email too.
    Mail::fake();

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $walkIn = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $conversation = createUnattendedWait($agent, $site);

    $this->travel(1)->minutes();
    $conversation->markReadFor($walkIn);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES)->minutes();

    Artisan::call('wayfindr:send-unattended-conversation-alerts');

    Mail::assertNothingQueued();
});

test('a visitor follow-up after being seen but not answered starts a new wait', function (): void {
    // Without the seen boundary, "viewed but never answered" would suppress
    // alerts forever: the old episode start predates the colleague's read, so
    // every future sweep stays quiet. The follow-up must re-arm.
    Mail::fake();

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $colleague = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $conversation = createUnattendedWait($agent, $site);

    $colleague->notify(new ConversationNeedsReply($conversation->messages()->firstOrFail()));
    $this->travel(1)->minutes();
    // Seen, never answered.
    $colleague->unreadNotifications()->update(['read_at' => now()]);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES)->minutes();
    Artisan::call('wayfindr:send-unattended-conversation-alerts');
    Mail::assertNothingQueued();

    // The visitor asks again — a genuinely new wait.
    $this->travel(1)->minutes();
    $followUp = ConversationMessage::factory()->for($conversation)->create([
        'body' => 'Is anyone looking at this?',
        'sender_id' => $conversation->visitor_id,
        'sender_type' => Visitor::class,
    ]);
    app(NotifyAgentsOfVisitorMessage::class)->handle(new ConversationMessageCreated($followUp));

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();
    Artisan::call('wayfindr:send-unattended-conversation-alerts');

    Mail::assertQueuedCount(1);
});

test('the sweep never stamps an episode it did not email', function (): void {
    // The listener can re-arm a notification between candidate collection and
    // the stamp write; the guard must leave the NEW episode unstamped so its
    // email still goes out.
    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $colleague = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $conversation = createUnattendedWait($agent, $site);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();

    $collector = app(UnattendedConversationAlertCollector::class);
    $staleCandidates = $collector->forAgent($agent);
    expect($staleCandidates)->toHaveCount(1);

    // Interleave: colleague reply + fresh visitor message re-arm the same
    // notification before the stamp lands.
    ConversationMessage::factory()->for($conversation)->create([
        'body' => 'Handled.',
        'sender_id' => $colleague->id,
        'sender_type' => User::class,
    ]);
    $this->travel(1)->minutes();
    $newWait = ConversationMessage::factory()->for($conversation)->create([
        'body' => 'Still stuck.',
        'sender_id' => $conversation->visitor_id,
        'sender_type' => Visitor::class,
    ]);
    app(NotifyAgentsOfVisitorMessage::class)->handle(new ConversationMessageCreated($newWait));

    $collector->stampEmailed($staleCandidates, now());

    $notification = $agent->unreadNotifications()->firstOrFail();

    expect(data_get($notification->data, UnattendedConversationAlertCollector::UNATTENDED_EMAILED_AT_KEY))->toBeNull();
});

test('the profile page offers the unattended cadence and reports it', function (): void {
    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);

    $this->actingAs($agent)
        ->get(route('dashboard.profile.show'))
        ->assertOk()
        ->assertSee('Email only when a visitor waits unseen')
        ->assertSee('Unattended only');
});

test('the alert center and account roster name the unattended cadence, not Immediate', function (): void {
    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account, ['account_role' => AccountRole::Admin]);

    $this->actingAs($agent)
        ->get(route('dashboard.alerts.index'))
        ->assertOk()
        ->assertSee('Unattended only')
        ->assertDontSee('Immediate email');

    $this->actingAs($agent)
        ->get(route('dashboard.account.show'))
        ->assertOk()
        ->assertSee('Unattended only');
});
