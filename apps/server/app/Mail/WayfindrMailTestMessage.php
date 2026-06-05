<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WayfindrMailTestMessage extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Wayfindr mail smoke test',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.wayfindr-mail-test',
            text: 'mail.wayfindr-mail-test-text',
            with: [
                'appName' => (string) config('app.name', 'Wayfindr'),
                'appUrl' => (string) config('app.url', ''),
                'sentAt' => now(),
            ],
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function attachments(): array
    {
        return [];
    }
}
