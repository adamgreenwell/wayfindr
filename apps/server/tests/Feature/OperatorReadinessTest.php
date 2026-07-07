<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\OperatorReadinessConfirmation;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\OperatorReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('account owner can inspect operator readiness diagnostics', function (): void {
    config([
        'app.url' => 'https://wayfindr.example.test',
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => 'wayfindr-production',
        'broadcasting.connections.reverb.key' => 'wayfindr-key',
        'broadcasting.connections.reverb.secret' => 'wayfindr-secret',
        'broadcasting.connections.reverb.options.host' => 'wayfindr.example.test',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@wayfindr.example.test',
        'queue.default' => 'database',
    ]);

    $agent = User::factory()->for(Account::factory(['name' => 'Acme Support']))->create([
        'account_role' => AccountRole::Owner,
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/readiness')
        ->assertOk()
        ->assertSee('Operator readiness')
        ->assertSee('Application key')
        ->assertSee('Database connection')
        ->assertSee('Public URL')
        ->assertSee('Mail transport')
        ->assertSee('Queue worker')
        ->assertSee('Realtime broadcasting')
        ->assertSee('Storage paths')
        ->assertSee('Scheduler')
        ->assertSee('Backups and restore')
        ->assertSee('Ready')
        ->assertSee('Dogfood readiness')
        ->assertSee('Controlled MVP gates for demo and staging use.')
        ->assertSee('Full support-loop smoke')
        ->assertSee('Data responsibility review')
        ->assertSee('Retention posture')
        ->assertSee('Cobrowse page content is pruned automatically; broader retention stays operator-owned.')
        ->assertSee('Application records')
        ->assertSee('Automatic deletion')
        ->assertSee('php artisan wayfindr:mail-test --to=you@example.com')
        ->assertSee('php artisan queue:failed')
        ->assertSee('php artisan queue:work')
        ->assertSee('php artisan schedule:run')
        ->assertSee('php artisan reverb:start --host=127.0.0.1 --port=8080')
        ->assertSee('php artisan reverb:restart')
        ->assertSee('Post-install smoke path')
        ->assertSee('Recommended next step')
        ->assertSee('data-copy-value="php artisan wayfindr:mail-test --to=you@example.com"', false)
        ->assertSee('data-copy-value="php artisan queue:failed"', false)
        ->assertSee('data-copy-value="php artisan reverb:start --host=127.0.0.1 --port=8080"', false)
        ->assertSee('data-copy-value="php artisan wayfindr:cobrowse-transport-smoke"', false)
        ->assertDontSee('data-copy-value="php artisan wayfindr:send-alert-digests"', false)
        ->assertSee('Confirm background workers')
        ->assertSee('Open the public app URL')
        ->assertSee('Send a widget smoke test')
        ->assertSee('Run cobrowse transport smoke')
        ->assertSee('php artisan wayfindr:cobrowse-transport-smoke')
        ->assertSee('Confirm backups can restore');
});

test('plain agents cannot inspect operator readiness diagnostics', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'account_role' => AccountRole::Agent,
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/readiness')
        ->assertForbidden();
});

test('readiness diagnostics flag missing app key and incomplete realtime setup', function (): void {
    config([
        'app.key' => null,
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => null,
        'broadcasting.connections.reverb.key' => null,
        'broadcasting.connections.reverb.secret' => null,
        'broadcasting.connections.reverb.options.host' => null,
        'broadcasting.connections.reverb.options.port' => null,
        'broadcasting.connections.reverb.options.scheme' => null,
    ]);

    $readiness = app(OperatorReadiness::class)->summary();
    $applicationKey = collect($readiness['checks'])->firstWhere('key', 'application_key');
    $realtime = collect($readiness['checks'])->firstWhere('key', 'realtime_broadcasting');

    expect($applicationKey)->toMatchArray([
        'label' => 'Application key',
        'status' => 'attention',
        'status_label' => 'Needs attention',
        'action' => 'Run php artisan key:generate and save the generated APP_KEY in the environment.',
    ])->and($realtime)->toMatchArray([
        'label' => 'Realtime broadcasting',
        'status' => 'attention',
        'detail' => 'Add Reverb app credentials and public host settings before enabling live updates.',
    ]);
});

test('readiness diagnostics recommend the first attention item as the next step', function (): void {
    config([
        'app.key' => null,
        'app.url' => 'https://support.example.test',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
        'queue.default' => 'database',
    ]);

    $readiness = app(OperatorReadiness::class)->summary();

    expect($readiness['next_step'])->toMatchArray([
        'key' => 'application_key',
        'label' => 'Fix Application key',
        'status' => 'attention',
        'status_label' => 'Needs attention',
        'summary' => 'APP_KEY is missing.',
        'action' => 'Run php artisan key:generate and save the generated APP_KEY in the environment.',
    ]);
});

test('readiness diagnostics recommend manual smoke confirmation when no attention items remain', function (): void {
    config([
        'app.url' => 'https://support.example.test',
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => 'wayfindr-production',
        'broadcasting.connections.reverb.key' => 'wayfindr-key',
        'broadcasting.connections.reverb.secret' => 'wayfindr-secret',
        'broadcasting.connections.reverb.options.host' => 'support.example.test',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
        'queue.default' => 'database',
    ]);

    $readiness = app(OperatorReadiness::class)->summary();

    expect($readiness['next_step'])->toMatchArray([
        'key' => 'background_processes',
        'confirmation_key' => 'scheduler',
        'confirmable' => true,
        'label' => 'Confirm background workers',
        'status' => 'manual',
        'status_label' => 'Manual check',
        'summary' => 'Queues and the scheduler need process-manager coverage outside the request lifecycle.',
        'action' => 'Confirm php artisan queue:work is managed by Forge, Supervisor, systemd, or your host; run php artisan queue:failed to inspect failures; verify * * * * * cd /path/to/apps/server && php artisan schedule:run is configured once per minute; and confirm php artisan wayfindr:send-alert-digests appears in php artisan schedule:list.',
    ]);
});

test('readiness scheduler guidance names alert digest delivery', function (): void {
    config([
        'app.url' => 'https://support.example.test',
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => 'wayfindr-production',
        'broadcasting.connections.reverb.key' => 'wayfindr-key',
        'broadcasting.connections.reverb.secret' => 'wayfindr-secret',
        'broadcasting.connections.reverb.options.host' => 'support.example.test',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
        'queue.default' => 'database',
    ]);

    $readiness = app(OperatorReadiness::class)->summary();
    $scheduler = collect($readiness['checks'])->firstWhere('key', 'scheduler');
    $backgroundProcesses = collect($readiness['smoke_path'])->firstWhere('key', 'background_processes');

    expect($scheduler)->toMatchArray([
        'label' => 'Scheduler',
        'status' => 'manual',
    ])
        ->and($scheduler['action'])->toContain('php artisan schedule:run')
        ->and($scheduler['action'])->toContain('php artisan wayfindr:send-alert-digests')
        ->and($backgroundProcesses['action'])->toContain('php artisan wayfindr:send-alert-digests');
});

test('readiness diagnostics flag failed digest delivery without leaking raw provider errors', function (): void {
    config([
        'app.url' => 'https://support.example.test',
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => 'wayfindr-production',
        'broadcasting.connections.reverb.key' => 'wayfindr-key',
        'broadcasting.connections.reverb.secret' => 'wayfindr-secret',
        'broadcasting.connections.reverb.options.host' => 'support.example.test',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
        'queue.default' => 'database',
    ]);

    $account = Account::factory()->create(['name' => 'Acme Support']);

    User::factory()->for($account)->create([
        'name' => 'Faye Failed',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_DIGEST,
            'digest_delivery' => [
                'status' => User::ALERT_DIGEST_DELIVERY_FAILED,
                'candidate_count' => 2,
                'message' => 'Digest email could not be queued.',
                'error' => 'SMTP provider secret stack trace should not render',
                'last_attempted_at' => now()->subMinutes(9)->toISOString(),
            ],
        ],
    ]);

    User::factory()->for($account)->create([
        'name' => 'Quinn Queued',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_DIGEST,
            'digest_delivery' => [
                'status' => User::ALERT_DIGEST_DELIVERY_QUEUED,
                'candidate_count' => 1,
                'message' => User::digestQueuedMessage(1),
                'last_attempted_at' => now()->subMinutes(5)->toISOString(),
            ],
        ],
    ]);

    User::factory()->for($account)->create([
        'name' => 'Darla Dormant',
        'deactivated_at' => now(),
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_DIGEST,
            'digest_delivery' => [
                'status' => User::ALERT_DIGEST_DELIVERY_FAILED,
                'error' => 'Dormant agent failure should not render',
            ],
        ],
    ]);

    $readiness = app(OperatorReadiness::class)->summary();
    $digestDelivery = collect($readiness['checks'])->firstWhere('key', 'alert_digest_delivery');

    expect($digestDelivery)->toMatchArray([
        'label' => 'Alert digest delivery',
        'status' => 'attention',
        'status_label' => 'Needs attention',
        'summary' => '1 digest-enabled agent needs delivery attention.',
    ])
        ->and($digestDelivery['detail'])->toContain('Faye Failed')
        ->and($digestDelivery['action'])->toContain('php artisan wayfindr:send-alert-digests')
        ->and($digestDelivery['detail'])->not->toContain('SMTP provider secret stack trace should not render')
        ->and($digestDelivery['detail'])->not->toContain('Dormant agent failure should not render')
        ->and($readiness['next_step'])->toMatchArray([
            'key' => 'alert_digest_delivery',
            'label' => 'Fix Alert digest delivery',
            'status' => 'attention',
        ]);
});

test('readiness diagnostics summarize cobrowse transport pressure without leaking support data', function (): void {
    $this->travelTo(Carbon::parse('2026-06-20 12:00:00'));

    config([
        'app.url' => 'https://support.example.test',
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => 'wayfindr-production',
        'broadcasting.connections.reverb.key' => 'wayfindr-key',
        'broadcasting.connections.reverb.secret' => 'wayfindr-secret',
        'broadcasting.connections.reverb.options.host' => 'support.example.test',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
        'queue.default' => 'database',
    ]);

    $site = Site::factory()->create(['name' => 'Sensitive Support Site']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-sensitive-cobrowse',
    ]);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-SECRET',
        'subject' => 'Billing details are visible on the checkout page',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'metadata' => [
            'telemetry' => [
                'reported_at' => now()->toJSON(),
                'rtt_ms' => 950,
                'max_rtt_ms' => 1250,
                'payload_bytes' => 59000,
                'max_payload_bytes' => 60000,
                'dropped_batches' => 3,
                'reconnects' => 1,
            ],
            'snapshot' => [
                'reported_at' => now()->toJSON(),
                'title' => 'Private billing checkout',
                'page_url' => 'https://customer.example.test/private-billing',
                'html' => '<main>Private invoice contents</main>',
                'text' => 'Private invoice contents',
            ],
            'mutations' => [
                'last_reported_at' => now()->toJSON(),
                'dropped_count' => 4,
                'skipped_count' => 5,
            ],
        ],
    ]);

    $readiness = app(OperatorReadiness::class)->summary();
    $cobrowseTransport = collect($readiness['checks'])->firstWhere('key', 'cobrowse_transport');

    expect($cobrowseTransport)->toMatchArray([
        'label' => 'Cobrowse transport',
        'status' => 'attention',
        'status_label' => 'Needs attention',
        'summary' => '1 active cobrowse session needs transport attention.',
    ])
        ->and($cobrowseTransport['detail'])->toContain('1 reconnecting')
        ->and($cobrowseTransport['action'])->toContain('Use chat to confirm fast-changing page state')
        ->and($cobrowseTransport['detail'])->not->toContain('WF-SECRET')
        ->and($cobrowseTransport['detail'])->not->toContain('Billing details are visible')
        ->and($cobrowseTransport['detail'])->not->toContain('anon-sensitive-cobrowse')
        ->and($cobrowseTransport['detail'])->not->toContain('Private billing checkout')
        ->and($cobrowseTransport['detail'])->not->toContain('customer.example.test')
        ->and($readiness['next_step'])->toMatchArray([
            'key' => 'cobrowse_transport',
            'label' => 'Fix Cobrowse transport',
            'status' => 'attention',
        ]);
});

test('readiness diagnostics show no-data cobrowse transport as ready before traffic exists', function (): void {
    $readiness = app(OperatorReadiness::class)->summary();
    $cobrowseTransport = collect($readiness['checks'])->firstWhere('key', 'cobrowse_transport');

    expect($cobrowseTransport)->toMatchArray([
        'label' => 'Cobrowse transport',
        'status' => 'ready',
        'status_label' => 'No data yet',
        'summary' => 'No active cobrowse transport samples yet.',
        'detail' => 'Cobrowse health will appear after a visitor grants consent and the widget reports telemetry.',
    ]);
});

test('dashboard readiness shows cobrowse budget defaults without support data', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Owner,
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Sensitive Budget Site']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-budget-readiness',
    ]);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-READYSECRET',
        'subject' => 'Private checkout snapshot should stay hidden',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'metadata' => [
            'snapshot' => [
                'title' => 'Private checkout',
                'page_url' => 'https://customer.example.test/private-checkout',
                'html' => '<main>Private checkout fields</main>',
                'text' => 'Private checkout fields',
            ],
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/readiness')
        ->assertOk()
        ->assertSee('Cobrowse budget defaults')
        ->assertSee('Safe default limits for stock widget payloads and server intake.')
        ->assertSee('Snapshot HTML')
        ->assertSee('65,535 characters')
        ->assertSee('Server mutation batch')
        ->assertSee('50 items')
        ->assertSee('Server telemetry payload')
        ->assertSee('10,485,760 bytes')
        ->assertSee('Stock widget batch payload')
        ->assertSee('60,000 bytes')
        ->assertSee('Stock widget queue')
        ->assertSee('250 pending')
        ->assertSee('Mutation flush')
        ->assertSee('50 ms')
        ->assertSee('Pressure resync')
        ->assertSee('30,000 ms')
        ->assertSee('Status poll')
        ->assertSee('5,000 ms')
        ->assertSee('Resync attempts')
        ->assertSee('3 attempts')
        ->assertDontSee('WF-READYSECRET')
        ->assertDontSee('Private checkout snapshot should stay hidden')
        ->assertDontSee('Sensitive Budget Site')
        ->assertDontSee('anon-budget-readiness')
        ->assertDontSee('Private checkout')
        ->assertDontSee('customer.example.test')
        ->assertDontSee('Private checkout fields');
});

test('readiness diagnostics include older active cobrowse transport pressure beyond the newest sessions', function (): void {
    $this->travelTo(Carbon::parse('2026-06-20 12:00:00'));

    $site = Site::factory()->create();
    $visitor = Visitor::factory()->for($site)->create();

    $degradedConversation = Conversation::factory()->for($site)->for($visitor)->create();
    CobrowseSession::factory()->for($degradedConversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinutes(5),
        'metadata' => [
            'telemetry' => [
                'reported_at' => now()->toJSON(),
                'dropped_batches' => 1,
                'reconnects' => 0,
            ],
        ],
    ]);

    for ($index = 0; $index < 50; $index++) {
        $conversation = Conversation::factory()->for($site)->for($visitor)->create();

        CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinute(),
            'metadata' => [
                'telemetry' => [
                    'reported_at' => now()->toJSON(),
                    'dropped_batches' => 0,
                    'reconnects' => 0,
                ],
            ],
        ]);
    }

    $readiness = app(OperatorReadiness::class)->summary();
    $cobrowseTransport = collect($readiness['checks'])->firstWhere('key', 'cobrowse_transport');

    expect($cobrowseTransport)->toMatchArray([
        'label' => 'Cobrowse transport',
        'status' => 'attention',
        'status_label' => 'Needs attention',
        'summary' => '1 active cobrowse session needs transport attention.',
    ])
        ->and($cobrowseTransport['detail'])->toContain('50 live')
        ->and($cobrowseTransport['detail'])->toContain('1 degraded');
});

test('readiness diagnostics treat confirmed manual items as ready', function (): void {
    config([
        'app.url' => 'https://support.example.test',
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => 'wayfindr-production',
        'broadcasting.connections.reverb.key' => 'wayfindr-key',
        'broadcasting.connections.reverb.secret' => 'wayfindr-secret',
        'broadcasting.connections.reverb.options.host' => 'support.example.test',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
        'queue.default' => 'database',
    ]);

    $operator = User::factory()->create(['name' => 'Olive Operator']);

    OperatorReadinessConfirmation::query()->create([
        'key' => 'scheduler',
        'confirmed_by_id' => $operator->id,
        'confirmed_at' => now()->subMinute(),
        'note' => 'Forge scheduler is running every minute.',
    ]);

    OperatorReadinessConfirmation::query()->create([
        'key' => 'backups_restore',
        'confirmed_by_id' => $operator->id,
        'confirmed_at' => now(),
        'note' => 'Database restore was tested.',
    ]);

    $readiness = app(OperatorReadiness::class)->summary();
    $scheduler = collect($readiness['checks'])->firstWhere('key', 'scheduler');
    $backups = collect($readiness['checks'])->firstWhere('key', 'backups_restore');
    $backgroundProcesses = collect($readiness['smoke_path'])->firstWhere('key', 'background_processes');
    $backupRestore = collect($readiness['smoke_path'])->firstWhere('key', 'backup_restore');

    expect($readiness)
        ->attention_count->toBe(0)
        ->manual_count->toBe(0)
        ->and($scheduler)->toMatchArray([
            'status' => 'ready',
            'status_label' => 'Ready',
            'summary' => 'Scheduler confirmed.',
        ])
        ->and($scheduler['confirmation'])->toMatchArray([
            'key' => 'scheduler',
            'confirmed_by' => 'Olive Operator',
            'freshness_status' => 'fresh',
            'note_present' => true,
            'stale_after_days' => 7,
        ])
        ->and($scheduler['confirmation']['age_label'])->not->toBeNull()
        ->and($scheduler['confirmation']['confirmed_at'])->not->toBeNull()
        ->and($backups)->toMatchArray([
            'status' => 'ready',
            'summary' => 'Backups and restore confirmed.',
        ])
        ->and($backgroundProcesses)->toMatchArray([
            'status' => 'ready',
            'confirmation_key' => 'scheduler',
        ])
        ->and($backupRestore)->toMatchArray([
            'status' => 'ready',
            'confirmation_key' => 'backups_restore',
        ])
        ->and($readiness['next_step'])->toMatchArray([
            'key' => 'ready_for_traffic',
            'status' => 'ready',
            'label' => 'Ready for traffic',
        ]);
});

test('readiness diagnostics mark stale confirmations as refresh due', function (): void {
    $this->travelTo(Carbon::parse('2026-06-06 12:00:00'));

    config([
        'app.url' => 'https://support.example.test',
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => 'wayfindr-production',
        'broadcasting.connections.reverb.key' => 'wayfindr-key',
        'broadcasting.connections.reverb.secret' => 'wayfindr-secret',
        'broadcasting.connections.reverb.options.host' => 'support.example.test',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
        'queue.default' => 'database',
    ]);

    $operator = User::factory()->create(['name' => 'Olive Operator']);

    OperatorReadinessConfirmation::query()->create([
        'key' => 'scheduler',
        'confirmed_by_id' => $operator->id,
        'confirmed_at' => now()->subDays(8),
        'note' => 'Forge scheduler was checked last week.',
    ]);

    OperatorReadinessConfirmation::query()->create([
        'key' => 'backups_restore',
        'confirmed_by_id' => $operator->id,
        'confirmed_at' => now()->subDays(31),
        'note' => 'Restore drill was last month.',
    ]);

    $readiness = app(OperatorReadiness::class)->summary();
    $scheduler = collect($readiness['checks'])->firstWhere('key', 'scheduler');
    $backups = collect($readiness['checks'])->firstWhere('key', 'backups_restore');
    $backgroundProcesses = collect($readiness['smoke_path'])->firstWhere('key', 'background_processes');
    $backupRestore = collect($readiness['smoke_path'])->firstWhere('key', 'backup_restore');

    expect($readiness)
        ->attention_count->toBe(0)
        ->manual_count->toBe(2)
        ->and($scheduler)->toMatchArray([
            'status' => 'manual',
            'status_label' => 'Refresh due',
            'summary' => 'Scheduler confirmation needs refresh.',
        ])
        ->and($scheduler['confirmation'])->toMatchArray([
            'age_label' => '8 days ago',
            'confirmed_by' => 'Olive Operator',
            'freshness_status' => 'stale',
            'key' => 'scheduler',
            'note_present' => true,
            'stale_after_days' => 7,
        ])
        ->and($backups)->toMatchArray([
            'status' => 'manual',
            'status_label' => 'Refresh due',
            'summary' => 'Backups and restore confirmation needs refresh.',
        ])
        ->and($backups['confirmation'])->toMatchArray([
            'age_label' => '1 month ago',
            'freshness_status' => 'stale',
            'stale_after_days' => 30,
        ])
        ->and($backgroundProcesses)->toMatchArray([
            'status' => 'manual',
            'status_label' => 'Refresh due',
            'confirmation_key' => 'scheduler',
        ])
        ->and($backupRestore)->toMatchArray([
            'status' => 'manual',
            'status_label' => 'Refresh due',
            'confirmation_key' => 'backups_restore',
        ])
        ->and($readiness['next_step'])->toMatchArray([
            'key' => 'background_processes',
            'status' => 'manual',
            'status_label' => 'Refresh due',
        ]);
});

test('dashboard readiness shows stale confirmation refresh guidance', function (): void {
    $this->travelTo(Carbon::parse('2026-06-06 12:00:00'));

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Adam Admin',
    ]);

    OperatorReadinessConfirmation::query()->create([
        'key' => 'scheduler',
        'confirmed_by_id' => $agent->id,
        'confirmed_at' => now()->subDays(8),
        'note' => 'Scheduler was checked before a deploy.',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/readiness')
        ->assertOk()
        ->assertSee('Scheduler confirmation needs refresh.')
        ->assertSee('Confirmed by Adam Admin 8 days ago.')
        ->assertSee('Evidence note recorded.')
        ->assertDontSee('Scheduler was checked before a deploy.')
        ->assertSee('Refresh confirmation');
});

test('readiness diagnostics skip confirmation lookup when the confirmation table is unavailable', function (): void {
    config([
        'app.url' => 'https://support.example.test',
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => 'wayfindr-production',
        'broadcasting.connections.reverb.key' => 'wayfindr-key',
        'broadcasting.connections.reverb.secret' => 'wayfindr-secret',
        'broadcasting.connections.reverb.options.host' => 'support.example.test',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
        'queue.default' => 'database',
    ]);

    Schema::drop('operator_readiness_confirmations');

    $readiness = app(OperatorReadiness::class)->summary();
    $database = collect($readiness['checks'])->firstWhere('key', 'database_connection');
    $scheduler = collect($readiness['checks'])->firstWhere('key', 'scheduler');

    expect($database)->toMatchArray([
        'status' => 'ready',
        'summary' => 'The sqlite connection responded.',
    ])->and($scheduler)->toMatchArray([
        'status' => 'manual',
        'confirmation' => null,
    ]);
});

test('account admins can confirm a manual readiness item', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Adam Admin',
    ]);

    $this->actingAs($agent)
        ->post('/dashboard/readiness/confirmations', [
            'key' => 'scheduler',
            'note' => 'Forge scheduled job is configured.',
        ])
        ->assertRedirect('/dashboard/readiness');

    $this->assertDatabaseHas('operator_readiness_confirmations', [
        'key' => 'scheduler',
        'confirmed_by_id' => $agent->id,
        'note' => 'Forge scheduled job is configured.',
    ]);

    $auditEvent = AuditEvent::query()
        ->where('action', 'operator_readiness.confirmed')
        ->firstOrFail();

    expect($auditEvent->account_id)
        ->toBe($account->id)
        ->and($auditEvent->actor_id)->toBe($agent->id)
        ->and($auditEvent->metadata)->toMatchArray([
            'key' => 'scheduler',
            'note' => 'Forge scheduled job is configured.',
        ]);

    $this->actingAs($agent)
        ->get('/dashboard/readiness')
        ->assertOk()
        ->assertSee('Scheduler confirmed.')
        ->assertSee('Confirmed by Adam Admin')
        ->assertSee('Evidence note recorded.')
        ->assertDontSee('Forge scheduled job is configured.');
});

test('blank readiness refresh preserves the existing evidence note', function (): void {
    $this->travelTo(Carbon::parse('2026-06-22 12:00:00'));

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Adam Admin',
    ]);

    OperatorReadinessConfirmation::query()->create([
        'key' => 'scheduler',
        'confirmed_by_id' => $agent->id,
        'confirmed_at' => now()->subDays(8),
        'note' => 'Existing scheduler proof should stay recorded.',
    ]);

    $this->travelTo(Carbon::parse('2026-06-22 12:05:00'));

    $this->actingAs($agent)
        ->post('/dashboard/readiness/confirmations', [
            'key' => 'scheduler',
            'note' => '   ',
        ])
        ->assertRedirect('/dashboard/readiness');

    $this->assertDatabaseHas('operator_readiness_confirmations', [
        'key' => 'scheduler',
        'confirmed_by_id' => $agent->id,
        'note' => 'Existing scheduler proof should stay recorded.',
    ]);

    $confirmation = OperatorReadinessConfirmation::query()
        ->where('key', 'scheduler')
        ->firstOrFail();

    expect($confirmation->confirmed_at?->toIso8601String())->toBe('2026-06-22T12:05:00+00:00');

    $auditEvent = AuditEvent::query()
        ->where('action', 'operator_readiness.confirmed')
        ->firstOrFail();

    expect($auditEvent->metadata)->toMatchArray([
        'key' => 'scheduler',
        'note' => 'Existing scheduler proof should stay recorded.',
    ]);
});

test('plain agents cannot confirm readiness items', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'account_role' => AccountRole::Agent,
    ]);

    $this->actingAs($agent)
        ->post('/dashboard/readiness/confirmations', [
            'key' => 'scheduler',
            'note' => 'Nope.',
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('operator_readiness_confirmations', [
        'key' => 'scheduler',
    ]);
});

test('readiness diagnostics flag local public urls and local-only mail transport', function (): void {
    config([
        'app.url' => 'http://localhost:8000',
        'mail.default' => 'log',
        'mail.from.address' => 'hello@wayfindr.local',
    ]);

    $readiness = app(OperatorReadiness::class)->summary();
    $publicUrl = collect($readiness['checks'])->firstWhere('key', 'public_url');
    $mail = collect($readiness['checks'])->firstWhere('key', 'mail_transport');
    $backups = collect($readiness['checks'])->firstWhere('key', 'backups_restore');

    expect($publicUrl)->toMatchArray([
        'label' => 'Public URL',
        'status' => 'attention',
        'summary' => 'APP_URL is local or not secure.',
        'action' => 'Set APP_URL to the public HTTPS URL visitors and agents will use.',
    ])->and($mail)->toMatchArray([
        'label' => 'Mail transport',
        'status' => 'attention',
        'summary' => 'MAIL_MAILER is log.',
        'action' => 'Configure smtp, ses, postmark, resend, or another real outbound mail transport before relying on email alerts.',
    ])->and($backups)->toMatchArray([
        'label' => 'Backups and restore',
        'status' => 'manual',
        'action' => 'Confirm database and storage backups are scheduled, retained, monitored, and restorable before real support traffic arrives.',
    ]);
});

test('readiness diagnostics accept a public https app url and outbound mail transport', function (): void {
    config([
        'app.url' => 'https://support.example.test',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
    ]);

    $readiness = app(OperatorReadiness::class)->summary();
    $publicUrl = collect($readiness['checks'])->firstWhere('key', 'public_url');
    $mail = collect($readiness['checks'])->firstWhere('key', 'mail_transport');

    expect($publicUrl)->toMatchArray([
        'label' => 'Public URL',
        'status' => 'ready',
        'summary' => 'APP_URL is https://support.example.test.',
    ])->and($mail)->toMatchArray([
        'label' => 'Mail transport',
        'status' => 'ready',
        'summary' => 'MAIL_MAILER is smtp.',
        'action' => 'Run php artisan wayfindr:mail-test --to=you@example.com from apps/server after deploy. For STARTTLS ports such as 587 or 2587, leave MAIL_SCHEME unset; use smtps only for port 465.',
        'commands' => ['php artisan wayfindr:mail-test --to=you@example.com'],
    ]);
});

test('readiness security posture flags debug mode enabled in production', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['app.debug' => true]);

    $readiness = app(OperatorReadiness::class)->summary();
    $security = collect($readiness['checks'])->firstWhere('key', 'security_posture');

    expect($security)->toMatchArray([
        'label' => 'Security posture',
        'status' => 'attention',
        'summary' => 'Debug mode is enabled in production.',
        'commands' => ['php artisan config:cache'],
    ])
        ->and($security['detail'])->toContain('APP_DEBUG=true')
        ->and(collect($readiness['checks'])->where('status', 'attention')->pluck('key'))->toContain('security_posture');
});

test('readiness security posture is ready when debug is disabled in production', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['app.debug' => false]);

    $readiness = app(OperatorReadiness::class)->summary();
    $security = collect($readiness['checks'])->firstWhere('key', 'security_posture');

    expect($security)->toMatchArray([
        'label' => 'Security posture',
        'status' => 'ready',
        'summary' => 'Debug mode is disabled.',
    ]);
});

test('readiness security posture stays ready when debug is on outside production', function (): void {
    // Debug mode is expected in non-production environments; do not nag operators there.
    config(['app.debug' => true]);

    $readiness = app(OperatorReadiness::class)->summary();
    $security = collect($readiness['checks'])->firstWhere('key', 'security_posture');

    expect($security['status'])->toBe('ready')
        ->and($security['summary'])->toBe('Debug mode is on in the testing environment.')
        ->and(collect($readiness['checks'])->where('status', 'attention')->pluck('key'))->not->toContain('security_posture');
});

test('readiness cache store check round-trips a working store', function (): void {
    config(['cache.default' => 'file']);

    $readiness = app(OperatorReadiness::class)->summary();
    $cache = collect($readiness['checks'])->firstWhere('key', 'cache_store');

    expect($cache)->toMatchArray([
        'label' => 'Cache store',
        'status' => 'ready',
    ])->and($cache['summary'])->toContain('responded');
});

test('readiness flags a non-persistent cache store in production', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['cache.default' => 'array']);

    $readiness = app(OperatorReadiness::class)->summary();
    $cache = collect($readiness['checks'])->firstWhere('key', 'cache_store');

    expect($cache)->toMatchArray([
        'label' => 'Cache store',
        'status' => 'attention',
        'summary' => 'CACHE_STORE is array in production.',
    ])
        ->and(collect($readiness['checks'])->where('status', 'attention')->pluck('key'))->toContain('cache_store');
});

test('readiness flags a cache store that cannot be reached', function (): void {
    config(['cache.default' => 'redis']);
    Cache::shouldReceive('store')->with('redis')->andThrow(new RuntimeException('Connection refused'));

    $readiness = app(OperatorReadiness::class)->summary();
    $cache = collect($readiness['checks'])->firstWhere('key', 'cache_store');

    expect($cache['status'])->toBe('attention')
        ->and($cache['summary'])->toContain('redis cache store could not be verified');
});

test('readiness flags a failed persistent store inside a cache failover chain', function (): void {
    // The repo's failover store chains [database, array]. A round-trip would
    // otherwise pass through the array fallback and hide a broken primary, so the
    // persistent member (database) must be probed directly.
    config(['cache.default' => 'failover']);
    Cache::shouldReceive('store')->with('database')->andThrow(new RuntimeException('no such table: cache'));

    $readiness = app(OperatorReadiness::class)->summary();
    $cache = collect($readiness['checks'])->firstWhere('key', 'cache_store');

    expect($cache['status'])->toBe('attention')
        ->and($cache['summary'])->toContain('database')
        ->and($cache['summary'])->toContain('failover');
});

test('readiness diagnostics flag smtp mail that still points at local defaults', function (): void {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 2525,
        'mail.from.address' => 'hello@example.com',
    ]);

    $readiness = app(OperatorReadiness::class)->summary();
    $mail = collect($readiness['checks'])->firstWhere('key', 'mail_transport');

    expect($mail)->toMatchArray([
        'label' => 'Mail transport',
        'status' => 'attention',
        'summary' => 'SMTP is still pointed at a local mail host.',
        'action' => 'Set MAIL_HOST, MAIL_PORT, and MAIL_FROM_ADDRESS to a real outbound mail provider before relying on email alerts.',
    ]);
});

test('readiness diagnostics flag unsupported smtp schemes', function (): void {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.mailers.smtp.scheme' => 'tls',
        'mail.from.address' => 'support@example.test',
    ]);

    $readiness = app(OperatorReadiness::class)->summary();
    $mail = collect($readiness['checks'])->firstWhere('key', 'mail_transport');

    expect($mail)->toMatchArray([
        'label' => 'Mail transport',
        'status' => 'attention',
        'summary' => 'SMTP has an unsupported MAIL_SCHEME value.',
        'action' => 'Unset MAIL_SCHEME for port 587 STARTTLS SMTP, or set it to smtps when using port 465.',
    ]);
});

test('readiness diagnostics include a guided post install smoke path', function (): void {
    config([
        'app.url' => 'https://support.example.test',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
        'queue.default' => 'database',
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => 'wayfindr-production',
        'broadcasting.connections.reverb.key' => 'wayfindr-key',
        'broadcasting.connections.reverb.secret' => 'wayfindr-secret',
        'broadcasting.connections.reverb.options.host' => 'support.example.test',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
    ]);

    $readiness = app(OperatorReadiness::class)->summary();

    expect($readiness['smoke_path'])->sequence(
        fn ($step) => $step->toMatchArray([
            'key' => 'public_endpoint',
            'label' => 'Open the public app URL',
            'status' => 'ready',
        ]),
        fn ($step) => $step->toMatchArray([
            'key' => 'outbound_mail',
            'label' => 'Send a real email',
            'status' => 'ready',
            'action' => 'Run php artisan wayfindr:mail-test --to=you@example.com from apps/server, then confirm the message lands in a real inbox.',
            'commands' => ['php artisan wayfindr:mail-test --to=you@example.com'],
        ]),
        fn ($step) => $step->toMatchArray([
            'key' => 'background_processes',
            'label' => 'Confirm background workers',
            'status' => 'manual',
            'action' => 'Confirm php artisan queue:work is managed by Forge, Supervisor, systemd, or your host; run php artisan queue:failed to inspect failures; verify * * * * * cd /path/to/apps/server && php artisan schedule:run is configured once per minute; and confirm php artisan wayfindr:send-alert-digests appears in php artisan schedule:list.',
            'commands' => [
                'php artisan queue:work',
                'php artisan queue:failed',
                '* * * * * cd /path/to/apps/server && php artisan schedule:run',
                'php artisan schedule:list',
            ],
        ]),
        fn ($step) => $step->toMatchArray([
            'key' => 'widget_smoke',
            'label' => 'Send a widget smoke test',
            'status' => 'ready',
        ]),
        fn ($step) => $step->toMatchArray([
            'key' => 'cobrowse_transport_smoke',
            'label' => 'Run cobrowse transport smoke',
            'status' => 'ready',
            'status_label' => 'No data yet',
            'action' => 'Run php artisan wayfindr:cobrowse-transport-smoke from apps/server after a consented widget smoke test, then review aggregate transport state before relying on cobrowse.',
            'commands' => ['php artisan wayfindr:cobrowse-transport-smoke'],
        ]),
        fn ($step) => $step->toMatchArray([
            'key' => 'backup_restore',
            'label' => 'Confirm backups can restore',
            'status' => 'manual',
        ]),
    );
});

test('readiness diagnostics include a dogfood gate summary', function (): void {
    config([
        'app.url' => 'https://support.example.test',
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => 'wayfindr-production',
        'broadcasting.connections.reverb.key' => 'wayfindr-key',
        'broadcasting.connections.reverb.secret' => 'wayfindr-secret',
        'broadcasting.connections.reverb.options.host' => 'support.example.test',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
        'queue.default' => 'database',
    ]);

    $readiness = app(OperatorReadiness::class)->summary();
    $summary = $readiness['dogfood_summary'];
    $items = collect($summary['items'])->keyBy('key');

    expect($summary)->toMatchArray([
        'status' => 'manual',
        'label' => 'Manual proof needed',
        'attention_count' => 0,
    ])
        ->and(array_keys($items->all()))->toBe([
            'production_https_host',
            'demo_account_site',
            'host_widget_install',
            'support_loop_smoke',
            'ticket_workflow',
            'alerts_email',
            'cobrowse_observe_mode',
            'operator_boundary',
            'data_responsibility',
        ])
        ->and($items->get('production_https_host'))->toMatchArray([
            'label' => 'Production-like HTTPS host',
            'status' => 'ready',
        ])
        ->and($items->get('host_widget_install'))->toMatchArray([
            'label' => 'Host project widget install',
            'status' => 'manual',
        ])
        ->and($items->get('support_loop_smoke'))->toMatchArray([
            'label' => 'Full support-loop smoke',
            'status' => 'manual',
        ])
        ->and($items->get('support_loop_smoke')['commands'][0])->toContain('scripts/smoke/support-loop.sh')
        ->and($items->get('support_loop_smoke')['commands'][0])->toContain('WAYFINDR_HOST_PAGE_URL')
        ->and($items->get('alerts_email'))->toMatchArray([
            'label' => 'Alerts and email',
            'status' => 'manual',
        ])
        ->and($items->get('operator_boundary'))->toMatchArray([
            'label' => 'Operator readiness boundary',
            'status' => 'ready',
        ])
        ->and($items->get('data_responsibility'))->toMatchArray([
            'label' => 'Data responsibility review',
            'status' => 'manual',
            'docs_url' => 'https://github.com/adamgreenwell/wayfindr/blob/main/docs/privacy/data-responsibility.md',
        ]);
});

test('readiness diagnostics include config backed retention visibility', function (): void {
    config([
        'wayfindr.retention' => [
            'label' => 'Pilot 30-day retention',
            'status' => 'ready',
            'summary' => 'Pilot retention is documented for the demo install.',
            'description' => 'Records are reviewed before production traffic is allowed.',
            'docs_url' => 'https://docs.example.test/retention',
            'items' => [
                [
                    'label' => 'Conversations and messages',
                    'value' => '30 days',
                    'description' => 'The operator reviews conversation records after each pilot cycle.',
                ],
                [
                    'label' => 'Backups',
                    'value' => 'Host policy',
                    'description' => 'Backups follow the host lifecycle outside Wayfindr.',
                ],
            ],
            'reminders' => [
                'Tell pilot users how long support records are kept.',
                '',
                'Review backup retention before the demo.',
            ],
        ],
    ]);

    $readiness = app(OperatorReadiness::class)->summary();

    expect($readiness['retention_summary'])->toMatchArray([
        'label' => 'Pilot 30-day retention',
        'status' => 'ready',
        'status_label' => 'Documented',
        'summary' => 'Pilot retention is documented for the demo install.',
        'description' => 'Records are reviewed before production traffic is allowed.',
        'docs_url' => 'https://docs.example.test/retention',
        'items' => [
            [
                'label' => 'Conversations and messages',
                'value' => '30 days',
                'description' => 'The operator reviews conversation records after each pilot cycle.',
            ],
            [
                'label' => 'Backups',
                'value' => 'Host policy',
                'description' => 'Backups follow the host lifecycle outside Wayfindr.',
            ],
        ],
        'reminders' => [
            'Tell pilot users how long support records are kept.',
            'Review backup retention before the demo.',
        ],
    ]);
});

test('configured retention attention raises the readiness total and next step', function (): void {
    $readyEnv = [
        'app.url' => 'https://support.example.test',
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => 'wayfindr-production',
        'broadcasting.connections.reverb.key' => 'wayfindr-key',
        'broadcasting.connections.reverb.secret' => 'wayfindr-secret',
        'broadcasting.connections.reverb.options.host' => 'support.example.test',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
        'queue.default' => 'database',
    ];

    config($readyEnv + ['wayfindr.retention' => ['status' => 'ready']]);
    $ready = app(OperatorReadiness::class)->summary();

    config($readyEnv + ['wayfindr.retention' => [
        'status' => 'attention',
        'summary' => 'Retention review is overdue for this install.',
        'description' => 'The operator flagged retention as needing attention before traffic.',
    ]]);
    $blocked = app(OperatorReadiness::class)->summary();

    // A configured retention attention now contributes to the top-level totals
    // instead of only living in the retention summary.
    expect($blocked['attention_count'])->toBe($ready['attention_count'] + 1)
        ->and($blocked['label'])->toBe('Needs attention')
        ->and($blocked['retention_summary']['status'])->toBe('attention')
        ->and($blocked['next_step']['status'])->toBe('attention');

    // When no runtime check is blocking, retention attention is the next step,
    // ahead of manual smoke steps, so it cannot be missed.
    $blockedByCheck = collect($blocked['checks'])->contains(fn (array $check): bool => $check['status'] === 'attention');

    if (! $blockedByCheck) {
        expect($blocked['next_step']['key'])->toBe('retention_posture');
    }
});

test('dogfood support loop gate allows manual refresh when realtime is not ready', function (): void {
    config([
        'app.url' => 'https://support.example.test',
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => null,
        'broadcasting.connections.reverb.key' => null,
        'broadcasting.connections.reverb.secret' => null,
        'broadcasting.connections.reverb.options.host' => null,
        'broadcasting.connections.reverb.options.port' => null,
        'broadcasting.connections.reverb.options.scheme' => null,
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
        'queue.default' => 'database',
    ]);

    $readiness = app(OperatorReadiness::class)->summary();
    $widgetSmoke = collect($readiness['smoke_path'])->firstWhere('key', 'widget_smoke');
    $supportLoop = collect($readiness['dogfood_summary']['items'])->firstWhere('key', 'support_loop_smoke');

    expect($widgetSmoke)->toMatchArray([
        'status' => 'attention',
    ])
        ->and($supportLoop)->toMatchArray([
            'label' => 'Full support-loop smoke',
            'status' => 'manual',
            'status_label' => 'Manual proof',
        ])
        ->and($supportLoop['summary'])->toContain('Manual refresh remains acceptable')
        ->and($supportLoop['detail'])->toContain('manual refresh as a stated fallback');
});

test('dogfood support loop gate blocks when queue workers are disabled', function (): void {
    config([
        'app.url' => 'https://support.example.test',
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => 'wayfindr-production',
        'broadcasting.connections.reverb.key' => 'wayfindr-key',
        'broadcasting.connections.reverb.secret' => 'wayfindr-secret',
        'broadcasting.connections.reverb.options.host' => 'support.example.test',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
        'queue.default' => 'sync',
    ]);

    $readiness = app(OperatorReadiness::class)->summary();
    $queueWorker = collect($readiness['checks'])->firstWhere('key', 'queue_worker');
    $supportLoop = collect($readiness['dogfood_summary']['items'])->firstWhere('key', 'support_loop_smoke');

    expect($queueWorker)->toMatchArray([
        'status' => 'attention',
        'summary' => 'QUEUE_CONNECTION is sync.',
    ])
        ->and($readiness['dogfood_summary'])->toMatchArray([
            'status' => 'attention',
            'label' => 'Dogfood blocked',
        ])
        ->and($supportLoop)->toMatchArray([
            'label' => 'Full support-loop smoke',
            'status' => 'attention',
            'status_label' => 'Needs attention',
            'summary' => 'Fix the blocked app/runtime checks before running the browser-backed support-loop smoke.',
        ]);
});

test('dogfood gate summary blocks on insecure public app urls', function (): void {
    config([
        'app.url' => 'http://localhost',
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
        'queue.default' => 'database',
    ]);

    $readiness = app(OperatorReadiness::class)->summary();
    $summary = $readiness['dogfood_summary'];
    $items = collect($summary['items'])->keyBy('key');

    expect($summary)->toMatchArray([
        'status' => 'attention',
        'label' => 'Dogfood blocked',
    ])
        ->and($items->get('production_https_host'))->toMatchArray([
            'status' => 'attention',
            'status_label' => 'Needs attention',
        ])
        ->and($items->get('host_widget_install'))->toMatchArray([
            'status' => 'attention',
        ])
        ->and($items->get('support_loop_smoke'))->toMatchArray([
            'status' => 'attention',
        ]);
});

test('readiness smoke path reflects cobrowse transport attention without leaking support data', function (): void {
    $this->travelTo(Carbon::parse('2026-06-20 12:00:00'));

    config([
        'app.url' => 'https://support.example.test',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
        'queue.default' => 'database',
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => 'wayfindr-production',
        'broadcasting.connections.reverb.key' => 'wayfindr-key',
        'broadcasting.connections.reverb.secret' => 'wayfindr-secret',
        'broadcasting.connections.reverb.options.host' => 'support.example.test',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
    ]);

    $site = Site::factory()->create(['name' => 'Sensitive Smoke Site']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-smoke-path',
    ]);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-SMOKEPATH',
        'subject' => 'Private cobrowse subject',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'metadata' => [
            'telemetry' => [
                'reported_at' => now()->toJSON(),
                'dropped_batches' => 1,
                'reconnects' => 0,
            ],
            'snapshot' => [
                'reported_at' => now()->toJSON(),
                'title' => 'Private smoke page',
                'page_url' => 'https://customer.example.test/private-smoke',
                'text' => 'Private smoke contents',
            ],
        ],
    ]);

    $readiness = app(OperatorReadiness::class)->summary();
    $step = collect($readiness['smoke_path'])->firstWhere('key', 'cobrowse_transport_smoke');

    expect($step)->toMatchArray([
        'label' => 'Run cobrowse transport smoke',
        'status' => 'attention',
        'status_label' => 'Needs attention',
        'summary' => '1 active cobrowse session needs transport attention.',
    ])
        ->and($step['action'])->toContain('php artisan wayfindr:cobrowse-transport-smoke')
        ->and($step['action'])->not->toContain('WF-SMOKEPATH')
        ->and($step['action'])->not->toContain('Private cobrowse subject')
        ->and($step['action'])->not->toContain('Sensitive Smoke Site')
        ->and($step['action'])->not->toContain('anon-smoke-path')
        ->and($step['action'])->not->toContain('Private smoke page')
        ->and($step['action'])->not->toContain('customer.example.test')
        ->and($step['action'])->not->toContain('Private smoke contents');
});

test('readiness smoke path preserves cobrowse manual remediation when transport cannot be inspected', function (): void {
    config([
        'app.url' => 'https://support.example.test',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
        'queue.default' => 'database',
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => 'wayfindr-production',
        'broadcasting.connections.reverb.key' => 'wayfindr-key',
        'broadcasting.connections.reverb.secret' => 'wayfindr-secret',
        'broadcasting.connections.reverb.options.host' => 'support.example.test',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
    ]);

    Schema::shouldReceive('hasTable')
        ->byDefault()
        ->andReturn(true);
    Schema::shouldReceive('hasTable')
        ->with('cobrowse_sessions')
        ->once()
        ->andReturn(false);

    $readiness = app(OperatorReadiness::class)->summary();
    $step = collect($readiness['smoke_path'])->firstWhere('key', 'cobrowse_transport_smoke');

    expect($step)->toMatchArray([
        'label' => 'Run cobrowse transport smoke',
        'status' => 'manual',
        'status_label' => 'Manual check',
        'summary' => 'Cobrowse transport readiness could not inspect active sessions.',
        'action' => 'Confirm the database is reachable, run php artisan migrate --force if needed, then rerun php artisan wayfindr:cobrowse-transport-smoke.',
    ])
        ->and($readiness['next_step'])->toMatchArray([
            'key' => 'background_processes',
            'label' => 'Confirm background workers',
            'status' => 'manual',
        ]);
});

test('readiness diagnostics require an authenticated agent', function (): void {
    $this->get('/dashboard/readiness')
        ->assertRedirect('/login');
});
