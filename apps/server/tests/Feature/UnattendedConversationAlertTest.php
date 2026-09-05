<?php

// The unattended alert cadence: email only when a visitor message has waited
// UNSEEN past the threshold — "unseen" being the unread ConversationNeedsReply
// notification that opening the conversation marks read. One email per waiting
// episode, metadata only, and nothing while someone is actually answering.

use App\Enums\AccountRole;
use App\Events\ConversationMessageCreated;
use App\Jobs\SendUnattendedConversationAlert;
use App\Listeners\NotifyAgentsOfVisitorMessage;
use App\Mail\UnattendedConversationAlertMessage;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use App\Support\Reporting\ReportingScope;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\SupportReport;
use App\Support\UnattendedConversationAlertCollector;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeTime();
});

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

    Mail::assertSent(UnattendedConversationAlertMessage::class, function (UnattendedConversationAlertMessage $mail): bool {
        $rendered = $mail->render();

        return $mail->hasTo('oncall@example.test')
            && str_contains($rendered, 'WF-WAITING1')
            && str_contains($rendered, 'Acme Docs')
            && ! str_contains($rendered, 'hunter2');
    });

    // A second sweep re-sends nothing: one email per waiting episode.
    Artisan::call('wayfindr:send-unattended-conversation-alerts');
    Mail::assertSentCount(1);
});

test('nothing sends while the wait is inside the threshold', function (): void {
    Mail::fake();

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $site = Site::factory()->for($account)->create();
    createUnattendedWait($agent, $site);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES - 2)->minutes();

    Artisan::call('wayfindr:send-unattended-conversation-alerts');

    Mail::assertNothingSent();
});

test('after-hours waiting pauses the unattended threshold until support reopens', function (): void {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-08-28 18:00', 'UTC'));

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $site = Site::factory()->for($account)->create([
        'settings' => ['availability' => [
            'enabled' => true,
            'timezone' => 'UTC',
            'weekdays' => [
                'mon' => ['09:00', '17:00'],
                'tue' => ['09:00', '17:00'],
                'wed' => ['09:00', '17:00'],
                'thu' => ['09:00', '17:00'],
                'fri' => ['09:00', '17:00'],
                'sat' => null,
                'sun' => null,
            ],
        ]],
    ]);
    createUnattendedWait($agent, $site);

    $this->travelTo(CarbonImmutable::parse('2026-08-31 09:04', 'UTC'));
    Artisan::call('wayfindr:send-unattended-conversation-alerts');
    Mail::assertNothingSent();

    $this->travelTo(CarbonImmutable::parse('2026-08-31 09:06', 'UTC'));
    Artisan::call('wayfindr:send-unattended-conversation-alerts');
    Mail::assertSentCount(1);
});

test('the unattended sweep locks account before site and conversation', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL exposes the row-lock clause used by this concurrency contract.');
    }

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $site = Site::factory()->for($account)->create(['settings' => ['availability' => ['enabled' => false]]]);
    createUnattendedWait($agent, $site);
    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $candidates = app(UnattendedConversationAlertCollector::class)->forAgent($agent);

    $queries = collect(DB::getQueryLog())->pluck('query')->values();
    DB::disableQueryLog();
    $accountLock = $queries->search(fn (string $query): bool => str_contains($query, 'from "accounts"')
        && str_contains($query, 'for update'));
    $siteLock = $queries->search(fn (string $query): bool => str_contains($query, 'from "sites"')
        && str_contains($query, 'for update'));
    $conversationLock = $queries->search(fn (string $query): bool => str_contains($query, 'from "conversations"')
        && str_contains($query, 'for update'));

    expect($candidates)->toHaveCount(1)
        ->and($accountLock)->toBeInt()
        ->and($siteLock)->toBeInt()
        ->and($conversationLock)->toBeInt()
        ->and($accountLock)->toBeLessThan($siteLock)
        ->and($siteLock)->toBeLessThan($conversationLock);
});

test('manually reopening a desk does not turn closed time into unattended wait time', function (): void {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-08-26 10:00', 'UTC'));

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account, ['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['settings' => []]);
    $conversation = createUnattendedWait($agent, $site);

    $this->travel(2)->minutes();
    $this->actingAs($agent)
        ->post(route('dashboard.sites.availability.close', $site), ['closure' => 'hour'])
        ->assertRedirect(route('dashboard.sites.show', $site));

    $this->travel(10)->minutes();
    $this->actingAs($agent)
        ->delete(route('dashboard.sites.availability.reopen', $site))
        ->assertRedirect(route('dashboard.sites.show', $site));

    $report = new SupportReport(
        ReportingScope::for($account, $agent),
        ReportingWindow::ofDays(30),
    );

    expect($conversation->fresh()->support_wait_elapsed_seconds)->toBe(2 * 60)
        ->and($report->queueHealth()['oldest_wait_seconds'])->toBe(2 * 60);

    Artisan::call('wayfindr:send-unattended-conversation-alerts');
    Mail::assertNothingSent();

    $this->travel(2)->minutes();
    Artisan::call('wayfindr:send-unattended-conversation-alerts');
    Mail::assertNothingSent();

    $this->travel(2)->minutes();
    Artisan::call('wayfindr:send-unattended-conversation-alerts');
    Mail::assertSentCount(1);
});

test('queue health preserves a waiting clock after its notification is read', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-26 10:00', 'UTC'));

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account, ['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['settings' => []]);
    $conversation = createUnattendedWait($agent, $site);

    $this->travel(2)->minutes();
    $agent->unreadNotifications()->update(['read_at' => now()]);
    $conversation->markReadFor($agent);

    $this->actingAs($agent)
        ->put(route('dashboard.sites.availability.update', $site), [
            'availability_enabled' => '1',
            'availability_timezone' => 'UTC',
        ])
        ->assertRedirect(route('dashboard.sites.show', $site));

    $report = new SupportReport(
        ReportingScope::for($account, $agent),
        ReportingWindow::ofDays(30),
    );

    expect($agent->unreadNotifications()->where('type', ConversationNeedsReply::class)->count())->toBe(0)
        ->and($conversation->fresh()->support_wait_elapsed_seconds)->toBe(2 * 60)
        ->and($report->queueHealth()['oldest_wait_seconds'])->toBe(2 * 60);
});

test('archiving pauses an unattended wait until the site is restored', function (): void {
    Mail::fake();

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account, ['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['settings' => []]);
    $conversation = createUnattendedWait($agent, $site);

    $this->travel(2)->minutes();
    $this->actingAs($agent)->post(route('dashboard.sites.archive', $site))->assertRedirect();

    $this->travel(10)->minutes();
    Artisan::call('wayfindr:send-unattended-conversation-alerts');
    Mail::assertNothingSent();

    $this->actingAs($agent)->post(route('dashboard.sites.unarchive', $site))->assertRedirect();

    $this->travel(2)->minutes();
    Artisan::call('wayfindr:send-unattended-conversation-alerts');
    Mail::assertNothingSent();

    $this->travel(2)->minutes();
    Artisan::call('wayfindr:send-unattended-conversation-alerts');

    expect($conversation->fresh()->support_wait_elapsed_seconds)->toBe(6 * 60);
    Mail::assertSentCount(1);
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

    Mail::assertNothingSent();
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

    Mail::assertNothingSent();
});

test('an unknown legacy conversation status never alerts', function (): void {
    Mail::fake();

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $site = Site::factory()->for($account)->create();
    $conversation = createUnattendedWait($agent, $site);
    // Model writes reject states outside the lifecycle contract. A database
    // restored from an older build can still contain one, so the collector
    // must continue to fail closed when it reads that row.
    DB::table('conversations')->where('id', $conversation->id)->update(['status' => 'resolved']);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();

    Artisan::call('wayfindr:send-unattended-conversation-alerts');

    Mail::assertNothingSent();
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

    Mail::assertNothingSent();
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

    Mail::assertNothingSent();
});

test('unattended email waits until agent quiet hours end', function (): void {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-06 01:00:00', 'UTC'));
    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account, [
        'email' => 'quiet-unattended@example.test',
        'timezone' => 'America/New_York',
        'alert_preferences' => [
            'quiet_hours' => [
                'enabled' => true,
                'start' => '22:00',
                'end' => '07:00',
            ],
        ],
    ]);
    $site = Site::factory()->for($account)->create();
    createUnattendedWait($agent, $site);

    $this->travelTo(CarbonImmutable::parse('2026-09-06 02:30:00', 'UTC'));
    Artisan::call('wayfindr:send-unattended-conversation-alerts', ['--email' => $agent->email]);
    Mail::assertNothingSent();

    $this->travelTo(CarbonImmutable::parse('2026-09-06 11:00:00', 'UTC'));
    Artisan::call('wayfindr:send-unattended-conversation-alerts', ['--email' => $agent->email]);
    Mail::assertSentCount(1);
});

test('a queued unattended alert rechecks quiet hours before sending or stamping the wait', function (): void {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-06 01:53:00', 'UTC'));
    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account, [
        'timezone' => 'America/New_York',
        'alert_preferences' => [
            'quiet_hours' => [
                'enabled' => true,
                'start' => '22:00',
                'end' => '07:00',
            ],
        ],
    ]);
    $site = Site::factory()->for($account)->create();
    createUnattendedWait($agent, $site);
    $job = new SendUnattendedConversationAlert($agent->id);

    $this->travelTo(CarbonImmutable::parse('2026-09-06 02:00:00', 'UTC'));
    $job->handle(app(UnattendedConversationAlertCollector::class));

    Mail::assertNothingSent();
    expect(data_get(
        $agent->fresh()->unreadNotifications->first()?->data,
        UnattendedConversationAlertCollector::UNATTENDED_EMAILED_AT_KEY,
    ))->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-09-06 11:00:00', 'UTC'));
    $job->handle(app(UnattendedConversationAlertCollector::class));

    Mail::assertSentCount(1);
    expect(data_get(
        $agent->fresh()->unreadNotifications->first()?->data,
        UnattendedConversationAlertCollector::UNATTENDED_EMAILED_AT_KEY,
    ))->not->toBeNull();
});

test('a queued unattended alert rechecks deactivation before sending', function (): void {
    Mail::fake();
    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $site = Site::factory()->for($account)->create();
    createUnattendedWait($agent, $site);
    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();
    $collector = new class extends UnattendedConversationAlertCollector
    {
        public function claimForDelivery(User $agent, Collection $candidates, string $claim): Collection
        {
            $claimed = parent::claimForDelivery($agent, $candidates, $claim);
            $agent->forceFill(['deactivated_at' => now()])->save();

            return $claimed;
        }
    };

    (new SendUnattendedConversationAlert($agent->id))->handle($collector);

    Mail::assertNothingSent();
    $notification = $agent->fresh()->unreadNotifications->firstOrFail();
    expect(data_get($notification->data, UnattendedConversationAlertCollector::UNATTENDED_DELIVERY_CLAIM_KEY))->toBeNull();
});

test('an accepted unattended alert keeps a durable claim when finalization is uncertain', function (): void {
    Mail::fake();
    Log::spy();
    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $site = Site::factory()->for($account)->create();
    $conversation = createUnattendedWait($agent, $site);
    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();
    $collector = new class extends UnattendedConversationAlertCollector
    {
        public function acceptDeliveryClaim(Collection $candidates, string $claim, CarbonInterface $acceptedAt): void
        {
            throw new RuntimeException('Database unavailable after SMTP acceptance.');
        }
    };
    $job = new SendUnattendedConversationAlert($agent->id);

    $job->handle($collector);

    Mail::assertSentCount(1);
    $notification = $agent->fresh()->unreadNotifications->firstOrFail();
    $deliveryClaim = data_get($notification->data, UnattendedConversationAlertCollector::UNATTENDED_DELIVERY_CLAIM_KEY);

    expect($deliveryClaim)->toBeString()->not->toBe('')
        ->and(data_get($notification->data, UnattendedConversationAlertCollector::UNATTENDED_EMAILED_AT_KEY))->toBeNull()
        ->and(app(UnattendedConversationAlertCollector::class)->forAgent($agent->fresh()))->toBeEmpty();

    $job->handle(app(UnattendedConversationAlertCollector::class));
    Mail::assertSentCount(1);
    Log::shouldHaveReceived('critical')->once();

    $followUp = ConversationMessage::factory()->for($conversation)->create([
        'body' => 'One more detail while I wait.',
        'sender_id' => $conversation->visitor_id,
        'sender_type' => Visitor::class,
    ]);
    app(NotifyAgentsOfVisitorMessage::class)->handle(new ConversationMessageCreated($followUp));

    expect(data_get(
        $agent->fresh()->unreadNotifications->firstOrFail()->data,
        UnattendedConversationAlertCollector::UNATTENDED_DELIVERY_CLAIM_KEY,
    ))->toBe($deliveryClaim);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();
    $job->handle(app(UnattendedConversationAlertCollector::class));
    Mail::assertSentCount(1);
});

test('a rejected unattended transport releases its claim for retry', function (): void {
    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $site = Site::factory()->for($account)->create();
    createUnattendedWait($agent, $site);
    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();
    Mail::shouldReceive('to')
        ->once()
        ->with($agent->email)
        ->andThrow(new RuntimeException('SMTP rejected the message.'));
    $job = new SendUnattendedConversationAlert($agent->id);

    expect(fn () => $job->handle(app(UnattendedConversationAlertCollector::class)))
        ->toThrow(RuntimeException::class, 'SMTP rejected the message.');

    $notification = $agent->fresh()->unreadNotifications->firstOrFail();

    expect(data_get($notification->data, UnattendedConversationAlertCollector::UNATTENDED_DELIVERY_CLAIM_KEY))->toBeNull()
        ->and(app(UnattendedConversationAlertCollector::class)->forAgent($agent->fresh()))->toHaveCount(1);
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
    Mail::assertSentCount(1);

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

    Mail::assertSentCount(1);
});

test('a follow-up retries its refresh when a delivery claim lands after the read', function (): void {
    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $site = Site::factory()->for($account)->create();
    $conversation = createUnattendedWait($agent, $site);
    $notification = $agent->unreadNotifications()->firstOrFail();
    $racingData = [
        ...$notification->data,
        UnattendedConversationAlertCollector::UNATTENDED_DELIVERY_CLAIM_KEY => 'racing-delivery-claim',
    ];
    $injected = false;
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$injected, &$queries, $notification, $racingData): void {
        if ($injected) {
            return;
        }

        $queries[] = $query->sql;

        if (! str_contains($query->sql, 'from "notifications"')
            || ! str_contains($query->sql, 'order by "id"')) {
            return;
        }

        // Interleave the worker write after the listener SELECT has returned
        // stale data but before its compare-and-swap update.
        $injected = true;
        DB::table('notifications')
            ->where('id', $notification->id)
            ->update(['data' => json_encode($racingData)]);
    });

    $followUp = ConversationMessage::factory()->for($conversation)->create([
        'body' => 'Adding a detail while the alert is claimed.',
        'sender_id' => $conversation->visitor_id,
        'sender_type' => Visitor::class,
    ]);
    app(NotifyAgentsOfVisitorMessage::class)->handle(new ConversationMessageCreated($followUp));

    $refreshed = $notification->fresh();

    expect($injected)->toBeTrue(implode("\n", $queries))
        ->and(data_get($refreshed->data, UnattendedConversationAlertCollector::UNATTENDED_DELIVERY_CLAIM_KEY))->toBe('racing-delivery-claim')
        ->and(data_get($refreshed->data, 'message_count'))->toBe(2);
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
    Mail::assertSentCount(1);

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
    Mail::assertSentCount(1);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES)->minutes();
    Artisan::call('wayfindr:send-unattended-conversation-alerts');

    Mail::assertSentCount(2);
});

test('a new wait after the first was handled alerts again', function (): void {
    Mail::fake();

    $account = Account::factory()->create();
    $agent = unattendedAlertAgent($account);
    $site = Site::factory()->for($account)->create();
    createUnattendedWait($agent, $site);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();
    Artisan::call('wayfindr:send-unattended-conversation-alerts');
    Mail::assertSentCount(1);

    // The agent handles it (notification read), then a NEW visitor wait
    // begins in another conversation.
    $agent->unreadNotifications()->update(['read_at' => now()]);
    createUnattendedWait($agent, $site);

    $this->travel(UnattendedConversationAlertCollector::THRESHOLD_MINUTES + 1)->minutes();
    Artisan::call('wayfindr:send-unattended-conversation-alerts');

    Mail::assertSentCount(2);
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

    Mail::assertSentCount(1);
    Mail::assertSent(UnattendedConversationAlertMessage::class, function (UnattendedConversationAlertMessage $mail): bool {
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

    Mail::assertNothingSent();
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

    Mail::assertSentCount(1);
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

    Mail::assertNothingSent();
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
    Mail::assertNothingSent();

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

    Mail::assertSentCount(1);
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
    $claimedCandidates = $collector->claimForDelivery($agent, $staleCandidates, 'stale-episode-claim');
    expect($claimedCandidates)->toHaveCount(1);

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

    $collector->acceptDeliveryClaim($claimedCandidates, 'stale-episode-claim', now());

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
