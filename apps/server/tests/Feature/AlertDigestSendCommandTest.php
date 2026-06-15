<?php

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

    Mail::assertQueued(AlertDigestMessage::class, function (AlertDigestMessage $mail) use ($agent, $ticket): bool {
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

    Mail::assertQueuedCount(1);

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

    Mail::assertQueued(AlertDigestMessage::class, function (AlertDigestMessage $mail) use ($agent, $ticket): bool {
        $renderedMail = $mail->render();

        return $mail->hasTo($agent->email)
            && $mail->candidateCount() === 1
            && str_contains($renderedMail, "Ticket #{$ticket->id}")
            && str_contains($renderedMail, 'Billing follow-up')
            && ! str_contains($renderedMail, 'WF-DIGESTMAIL')
            && ! str_contains($renderedMail, 'Checkout trouble');
    });

    Mail::assertQueuedCount(2);
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

    Mail::assertNothingQueued();

    $exitCode = Artisan::call('wayfindr:send-alert-digests', [
        '--email' => 'missing@example.test',
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('No agent found for missing@example.test.');
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
