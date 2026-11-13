<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Support\Ai\Observability\DraftRefinementAudit;
use App\Support\Ai\Usage\AiUsageClock;
use App\Support\Ai\Usage\AiUsageLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Operator opt-in; finite independent stores, no support-text deletion. */
final class PruneAssistObservabilityCommand extends Command
{
    protected $signature = 'wayfindr:prune-assist-observability {--dry-run : Count one bounded eligible batch without deleting}';

    protected $description = 'Prune configured Assist feedback, lifecycle audits and terminal usage history in batches of 500.';

    public function handle(AiUsageClock $clock, AiUsageLedger $ledger): int
    {
        foreach (['feedback', 'audit', 'usage'] as $store) {
            $days = config('wayfindr.ai.observability.'.$store.'_retention_days', 0);
            if (filter_var($days, FILTER_VALIDATE_INT) === false || (int) $days < 0 || (int) $days > 3650 || ($store === 'usage' && (int) $days > 0 && (int) $days < 32)) {
                $this->error('Invalid Assist retention configuration. Usage requires zero or at least 32 days.');

                return self::FAILURE;
            }
        }
        $counts = [];
        foreach (['feedback' => 'ai_assist_feedback', 'audit' => 'audit_events'] as $store => $table) {
            $days = (int) config('wayfindr.ai.observability.'.$store.'_retention_days', 0);
            $counts[$store] = 0;
            if ($days === 0) {
                continue;
            }
            $query = DB::table($table)->where($store === 'audit' ? 'occurred_at' : 'updated_at', '<', $clock->now()->subDays($days));
            if ($store === 'audit') {
                $query->whereIn('action', self::actions());
            }
            foreach ((clone $query)->orderBy('id')->limit(500)->get(['id', 'account_id']) as $hint) {
                if ($this->option('dry-run')) {
                    $counts[$store]++;

                    continue;
                }
                // Missing accounts already cascade their feedback; audit history can outlive an account.
                $counts[$store] += DB::transaction(function () use ($query, $hint): int {
                    DB::table('ai_usage_scopes')->where('scope_key', 'installation')->lockForUpdate()->first();
                    Account::query()->whereKey($hint->account_id)->lockForUpdate()->first();

                    return (clone $query)->where('id', $hint->id)->delete();
                });
            }
        }
        $days = (int) config('wayfindr.ai.observability.usage_retention_days', 0);
        $counts['usage'] = $days === 0 ? 0 : $ledger->pruneHistory($days, 500, (bool) $this->option('dry-run'));
        $this->info(json_encode(['dry_run' => (bool) $this->option('dry-run'), 'bounded_rows' => $counts], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    public static function actions(): array
    {
        $actions = [];
        foreach (['ai_summary', 'ai_reply_draft', 'ai_knowledge_suggestion', 'ai_ticket_suggestion'] as $task) {
            foreach (['requested', 'started', 'generated', 'failed'] as $state) {
                $actions[] = 'conversation.'.$task.'.'.$state;
            }
        }
        foreach (DraftRefinementAudit::STATES as $state) {
            $actions[] = 'conversation.ai_draft_refinement.'.$state;
        }

        return $actions;
    }
}
