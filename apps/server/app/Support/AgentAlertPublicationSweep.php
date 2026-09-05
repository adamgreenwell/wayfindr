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
     * @param  (callable(string): void)|null  $afterPending
     */
    public static function run(
        bool $markForBroadcast = false,
        ?callable $afterPending = null,
    ): int {
        $reconciled = 0;
        $afterId = null;

        do {
            $batch = self::runBatch($afterId, self::BATCH_SIZE, $markForBroadcast, $afterPending);
            $reconciled += $batch['reconciled'];
            $afterId = $batch['last_id'];
        } while ($batch['has_more']);

        return $reconciled;
    }

    /**
     * Reconcile one restart-safe page for queue workers with finite timeouts.
     *
     * @param  (callable(string): void)|null  $afterPending
     * @return array{reconciled: int, last_id: string|null, has_more: bool}
     */
    public static function runBatch(
        ?string $afterId,
        int $limit,
        bool $markForBroadcast = false,
        ?callable $afterPending = null,
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
                'agent_alert_broadcast_claim_version',
                'agent_alert_broadcast_pending_version',
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

            if ($data === null) {
                continue;
            }

            $id = (string) $notification->id;
            $fingerprintMatches = hash_equals(
                (string) ($notification->agent_alert_fingerprint ?? ''),
                AgentAlertPublicationFingerprint::for($data),
            );
            $version = $notification->agent_alert_version ?? null;
            $versioned = is_string($version) && $version !== '';
            $hasPendingBroadcast = $markForBroadcast
                && is_string($notification->agent_alert_broadcast_pending_version ?? null)
                && $notification->agent_alert_broadcast_pending_version !== '';

            if ($fingerprintMatches && $versioned && ! $hasPendingBroadcast) {
                continue;
            }

            $result = self::reconcile($id, $markForBroadcast);

            if ($result['reconciled']) {
                $reconciled++;
            }

            if ($result['broadcast_pending'] && $afterPending !== null) {
                $afterPending($id);
            }
        }

        return [
            'reconciled' => $reconciled,
            'last_id' => $page->isEmpty() ? null : (string) $page->last()->id,
            'has_more' => $hasMore,
        ];
    }

    /** @return array{reconciled: bool, broadcast_pending: bool} */
    private static function reconcile(string $id, bool $markForBroadcast): array
    {
        return DB::transaction(function () use ($id, $markForBroadcast): array {
            $notification = DB::table('notifications')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $notification instanceof stdClass) {
                return ['reconciled' => false, 'broadcast_pending' => false];
            }

            $data = self::decode($notification->data);

            if ($data === null) {
                return ['reconciled' => false, 'broadcast_pending' => false];
            }

            $fingerprint = AgentAlertPublicationFingerprint::for($data);
            $recordedFingerprint = $notification->agent_alert_fingerprint ?? null;
            $recordedVersion = $notification->agent_alert_version ?? null;
            $fingerprintMatches = is_string($recordedFingerprint)
                && hash_equals($recordedFingerprint, $fingerprint);
            $publicationAlreadyVersioned = $fingerprintMatches
                && is_string($recordedVersion)
                && $recordedVersion !== '';

            if ($publicationAlreadyVersioned) {
                if (! $markForBroadcast
                    || ! is_string($notification->agent_alert_broadcast_pending_version ?? null)
                    || $notification->agent_alert_broadcast_pending_version === '') {
                    return ['reconciled' => false, 'broadcast_pending' => false];
                }

                if (is_string($notification->agent_alert_broadcast_claim_version ?? null)
                    && hash_equals($recordedVersion, $notification->agent_alert_broadcast_claim_version)) {
                    DB::table('notifications')->where('id', $id)->update([
                        'agent_alert_broadcast_pending_version' => null,
                    ]);

                    return ['reconciled' => false, 'broadcast_pending' => false];
                }

                if (! hash_equals($recordedVersion, $notification->agent_alert_broadcast_pending_version)) {
                    DB::table('notifications')->where('id', $id)->update([
                        'agent_alert_broadcast_pending_version' => $recordedVersion,
                    ]);
                }

                return ['reconciled' => false, 'broadcast_pending' => true];
            }

            $firstPublication = blank($recordedFingerprint) || blank($recordedVersion);
            $version = $firstPublication ? $id : (string) Str::uuid();
            $updates = [
                'agent_alerted_at' => $notification->updated_at
                    ?? $notification->created_at
                    ?? now(),
                'agent_alert_version' => $version,
                // Deliberately do not touch agent_alert_broadcast_claim_version.
                // The claim is recorded only after Reverb accepts the event.
                'agent_alert_fingerprint' => $fingerprint,
            ];

            if ($markForBroadcast) {
                // This marker and the repaired version commit together. If the
                // queue enqueue fails immediately afterwards, retrying the same
                // cursor page sees this marker and enqueues it again.
                $updates['agent_alert_broadcast_pending_version'] = $version;
            }

            DB::table('notifications')->where('id', $id)->update($updates);

            return [
                'reconciled' => true,
                'broadcast_pending' => $markForBroadcast,
            ];
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
