<?php

use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('cobrowse smoke command reports no-data readiness without failing', function (): void {
    $exitCode = Artisan::call('wayfindr:cobrowse-transport-smoke');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Wayfindr cobrowse transport smoke')
        ->toContain('Status: No data yet')
        ->toContain('Summary: No active cobrowse transport samples yet.')
        ->toContain('Next step: Run the widget smoke path with cobrowse consent before relying on cobrowse for real visitor support.');
});

test('cobrowse smoke command prints static payload budgets without leaking support data', function (): void {
    $this->travelTo(Carbon::parse('2026-06-20 12:00:00'));

    $site = Site::factory()->create(['name' => 'Sensitive Customer Site']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-cobrowse-budget',
    ]);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-BUDGETSECRET',
        'subject' => 'Private checkout failure',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'metadata' => [
            'snapshot' => [
                'reported_at' => now()->toJSON(),
                'title' => 'Private account dashboard',
                'page_url' => 'https://customer.example.test/private-account',
                'text' => 'Private account number 12345',
            ],
            'telemetry' => [
                'reported_at' => now()->toJSON(),
                'dropped_batches' => 1,
                'reconnects' => 0,
            ],
        ],
    ]);

    Artisan::call('wayfindr:cobrowse-transport-smoke');
    $output = Artisan::output();

    expect($output)->toContain('Cobrowse budget defaults')
        ->toContain('Server intake')
        ->toContain('Snapshot HTML: 65,535 characters')
        ->toContain('Server mutation batch: 50 items')
        ->toContain('Server telemetry payload: 10,485,760 bytes')
        ->toContain('Stock widget')
        ->toContain('Stock widget batch payload: 60,000 bytes')
        ->toContain('Stock widget queue: 250 pending')
        ->toContain('Status poll: 5,000 ms')
        ->toContain('Resync attempts: 3 attempts')
        ->not->toContain('WF-BUDGETSECRET')
        ->not->toContain('Private checkout failure')
        ->not->toContain('Sensitive Customer Site')
        ->not->toContain('anon-cobrowse-budget')
        ->not->toContain('Private account dashboard')
        ->not->toContain('customer.example.test')
        ->not->toContain('Private account number');
});

test('cobrowse smoke command fails on active transport attention without leaking support data', function (): void {
    $this->travelTo(Carbon::parse('2026-06-20 12:00:00'));

    $site = Site::factory()->create(['name' => 'Sensitive Customer Site']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-cobrowse-smoke',
    ]);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-SMOKESECRET',
        'subject' => 'Private checkout failure',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'metadata' => [
            'telemetry' => [
                'reported_at' => now()->toJSON(),
                'dropped_batches' => 2,
                'reconnects' => 0,
            ],
            'snapshot' => [
                'reported_at' => now()->toJSON(),
                'title' => 'Private account dashboard',
                'page_url' => 'https://customer.example.test/private-account',
                'text' => 'Private account number 12345',
            ],
        ],
    ]);

    $exitCode = Artisan::call('wayfindr:cobrowse-transport-smoke');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Wayfindr cobrowse transport smoke')
        ->toContain('Status: Needs attention')
        ->toContain('Summary: 1 active cobrowse session needs transport attention.')
        ->toContain('Aggregate signals: 1 degraded.')
        ->toContain('Next step: Use chat to confirm fast-changing page state')
        ->not->toContain('WF-SMOKESECRET')
        ->not->toContain('Private checkout failure')
        ->not->toContain('Sensitive Customer Site')
        ->not->toContain('anon-cobrowse-smoke')
        ->not->toContain('Private account dashboard')
        ->not->toContain('customer.example.test')
        ->not->toContain('Private account number');
});

test('cobrowse smoke command treats pending first transport reports as manual but successful', function (): void {
    $site = Site::factory()->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'metadata' => [],
    ]);

    $exitCode = Artisan::call('wayfindr:cobrowse-transport-smoke');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Status: Manual check')
        ->toContain('1 active cobrowse session is waiting for transport reports.')
        ->toContain('confirm the widget can reach the cobrowse telemetry endpoints');
});

test('cobrowse smoke command reports a manual check when cobrowse sessions cannot be inspected', function (): void {
    Schema::shouldReceive('hasTable')
        ->with('cobrowse_sessions')
        ->once()
        ->andThrow(new RuntimeException('database unavailable'));

    $exitCode = Artisan::call('wayfindr:cobrowse-transport-smoke');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Status: Manual check')
        ->toContain('Cobrowse transport readiness could not inspect active sessions.')
        ->toContain('Check database connectivity and run migrations');
});

test('cobrowse smoke command reports a manual check when cobrowse sessions table is missing', function (): void {
    Schema::shouldReceive('hasTable')
        ->with('cobrowse_sessions')
        ->once()
        ->andReturn(false);

    $exitCode = Artisan::call('wayfindr:cobrowse-transport-smoke');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Status: Manual check')
        ->toContain('Cobrowse transport readiness could not inspect active sessions.')
        ->toContain('Check database connectivity and run migrations');
});
