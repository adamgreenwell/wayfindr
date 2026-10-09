<?php

declare(strict_types=1);

namespace App\Support\Updates;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Best-effort, deduplicated mirror; never an admission or history authority. */
class OperatorUpdateAudit
{
    /** @param array<string, mixed> $snapshot */
    public function mirror(array $snapshot): bool
    {
        $operations = isset($snapshot['operations']) ? $snapshot['operations'] : [$snapshot['operation'] ?? null];
        try {
            DB::transaction(function () use ($operations, $snapshot): void {
                foreach ($operations as $operation) {
                    if (! is_array($operation)) {
                        continue;
                    }
                    foreach ($operation['events'] as $event) {
                        $receipt = $operation['operator'] ?? [];
                        $actor = $receipt['prepare']['actor']['id'] ?? null;
                        foreach (['start', 'cancel'] as $kind) {
                            if (is_array($receipt[$kind] ?? null) && $event['revision'] >= $receipt[$kind]['revision']) {
                                $actor = $receipt[$kind]['actor']['id'];
                            }
                        }
                        $metadata = [
                            'installation_id' => $snapshot['installation_id'],
                            'operation_id' => $operation['operation_id'],
                            'event_revision' => $event['revision'], 'code' => $event['code'], 'phase' => $event['phase'],
                            'plan_id' => $operation['plan_id'], 'source' => $operation['source'], 'target' => $operation['target'],
                            'checkpoint' => $operation['checkpoint'], 'mutation_started' => $operation['mutation_started'],
                            'error' => $operation['error'],
                        ];
                        foreach (['apply', 'protection', 'operator'] as $key) {
                            if (isset($operation[$key])) {
                                $metadata[$key] = $operation[$key];
                            }
                        }
                        DB::table('audit_events')->insertOrIgnore([
                            'managed_update_event_key' => hash('sha256', $snapshot['installation_id'].':'.$operation['operation_id'].':'.$event['revision']),
                            'account_id' => null, 'site_id' => null,
                            'actor_type' => $actor === null ? null : (new User)->getMorphClass(), 'actor_id' => $actor,
                            'subject_type' => null, 'subject_id' => null, 'action' => 'operator_update.'.$event['code'],
                            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                            'occurred_at' => date('Y-m-d H:i:s', $event['at']), 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            });

            return true;
        } catch (Throwable) {
            // Never replay a host admission because its optional DB mirror failed.
            return false;
        }
    }
}
