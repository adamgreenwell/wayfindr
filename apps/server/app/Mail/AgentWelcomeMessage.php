<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AgentWelcomeMessage extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $accountName,
        public readonly string $agentName,
        public readonly string $agentEmail,
        public readonly string $temporaryPassword,
        public readonly string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Wayfindr',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.agent-welcome',
            text: 'mail.agent-welcome-text',
            with: [
                'accountName' => $this->accountName,
                'agentName' => $this->agentName,
                'agentEmail' => $this->agentEmail,
                'temporaryPassword' => $this->temporaryPassword,
                'loginUrl' => $this->loginUrl,
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
