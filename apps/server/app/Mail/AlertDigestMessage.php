<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AlertDigestMessage extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, array{
     *     kind: string,
     *     last_activity_at: string|null,
     *     notification_id: string,
     *     priority: string|null,
     *     reference: string,
     *     site_name: string,
     *     status: string|null,
     *     subject: string,
     *     url: string
     * }>  $candidates
     */
    public function __construct(
        public readonly string $agentName,
        public readonly array $candidates,
        public readonly CarbonInterface $generatedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Wayfindr alert digest: '.$this->candidateCount().' item'.($this->candidateCount() === 1 ? '' : 's'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.alert-digest',
            text: 'mail.alert-digest-text',
            with: [
                'agentName' => $this->agentName,
                'candidates' => $this->candidates,
                'candidateCount' => $this->candidateCount(),
                'generatedAt' => $this->generatedAt,
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

    public function candidateCount(): int
    {
        return count($this->candidates);
    }
}
