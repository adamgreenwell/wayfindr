<?php

use App\Jobs\SendAgentAlertDigest;
use App\Mail\AlertDigestMessage;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use App\Notifications\TicketAssigned;
use App\Support\AlertDigestCandidateCollector;
use App\Support\Sla\SlaAlertRouting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('alert digest send command queues metadata-only digest mail', function (): void {
    Mail::fake();

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = digestMailAgent($account, [
        'email' => 'digest-agent@example.test',
        'name' => 'Digest Agent',
    ]);
    $assigningAgent = User::factory()->for($account)->create(['name' => 'Ada Manager']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);

    createDigestMailConversationAlert(
        agent: $agent,
        site: $site,
        body: 'My password is hunter2 and my card is 4111 1111 1111 1111.',
        conversationOverrides: [
            'support_code' => 'WF-DIGESTMAIL',
            'subject' => 'Checkout trouble',
        ],
    );

    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'description' => 'Private ticket details should stay out of digest email.',
            'priority' => 'high',
            'status' => 'open',
            'subject' => 'Billing follow-up',
        ]);

    $agent->notify(new TicketAssigned($ticket, $assigningAgent));

    $exitCode = Artisan::call('wayfindr:send-alert-digests', [
        '--email' => $agent->email,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Alert digest delivery')
        ->toContain('Queued digest for Digest Agent <digest-agent@example.test> with 2 candidates.')
        ->toContain('Alert digest delivery complete. Agents scanned: 1. Emails queued: 1. Candidates: 2.');

    $deliveryStatus = data_get($agent->fresh()->alert_preferences, 'digest_delivery');

    expect($deliveryStatus['status'])->toBe('queued')
        ->and($deliveryStatus['candidate_count'])->toBe(2)
        ->and($deliveryStatus['message'])->toBe('Queued digest email with 2 alerts.')
        ->and($deliveryStatus['last_attempted_at'])->toBeString()->not->toBe('');

    Mail::assertSent(AlertDigestMessage::class, function (AlertDigestMessage $mail) use ($agent, $ticket): bool {
        $renderedMail = $mail->render();

        return $mail->hasTo($agent->email)
            && $mail->candidateCount() === 2
            && str_contains($renderedMail, 'WF-DIGESTMAIL')
            && str_contains($renderedMail, 'Checkout trouble')
            && str_contains($renderedMail, "Ticket #{$ticket->id}")
            && str_contains($renderedMail, 'Billing follow-up')
            && ! str_contains($renderedMail, 'hunter2')
            && ! str_contains($renderedMail, '4111 1111 1111 1111')
            && ! str_contains($renderedMail, 'Private ticket details')
            && ! str_contains($renderedMail, 'visitor_anonymous_id')
            && ! str_contains($renderedMail, 'message_preview');
    });

    $agent->fresh()->unreadNotifications->each(function ($notification): void {
        expect(data_get($notification->data, 'digest_queued_at'))->not->toBeNull();
    });

    $exitCode = Artisan::call('wayfindr:send-alert-digests', [
        '--email' => $agent->email,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('No alert digest emails queued.')
        ->toContain('Alert digest delivery complete. Agents scanned: 1. Emails queued: 0. Candidates: 0.');

    Mail::assertSentCount(1);

    $this->travelTo(now()->addMinutes(5));
    $ticket->forceFill([
        'priority' => 'normal',
    ])->save();

    $exitCode = Artisan::call('wayfindr:send-alert-digests', [
        '--email' => $agent->email,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Queued digest for Digest Agent <digest-agent@example.test> with 1 candidates.')
        ->toContain('Alert digest delivery complete. Agents scanned: 1. Emails queued: 1. Candidates: 1.');

    $deliveryStatus = data_get($agent->fresh()->alert_preferences, 'digest_delivery');

    expect($deliveryStatus['status'])->toBe('queued')
        ->and($deliveryStatus['candidate_count'])->toBe(1)
        ->and($deliveryStatus['message'])->toBe('Queued digest email with 1 alert.')
        ->and($deliveryStatus['last_attempted_at'])->toBeString()->not->toBe('');

    Mail::assertSent(AlertDigestMessage::class, function (AlertDigestMessage $mail) use ($agent, $ticket): bool {
        $renderedMail = $mail->render();

        return $mail->hasTo($agent->email)
            && $mail->candidateCount() === 1
            && str_contains($renderedMail, "Ticket #{$ticket->id}")
            && str_contains($renderedMail, 'Billing follow-up')
            && ! str_contains($renderedMail, 'WF-DIGESTMAIL')
            && ! str_contains($renderedMail, 'Checkout trouble');
    });

    Mail::assertSentCount(2);
});

test('alert digest send command reports empty and missing-agent states without queueing mail', function (): void {
    Mail::fake();

    $account = Account::factory()->create();
    $agent = digestMailAgent($account, [
        'email' => 'empty-digest@example.test',
        'name' => 'Empty Digest',
    ]);

    $exitCode = Artisan::call('wayfindr:send-alert-digests', [
        '--email' => $agent->email,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('No alert digest emails queued.')
        ->toContain('Alert digest delivery complete. Agents scanned: 1. Emails queued: 0. Candidates: 0.');

    $deliveryStatus = data_get($agent->fresh()->alert_preferences, 'digest_delivery');

    expect($deliveryStatus['status'])->toBe('no_alerts')
        ->and($deliveryStatus['candidate_count'])->toBe(0)
        ->and($deliveryStatus['message'])->toBe('No digest-ready alerts found.')
        ->and($deliveryStatus['last_attempted_at'])->toBeString()->not->toBe('');

    Mail::assertNothingSent();

    $exitCode = Artisan::call('wayfindr:send-alert-digests', [
        '--email' => 'missing@example.test',
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('No agent found for missing@example.test.');
});

test('alert digest delivery waits until agent quiet hours end', function (): void {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-06 02:30:00', 'UTC'));
    $account = Account::factory()->create();
    $agent = digestMailAgent($account, [
        'email' => 'quiet-digest@example.test',
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
    createDigestMailConversationAlert(agent: $agent, site: $site);

    expect(Artisan::call('wayfindr:send-alert-digests', ['--email' => $agent->email]))->toBe(0)
        ->and(Artisan::output())->toContain('Agents scanned: 0. Emails queued: 0. Candidates: 0.');
    Mail::assertNothingSent();
    expect(data_get($agent->fresh()->alert_preferences, 'digest_delivery'))->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-09-06 11:00:00', 'UTC'));

    expect(Artisan::call('wayfindr:send-alert-digests', ['--email' => $agent->email]))->toBe(0)
        ->and(Artisan::output())->toContain('Agents scanned: 1. Emails queued: 1. Candidates: 1.');
    Mail::assertSentCount(1);
});

test('a queued digest rechecks quiet hours before sending or stamping candidates', function (): void {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-06 01:59:00', 'UTC'));
    $account = Account::factory()->create();
    $agent = digestMailAgent($account, [
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
    createDigestMailConversationAlert(agent: $agent, site: $site);
    $job = new SendAgentAlertDigest($agent->id, 1);

    $this->travelTo(CarbonImmutable::parse('2026-09-06 02:00:00', 'UTC'));
    $job->handle(app(AlertDigestCandidateCollector::class));

    Mail::assertNothingSent();
    expect(data_get($agent->fresh()->alert_preferences, 'digest_delivery'))->toBeNull();
    $agent->fresh()->unreadNotifications->each(function ($notification): void {
        expect(data_get($notification->data, AlertDigestCandidateCollector::DIGEST_QUEUED_AT_KEY))->toBeNull();
    });

    $this->travelTo(CarbonImmutable::parse('2026-09-06 11:00:00', 'UTC'));
    $job->handle(app(AlertDigestCandidateCollector::class));

    Mail::assertSentCount(1);
    expect(data_get($agent->fresh()->alert_preferences, 'digest_delivery.status'))->toBe(User::ALERT_DIGEST_DELIVERY_QUEUED);
    $agent->fresh()->unreadNotifications->each(function ($notification): void {
        expect(data_get($notification->data, AlertDigestCandidateCollector::DIGEST_QUEUED_AT_KEY))->not->toBeNull();
    });
});

test('an accepted digest keeps a durable claim when finalization is uncertain', function (): void {
    Mail::fake();
    Log::spy();
    $account = Account::factory()->create();
    $agent = digestMailAgent($account);
    $site = Site::factory()->for($account)->create();
    createDigestMailConversationAlert(agent: $agent, site: $site);
    $collector = new class(app(SlaAlertRouting::class)) extends AlertDigestCandidateCollector
    {
        public function acceptDeliveryClaim(User $agent, Collection $candidates, string $claim, CarbonInterface $acceptedAt): void
        {
            throw new RuntimeException('Database unavailable after SMTP acceptance.');
        }
    };
    $job = new SendAgentAlertDigest($agent->id, 1);

    $job->handle($collector);

    Mail::assertSentCount(1);
    $notification = $agent->fresh()->unreadNotifications->firstOrFail();
    $deliveryClaim = data_get($notification->data, AlertDigestCandidateCollector::DIGEST_DELIVERY_CLAIM_KEY);

    expect($deliveryClaim)->toBeArray()
        ->and(data_get($deliveryClaim, 'token'))->toBeString()->not->toBe('')
        ->and(data_get($notification->data, AlertDigestCandidateCollector::DIGEST_QUEUED_AT_KEY))->toBeNull()
        ->and(app(AlertDigestCandidateCollector::class)->forAgent($agent->fresh()))->toBeEmpty();

    $job->handle(app(AlertDigestCandidateCollector::class));
    Mail::assertSentCount(1);
    Log::shouldHaveReceived('critical')->once();
});

test('an uncertain digest claim does not suppress an advanced alert version', function (): void {
    Mail::fake();
    $account = Account::factory()->create();
    $agent = digestMailAgent($account);
    $assigningAgent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create();
    $agent->notify(new TicketAssigned($ticket, $assigningAgent));
    $collector = app(AlertDigestCandidateCollector::class);
    $original = $collector->forAgent($agent);

    expect($original)->toHaveCount(1)
        ->and($collector->claimForDelivery($agent, $original, 'uncertain-old-version'))->toHaveCount(1)
        ->and($collector->forAgent($agent->fresh()))->toBeEmpty();

    $this->travel(1)->minutes();
    $ticket->forceFill(['priority' => 'high'])->save();

    $advanced = $collector->forAgent($agent->fresh());

    expect($advanced)->toHaveCount(1)
        ->and($advanced->first()['last_activity_at'])->not->toBe($original->first()['last_activity_at']);

    (new SendAgentAlertDigest($agent->id, 1))->handle($collector);

    Mail::assertSentCount(1);
    $notification = $agent->fresh()->unreadNotifications->firstOrFail();
    expect(data_get($notification->data, AlertDigestCandidateCollector::DIGEST_DELIVERY_CLAIM_KEY))->toBeNull()
        ->and(data_get($notification->data, AlertDigestCandidateCollector::DIGEST_QUEUED_AT_KEY))->not->toBeNull();
});

test('alert digest send command records failed delivery attempts', function (): void {
    $account = Account::factory()->create();
    $agent = digestMailAgent($account, [
        'email' => 'failing-digest@example.test',
        'name' => 'Failing Digest',
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Failure Docs']);

    createDigestMailConversationAlert(
        agent: $agent,
        site: $site,
        conversationOverrides: ['support_code' => 'WF-FAILMAIL'],
    );

    Mail::shouldReceive('to')
        ->once()
        ->with($agent->email)
        ->andThrow(new RuntimeException('SMTP cratered'));
    Log::shouldReceive('warning')
        ->once()
        ->with('Alert digest delivery failed.', Mockery::on(fn (array $context): bool => $context['agent_id'] === $agent->id
            && $context['agent_email'] === $agent->email
            && $context['exception'] instanceof RuntimeException
            && $context['exception']->getMessage() === 'SMTP cratered'));

    $exitCode = Artisan::call('wayfindr:send-alert-digests', [
        '--email' => $agent->email,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Failed digest for Failing Digest <failing-digest@example.test>.')
        ->toContain('Alert digest delivery complete. Agents scanned: 1. Emails queued: 0. Candidates: 1. Failed: 1.');

    $deliveryStatus = data_get($agent->fresh()->alert_preferences, 'digest_delivery');

    expect($deliveryStatus['status'])->toBe('failed')
        ->and($deliveryStatus['candidate_count'])->toBe(1)
        ->and($deliveryStatus['message'])->toBe('Digest email could not be queued.')
        ->and($deliveryStatus)->not->toHaveKey('error')
        ->and($deliveryStatus['last_attempted_at'])->toBeString()->not->toBe('');

    $notification = $agent->fresh()->unreadNotifications->firstOrFail();

    expect(data_get($notification->data, AlertDigestCandidateCollector::DIGEST_DELIVERY_CLAIM_KEY))->toBeNull()
        ->and(app(AlertDigestCandidateCollector::class)->forAgent($agent->fresh()))->toHaveCount(1);
});

test('digest delivery state locks the account before updating the agent preferences', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL exposes the row-lock clause used by this concurrency contract.');
    }

    $account = Account::factory()->create();
    $agent = digestMailAgent($account);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $agent->recordAlertDigestDelivery([
        'status' => User::ALERT_DIGEST_DELIVERY_NO_ALERTS,
        'candidate_count' => 0,
    ]);

    $queries = collect(DB::getQueryLog())->pluck('query')->values();
    DB::disableQueryLog();
    $accountLock = $queries->search(fn (string $query): bool => str_contains($query, 'from "accounts"')
        && str_contains($query, 'for update'));
    $agentLock = $queries->search(fn (string $query): bool => str_contains($query, 'from "users"')
        && str_contains($query, 'for update'));

    expect($accountLock)->toBeInt()
        ->and($agentLock)->toBeInt()
        ->and($accountLock)->toBeLessThan($agentLock);
});

function digestMailAgent(Account $account, array $overrides = []): User
{
    return User::factory()->for($account)->create(array_replace_recursive([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_DIGEST,
        ],
    ], $overrides));
}

function createDigestMailConversationAlert(
    User $agent,
    Site $site,
    string $body = 'The checkout button is still stuck.',
    array $conversationOverrides = [],
): Conversation {
    $site->supportAgents()->syncWithoutDetaching($agent->id);

    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create(array_replace([
            'support_code' => fake()->unique()->bothify('WF-######'),
            'subject' => 'Support request',
        ], $conversationOverrides));

    $message = ConversationMessage::factory()->for($conversation)->create([
        'body' => $body,
        'sender_id' => $visitor->id,
        'sender_type' => Visitor::class,
    ]);

    $conversation->forceFill(['last_message_at' => $message->created_at])->save();
    $agent->notify(new ConversationNeedsReply($message));

    return $conversation;
}
