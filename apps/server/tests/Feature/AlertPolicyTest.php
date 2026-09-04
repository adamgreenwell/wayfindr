<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\SlaClock;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use App\Notifications\SlaDeadlineAlert;
use App\Notifications\TicketAssigned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

test('alert policy allows agents to view and mark read only their visible conversation alerts', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherAgent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
    ]);
    $agent->notify(new ConversationNeedsReply($message));
    $notification = $agent->unreadNotifications()->firstOrFail();

    expect(Gate::forUser($agent)->allows('view', $notification))->toBeTrue()
        ->and(Gate::forUser($agent)->allows('markRead', $notification))->toBeTrue()
        ->and(Gate::forUser($otherAgent)->allows('view', $notification))->toBeFalse()
        ->and(Gate::forUser($otherAgent)->allows('markRead', $notification))->toBeFalse();
});

test('alert policy denies stale conversation alerts after site access changes', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $remainingAgent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
    ]);
    $agent->notify(new ConversationNeedsReply($message));
    $notification = $agent->unreadNotifications()->firstOrFail();

    $site->supportAgents()->sync([$remainingAgent->id]);

    expect(Gate::forUser($agent)->allows('view', $notification))->toBeFalse()
        ->and(Gate::forUser($agent)->allows('markRead', $notification))->toBeFalse();
});

test('alert policy allows agents to view and mark read only their visible ticket alerts', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherAgent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create();
    $agent->notify(new TicketAssigned($ticket, $otherAgent));
    $notification = $agent->unreadNotifications()->firstOrFail();

    expect(Gate::forUser($agent)->allows('view', $notification))->toBeTrue()
        ->and(Gate::forUser($agent)->allows('markRead', $notification))->toBeTrue()
        ->and(Gate::forUser($otherAgent)->allows('view', $notification))->toBeFalse()
        ->and(Gate::forUser($otherAgent)->allows('markRead', $notification))->toBeFalse();
});

test('alert policy denies stale ticket alerts after site access changes', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $remainingAgent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create();
    $agent->notify(new TicketAssigned($ticket, $remainingAgent));
    $notification = $agent->unreadNotifications()->firstOrFail();

    $site->supportAgents()->sync([$remainingAgent->id]);

    expect(Gate::forUser($agent)->allows('view', $notification))->toBeFalse()
        ->and(Gate::forUser($agent)->allows('markRead', $notification))->toBeFalse();
});

test('alert policy rechecks current work access for SLA alerts', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create([
        'alert_preferences' => ['mode' => 'all', 'email' => false, 'cadence' => 'immediate'],
    ]);
    $remainingAgent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();
    $clock = $conversation->slaClocks()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'metric' => SlaClock::METRIC_FIRST_RESPONSE,
        'priority' => 'normal',
        'target_seconds' => 600,
        'warning_seconds' => 480,
        'elapsed_seconds' => 480,
        'started_at' => now()->subMinutes(8),
        'last_counted_at' => now(),
        'warned_at' => now(),
    ]);
    $agent->notify(new SlaDeadlineAlert($clock, 'warning'));
    $notification = $agent->unreadNotifications()->firstOrFail();

    expect(Gate::forUser($agent)->allows('view', $notification))->toBeTrue();

    $site->supportAgents()->sync([$remainingAgent->id]);

    expect(Gate::forUser($agent)->allows('view', $notification))->toBeFalse()
        ->and(Gate::forUser($agent)->allows('markRead', $notification))->toBeFalse();
});
