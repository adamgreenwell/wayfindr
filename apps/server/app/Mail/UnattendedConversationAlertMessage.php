<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class UnattendedConversationAlertMessage extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, array{
     *     notification_id: string,
     *     reference: string,
     *     site_name: string,
     *     subject: string,
     *     waiting_since: string|null,
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
        $count = count($this->candidates);

        return new Envelope(
            subject: $count === 1
                ? 'Wayfindr: a visitor is waiting unseen'
                : "Wayfindr: {$count} visitors are waiting unseen",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.unattended-alert',
            text: 'mail.unattended-alert-text',
            with: [
                'agentName' => $this->agentName,
                'candidates' => $this->candidates,
                'candidateCount' => count($this->candidates),
                'generatedAt' => $this->generatedAt,
            ],
        );
    }

    public static function waitingLabel(?string $waitingSince, CarbonInterface $generatedAt): string
    {
        if (! is_string($waitingSince) || trim($waitingSince) === '') {
            return 'recently';
        }

        return Str::of($generatedAt->toImmutable()->diffForHumans($waitingSince, syntax: CarbonInterface::DIFF_ABSOLUTE))
            ->prepend('for ')
            ->toString();
    }
}
