<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\User;
use App\Support\OperatorReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
        ->assertSee('php artisan schedule:run')
        ->assertSee('php artisan reverb:restart')
        ->assertSee('Post-install smoke path')
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
