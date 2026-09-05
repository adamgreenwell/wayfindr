<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/** Reconcile browser-alert metadata written across a release activation. */
final class AgentAlertPublicationSweep
{
    public static function run(): int
    {
        $reconciled = 0;

        DB::table('notifications')
            ->select([
                'id',
                'data',
                'created_at',
                'updated_at',
                'agent_alerted_at',
                'agent_alert_version',
                'agent_alert_fingerprint',
            ])
            ->orderBy('id')
            ->chunkById(500, function ($notifications) use (&$reconciled): void {
                foreach ($notifications as $notification) {
                    $data = self::decode($notification->data);

                    if ($data === null || hash_equals(
                        (string) ($notification->agent_alert_fingerprint ?? ''),
                        AgentAlertPublicationFingerprint::for($data),
                    )) {
                        continue;
                    }

                    if (self::reconcile((string) $notification->id)) {
                        $reconciled++;
                    }
                }
            });

        return $reconciled;
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
