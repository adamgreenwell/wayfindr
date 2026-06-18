<?php

namespace App\Events;

use App\Models\CobrowseSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CobrowseStateUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public CobrowseSession $cobrowseSession, public string $kind)
    {
        $this->cobrowseSession->loadMissing('conversation');
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversations.'.$this->cobrowseSession->conversation->support_code),
        ];
    }

    public function broadcastAs(): string
    {
        return 'conversation.cobrowse.updated';
    }

    /**
     * @return array{conversation: array{support_code: string, status: string}, cobrowse: array{status: string}, update: array{kind: string, reported_at: string|null}, summary: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation' => [
                'support_code' => $this->cobrowseSession->conversation->support_code,
                'status' => $this->cobrowseSession->conversation->status,
            ],
            'cobrowse' => [
                'status' => $this->cobrowseSession->status,
            ],
            'update' => [
                'kind' => $this->kind,
                'reported_at' => $this->reportedAt(),
            ],
            'summary' => $this->summaryPayload(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryPayload(): array
    {
        $metadata = $this->cobrowseSession->metadata ?? [];
        $pageState = is_array($metadata['page_state'] ?? null) ? $metadata['page_state'] : [];
        $snapshot = is_array($metadata['snapshot'] ?? null) ? $metadata['snapshot'] : [];
        $mutations = is_array($metadata['mutations'] ?? null) ? $metadata['mutations'] : [];
        $resyncRequest = is_array($metadata['resync_request'] ?? null) ? $metadata['resync_request'] : [];

        return array_filter([
            'page_url' => $pageState['page_url'] ?? $snapshot['page_url'] ?? $mutations['last_page_url'] ?? null,
            'title' => $pageState['title'] ?? $snapshot['title'] ?? null,
            'batch_count' => $mutations['batch_count'] ?? null,
            'mutation_count' => $mutations['mutation_count'] ?? null,
            'last_sequence' => $mutations['last_sequence'] ?? null,
            'resync_request_id' => $resyncRequest['id'] ?? null,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function reportedAt(): ?string
    {
        $metadata = $this->cobrowseSession->metadata ?? [];
        $pageState = is_array($metadata['page_state'] ?? null) ? $metadata['page_state'] : [];
        $snapshot = is_array($metadata['snapshot'] ?? null) ? $metadata['snapshot'] : [];
        $mutations = is_array($metadata['mutations'] ?? null) ? $metadata['mutations'] : [];
        $resyncRequest = is_array($metadata['resync_request'] ?? null) ? $metadata['resync_request'] : [];

        return match ($this->kind) {
            'page_state' => $pageState['reported_at'] ?? null,
            'snapshot' => $snapshot['reported_at'] ?? null,
            'mutations' => $mutations['last_reported_at'] ?? null,
            'resync_requested' => $resyncRequest['requested_at'] ?? null,
            default => null,
        };
    }
}
