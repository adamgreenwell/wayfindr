<?php

use App\Mail\WayfindrMailTestMessage;
use Illuminate\Support\Facades\Mail;

test('it sends a mail smoke test message', function (): void {
    config([
        'app.name' => 'Wayfindr',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.mailers.smtp.scheme' => null,
        'mail.from.address' => 'support@example.test',
        'mail.from.name' => 'Wayfindr Support',
    ]);

    Mail::fake();

    $this->artisan('wayfindr:mail-test', [
        '--to' => 'ada@example.test',
    ])
        ->expectsOutputToContain('Sending Wayfindr mail smoke test.')
        ->expectsOutputToContain('Mailer: smtp')
        ->expectsOutputToContain('SMTP host: smtp.example.test')
        ->expectsOutputToContain('From: support@example.test')
        ->expectsOutputToContain('Recipient: ada@example.test')
        ->expectsOutputToContain('Mail smoke test sent to the configured mailer.')
        ->assertExitCode(0);

    Mail::assertSent(WayfindrMailTestMessage::class, function (WayfindrMailTestMessage $mail): bool {
        return $mail->hasTo('ada@example.test')
            && $mail->envelope()->subject === 'Wayfindr mail smoke test';
    });
});

test('it requires a valid recipient email address', function (): void {
    Mail::fake();

    $this->artisan('wayfindr:mail-test', [
        '--to' => 'not-an-email',
    ])
        ->expectsOutputToContain('Pass --to with a valid recipient email address.')
        ->assertExitCode(1);

    Mail::assertNothingSent();
});
