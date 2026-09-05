<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/** Reconcile browser-alert metadata written across a release activation. */
final class AgentAlertPublicationSweep
{
    private const BATCH_SIZE = 500;

    /**
     * @param  (callable(string): void)|null  $afterReconcile
     */
    public static function run(?callable $afterReconcile = null): int
    {
        $reconciled = 0;
        $afterId = null;

        do {
            $batch = self::runBatch($afterId, self::BATCH_SIZE, $afterReconcile);
            $reconciled += $batch['reconciled'];
            $afterId = $batch['last_id'];
        } while ($batch['has_more']);

        return $reconciled;
    }

    /**
     * Reconcile one restart-safe page for queue workers with finite timeouts.
     *
     * @param  (callable(string): void)|null  $afterReconcile
     * @return array{reconciled: int, last_id: string|null, has_more: bool}
     */
    public static function runBatch(
        ?string $afterId,
        int $limit,
        ?callable $afterReconcile = null,
    ): array {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The agent alert reconciliation batch size must be positive.');
        }

        $reconciled = 0;
        $notifications = DB::table('notifications')
            ->select([
                'id',
                'data',
                'created_at',
                'updated_at',
                'agent_alerted_at',
                'agent_alert_version',
                'agent_alert_fingerprint',
            ])
            ->when($afterId !== null, fn ($query) => $query->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $notifications->count() > $limit;
        $page = $notifications->take($limit);

        foreach ($page as $notification) {
            $data = self::decode($notification->data);

            if ($data === null || hash_equals(
                (string) ($notification->agent_alert_fingerprint ?? ''),
                AgentAlertPublicationFingerprint::for($data),
            )) {
                continue;
            }

            $id = (string) $notification->id;

            if (! self::reconcile($id)) {
                continue;
            }

            $reconciled++;

            if ($afterReconcile !== null) {
                $afterReconcile($id);
            }
        }

        return [
            'reconciled' => $reconciled,
            'last_id' => $page->isEmpty() ? null : (string) $page->last()->id,
            'has_more' => $hasMore,
        ];
    }

    private static function reconcile(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $notification = DB::table('notifications')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $notification instanceof stdClass) {
                return false;
            }

            $data = self::decode($notification->data);

            if ($data === null) {
                return false;
            }

            $fingerprint = AgentAlertPublicationFingerprint::for($data);

            if (hash_equals((string) ($notification->agent_alert_fingerprint ?? ''), $fingerprint)) {
                return false;
            }

            $firstPublication = blank($notification->agent_alert_fingerprint ?? null);

            DB::table('notifications')->where('id', $id)->update([
                'agent_alerted_at' => $notification->updated_at
                    ?? $notification->created_at
                    ?? now(),
                'agent_alert_version' => $firstPublication
                    ? $id
                    : (string) Str::uuid(),
                // Deliberately do not touch agent_alert_broadcast_claim_version.
                // A sweep makes the state visible to durable reconciliation; a
                // current listener arriving after it must still be able to claim
                // and publish this exact version to an already-connected tab.
                'agent_alert_fingerprint' => $fingerprint,
            ]);

            return true;
        });
    }

    /** @return array<string, mixed>|null */
    private static function decode(mixed $data): ?array
    {
        if (is_array($data)) {
            return $data;
        }

        if (! is_string($data) || $data === '') {
            return null;
        }

        $decoded = json_decode($data, true);

        return is_array($decoded) ? $decoded : null;
    }
}
