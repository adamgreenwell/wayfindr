<?php

namespace App\Console\Commands;

use App\Mail\WayfindrMailTestMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class MailTestCommand extends Command
{
    protected $signature = 'wayfindr:mail-test
        {--to= : Recipient email address for the smoke test}';

    protected $description = 'Send a Wayfindr mail smoke test through the configured mailer.';

    public function handle(): int
    {
        $recipient = trim((string) $this->option('to'));

        if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Pass --to with a valid recipient email address.');

            return self::FAILURE;
        }

        $mailer = strtolower((string) config('mail.default', 'log'));
        $from = (string) config('mail.from.address', '');

        $this->info('Sending Wayfindr mail smoke test.');
        $this->line("Mailer: {$mailer}");

        if ($mailer === 'smtp') {
            $this->line('SMTP host: '.(string) config('mail.mailers.smtp.host', 'not set'));
            $this->line('SMTP port: '.(string) config('mail.mailers.smtp.port', 'not set'));
            $this->line('SMTP scheme: '.((string) config('mail.mailers.smtp.scheme') ?: 'not set'));
        }

        $this->line('From: '.($from !== '' ? $from : 'not set'));
        $this->line("Recipient: {$recipient}");

        Mail::to($recipient)->send(new WayfindrMailTestMessage);

        $this->info('Mail smoke test sent to the configured mailer.');

        return self::SUCCESS;
    }
}
