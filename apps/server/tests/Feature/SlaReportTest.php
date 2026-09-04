<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\SlaClock;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Reporting\ReportingScope;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\SlaReport;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('SLA history counts scoped breaches and exposes current pressure separately', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-SLA-HISTORY',
        'priority' => 'high',
    ]);
    $ticket = Ticket::factory()->for($account)->for($site)->create(['priority' => 'urgent']);

    $conversation->slaClocks()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'metric' => SlaClock::METRIC_FIRST_RESPONSE,
        'priority' => 'high',
        'target_seconds' => 900,
        'warning_seconds' => 720,
        'elapsed_seconds' => 1_000,
        'started_at' => now()->subDays(2),
        'last_counted_at' => now()->subDay(),
        'warned_at' => now()->subDay(),
        'breached_at' => now()->subDay(),
    ]);
    $ticket->slaClocks()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'metric' => SlaClock::METRIC_RESOLUTION,
        'priority' => 'urgent',
        'target_seconds' => 1_800,
        'warning_seconds' => 1_440,
        'elapsed_seconds' => 2_000,
        'started_at' => now()->subDays(3),
        'last_counted_at' => now()->subDays(2),
        'warned_at' => now()->subDays(2),
        'breached_at' => now()->subDays(2),
        'satisfied_at' => now()->subDay(),
    ]);
    $conversation->slaClocks()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'metric' => SlaClock::METRIC_RESOLUTION,
        'priority' => 'high',
        'target_seconds' => 3_600,
        'warning_seconds' => 2_880,
        'elapsed_seconds' => 3_000,
        'started_at' => now()->subHour(),
        'last_counted_at' => now(),
        'warned_at' => now(),
    ]);
    $conversation->slaClocks()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'metric' => SlaClock::METRIC_FIRST_RESPONSE,
        'priority' => 'normal',
        'target_seconds' => 3_600,
        'warning_seconds' => 2_880,
        'elapsed_seconds' => 480,
        'started_at' => now()->subHour(),
        'last_counted_at' => now(),
        'warned_at' => now()->subMinutes(10),
    ]);

    $otherAccount = Account::factory()->create();
    $otherSite = Site::factory()->for($otherAccount)->create();
    $otherTicket = Ticket::factory()->for($otherAccount)->for($otherSite)->create();
    $otherTicket->slaClocks()->create([
        'account_id' => $otherAccount->id,
        'site_id' => $otherSite->id,
        'metric' => SlaClock::METRIC_RESOLUTION,
        'priority' => 'normal',
        'target_seconds' => 300,
        'warning_seconds' => 240,
        'elapsed_seconds' => 400,
        'started_at' => now()->subDay(),
        'last_counted_at' => now(),
        'warned_at' => now(),
        'breached_at' => now(),
    ]);

    $history = (new SlaReport(
        ReportingScope::for($account, $admin),
        ReportingWindow::ofDays(30),
    ))->history();

    expect($history)->toMatchArray([
        'breached' => 2,
        'first_response' => 1,
        'resolution' => 1,
        'conversations' => 1,
        'tickets' => 1,
        'active_warning' => 1,
        'active_breached' => 1,
    ])
        ->and($history['by_priority']['high'])->toBe(1)
        ->and($history['by_priority']['urgent'])->toBe(1)
        ->and(collect($history['recent'])->pluck('reference')->all())
        ->toContain('WF-SLA-HISTORY', 'Ticket #'.$ticket->id);
});

test('the speed report renders SLA breach history from visible work', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create([
        'priority' => 'urgent',
        'subject' => 'Checkout outage',
    ]);
    $ticket->slaClocks()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'metric' => SlaClock::METRIC_RESOLUTION,
        'priority' => 'urgent',
        'target_seconds' => 600,
        'warning_seconds' => 480,
        'elapsed_seconds' => 700,
        'started_at' => now()->subHour(),
        'last_counted_at' => now(),
        'warned_at' => now()->subMinutes(5),
        'breached_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('dashboard.reports.index'))
        ->assertOk()
        ->assertSee('SLA history')
        ->assertSee('1 breach in this period')
        ->assertSee('Ticket #'.$ticket->id)
        ->assertSee('Resolution')
        ->assertSee('Urgent');
});
