<?php

namespace App\Support;

use App\Models\CobrowseSession;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Model;

class CobrowseAuditTrail
{
    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>|null  $previousRequest
     */
    public function resyncRequested(CobrowseSession $session, User $actor, array $request, ?array $previousRequest): void
    {
        $this->record($session, $actor, 'cobrowse.resync_requested', [
            'support_code' => $this->supportCode($session),
            'request_id' => $this->stringOrNull($request['id'] ?? null),
            'requested_at' => $this->stringOrNull($request['requested_at'] ?? null),
            'previous_request_id' => $this->stringOrNull($previousRequest['id'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $transition
     */
    public function resyncFulfilled(CobrowseSession $session, Visitor $actor, array $transition): void
    {
        $this->record($session, $actor, 'cobrowse.resync_fulfilled', [
            'support_code' => $this->supportCode($session),
            'request_id' => $this->stringOrNull($transition['request_id'] ?? null),
            'fulfilled_at' => $this->stringOrNull($transition['fulfilled_at'] ?? null),
            'snapshot_reported_at' => $this->stringOrNull($transition['snapshot_reported_at'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $transition
     */
    public function resyncIgnored(CobrowseSession $session, Visitor $actor, array $transition): void
    {
        $this->record($session, $actor, 'cobrowse.resync_ignored', [
            'support_code' => $this->supportCode($session),
            'active_request_id' => $this->stringOrNull($transition['active_request_id'] ?? null),
            'response_request_id' => $this->stringOrNull($transition['response_request_id'] ?? null),
            'reason' => $this->stringOrNull($transition['reason'] ?? null),
            'ignored_at' => $this->stringOrNull($transition['ignored_at'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $transition
     */
    public function resyncExhausted(CobrowseSession $session, Visitor $actor, array $transition): void
    {
        $this->record($session, $actor, 'cobrowse.resync_exhausted', [
            'support_code' => $this->supportCode($session),
            'request_id' => $this->stringOrNull($transition['request_id'] ?? null),
            'attempts_exhausted_at' => $this->stringOrNull($transition['attempts_exhausted_at'] ?? null),
            'dropped_batches' => $this->intOrNull($transition['dropped_batches'] ?? null),
            'reconnects' => $this->intOrNull($transition['reconnects'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(CobrowseSession $session, ?Model $actor, string $action, array $metadata): void
    {
        $session->loadMissing(['conversation', 'site']);

        $session->auditEvents()->create([
            'account_id' => $session->site?->account_id,
            'site_id' => $session->site_id,
            'actor_type' => $actor ? $actor::class : null,
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }

    private function supportCode(CobrowseSession $session): ?string
    {
        $session->loadMissing('conversation');

        return $session->conversation?->support_code;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
