<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\OperatorReadinessConfirmation;
use App\Models\User;
use App\Support\OperatorReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        ->assertSee('php artisan wayfindr:mail-test --to=you@example.com')
        ->assertSee('php artisan queue:failed')
        ->assertSee('php artisan queue:work')
        ->assertSee('php artisan schedule:run')
        ->assertSee('php artisan reverb:start --host=127.0.0.1 --port=8080')
        ->assertSee('php artisan reverb:restart')
        ->assertSee('Post-install smoke path')
        ->assertSee('Recommended next step')
        ->assertSee('Confirm background workers')
        ->assertSee('Open the public app URL')
        ->assertSee('Send a widget smoke test')
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
        'action' => 'Confirm php artisan queue:work is managed by Forge, Supervisor, systemd, or your host; run php artisan queue:failed to inspect failures; and verify * * * * * cd /path/to/apps/server && php artisan schedule:run is configured once per minute.',
    ]);
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
            'note' => 'Forge scheduler is running every minute.',
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
            'note' => 'Forge scheduler was checked last week.',
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
        ->assertSee('Scheduler was checked before a deploy.')
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
        ->assertSee('Forge scheduled job is configured.');
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
    ]);
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
        ]),
        fn ($step) => $step->toMatchArray([
            'key' => 'background_processes',
            'label' => 'Confirm background workers',
            'status' => 'manual',
            'action' => 'Confirm php artisan queue:work is managed by Forge, Supervisor, systemd, or your host; run php artisan queue:failed to inspect failures; and verify * * * * * cd /path/to/apps/server && php artisan schedule:run is configured once per minute.',
        ]),
        fn ($step) => $step->toMatchArray([
            'key' => 'widget_smoke',
            'label' => 'Send a widget smoke test',
            'status' => 'ready',
        ]),
        fn ($step) => $step->toMatchArray([
            'key' => 'backup_restore',
            'label' => 'Confirm backups can restore',
            'status' => 'manual',
        ]),
    );
});

test('readiness diagnostics require an authenticated agent', function (): void {
    $this->get('/dashboard/readiness')
        ->assertRedirect('/login');
});
