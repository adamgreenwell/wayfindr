<?php

declare(strict_types=1);

namespace App\Support\Ai\Observability;

use App\Console\Commands\PruneAssistObservabilityCommand;
use App\Enums\AccountPermission;
use App\Http\Controllers\AgentConversationAiPreparationController;
use App\Models\AiAssistFeedback;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationRating;
use App\Models\Site;
use App\Models\User;
use App\Support\Ai\Usage\AiUsageClock;
use App\Support\Conversations\ConversationLifecycleLog;
use Illuminate\Support\Facades\DB;

/** A bounded retained-data cohort, scoped to the viewer's current site authority. */
final readonly class AssistReport
{
    public const MAX_ROWS = 10000;

    public function read(User $hint, int $days = 7, string $task = 'all'): array
    {
        $actor = User::query()->find($hint->id);
        abort_unless($actor !== null && $actor->account_id && ! $actor->isDeactivated() && $actor->hasAccountPermission(AccountPermission::ViewAudit), 403);
        abort_unless($days >= 1 && $days <= 31, 422);
        $tasks = AgentConversationAiPreparationController::TASKS + ['refinement' => AssistFeedback::PRIVATE_PURPOSE];
        abort_unless($task === 'all' || isset($tasks[$task]), 422);
        $until = app(AiUsageClock::class)->now();
        $since = $until->subDays($days);
        $sites = Site::query()->where('account_id', $actor->account_id)->visibleToAgentIncludingArchived($actor)->select('id');
        $rows = AiAssistFeedback::query()->where('account_id', $actor->account_id)->whereIn('site_id', $sites)
            ->whereBetween('created_at', [$since, $until])->when($task !== 'all', fn ($q) => $q->where('purpose', $tasks[$task]))->limit(self::MAX_ROWS + 1)->get()->sortBy('id')->values();
        $audit = AuditEvent::query()->where('account_id', $actor->account_id)->whereIn('site_id', $sites)
            ->whereBetween('occurred_at', [$since, $until])->whereIn('action', PruneAssistObservabilityCommand::actions())->when($task !== 'all', fn ($q) => $q->where('metadata->purpose', $tasks[$task]))->limit(self::MAX_ROWS + 1)->get()->sortBy('id')->values();
        // Refuse the complete report rather than silently truncate an account's measures.
        if ($rows->count() > self::MAX_ROWS || $audit->count() > self::MAX_ROWS) {
            return ['complete' => false, 'days' => $days, 'task' => $task, 'limit' => self::MAX_ROWS];
        }
        $ratio = static fn (int $n, int $d): array => ['numerator' => $n, 'denominator' => $d, 'rate' => $d === 0 ? null : $n / $d];
        $viewed = $rows->whereNotNull('viewed_at')->count();
        $insertable = $rows->whereIn('purpose', AssistFeedback::INSERTABLE_PURPOSES);
        $insertableViewed = $insertable->whereNotNull('viewed_at')->count();
        $used = $insertable->whereNotNull('used_at')->count();
        $rejected = $rows->whereNotNull('rejected_at')->count();
        $assessed = $rows->whereIn('usefulness', ['useful', 'not_useful'])->count();
        $qualityResponses = $rows->whereIn('usefulness', ['useful', 'not_useful', 'unsure'])->count();
        $draftRows = $rows->whereIn('purpose', ['conversation_reply_draft', AssistFeedback::PRIVATE_PURPOSE]);
        $corrections = $insertable->whereNotNull('used_at')->whereIn('correction', ['none', 'minor', 'material']);
        $generations = $audit->filter(fn ($row) => is_string($row->metadata['generation'] ?? null) && ! ($row->metadata['erased'] ?? false))->groupBy(fn ($row) => ($row->metadata['purpose'] ?? 'unknown').':'.$row->metadata['generation']);
        $work = $generations->map(function ($events): array {
            $last = $events->last();
            $data = $last->metadata;
            $elapsed = $events->pluck('metadata.elapsed_ms')->filter(fn ($value) => is_int($value) && $value >= 0)->max();

            return ['generation' => $data['generation'], 'purpose' => $data['purpose'] ?? null, 'state' => $data['state'] ?? null, 'failure_code' => $data['failure_code'] ?? null, 'elapsed_ms' => $elapsed];
        });
        // Usage attempts are joined only through visible audit generations, never whole-account totals.
        $attempts = collect();
        foreach ($work->pluck('generation')->unique()->chunk(500) as $chunk) {
            $attempts = $attempts->concat(DB::table('ai_usage_attempts')->where('account_id', $actor->account_id)->whereIn('attempt_id', $chunk)->get());
        }
        $elapsed = $work->pluck('elapsed_ms')->filter(fn ($ms) => is_int($ms))->values();
        $cohortIds = $rows->whereNotNull('viewed_at')->pluck('conversation_id')->unique()->values();
        $usedIds = $insertable->whereNotNull('used_at')->pluck('conversation_id')->unique()->values();
        $outcome = static function ($ids) use ($since, $until, $ratio): array {
            $closed = 0;
            $rated = 0;
            $scores = ['good' => 0, 'ok' => 0, 'bad' => 0];
            foreach ($ids->chunk(500) as $chunk) {
                // Recorded close events establish closure, not a successful resolution.
                $closed += AuditEvent::query()->where('subject_type', (new Conversation)->getMorphClass())->whereIn('subject_id', $chunk)->where('action', ConversationLifecycleLog::CLOSED)->whereBetween('occurred_at', [$since, $until])->distinct()->count('subject_id');
                $ratings = ConversationRating::query()->whereIn('conversation_id', $chunk)->whereBetween('rated_at', [$since, $until]);
                $rated += (clone $ratings)->distinct()->count('conversation_id');
                foreach ($scores as $score => $count) {
                    $scores[$score] += (clone $ratings)->where('score', $score)->count();
                }
            }

            return ['conversations' => $ids->count(), 'recorded_closed_conversations' => $closed, 'rated_conversations' => $rated,
                'rating_coverage' => $ratio($rated, $ids->count()), 'rating_responses' => array_sum($scores), 'scores' => $scores];
        };

        return ['complete' => true, 'days' => $days, 'task' => $task, 'since' => $since->toIso8601String(), 'until' => $until->toIso8601String(),
            'feedback' => ['identities' => $rows->count(), 'viewed' => $viewed, 'used' => $used, 'rejected' => $rejected, 'insertable_viewed' => $insertableViewed, 'used_without_view' => $insertable->whereNotNull('used_at')->whereNull('viewed_at')->count(),
                'use_rate' => $task === 'summary' ? ['numerator' => 0, 'denominator' => 0, 'rate' => null, 'applicable' => false] : $ratio($insertable->whereNotNull('viewed_at')->whereNotNull('used_at')->count(), $insertableViewed),
                'reject_rate' => $ratio($rows->whereNotNull('viewed_at')->whereNotNull('rejected_at')->count(), $viewed),
                'draft_useful_rate' => $ratio($draftRows->where('usefulness', 'useful')->count(), $draftRows->whereIn('usefulness', ['useful', 'not_useful'])->count()),
                'draft_assessment_coverage' => $ratio($draftRows->whereIn('usefulness', ['useful', 'not_useful', 'unsure'])->count(), $draftRows->whereNotNull('viewed_at')->count()),
                'useful_rate' => $ratio($rows->where('usefulness', 'useful')->count(), $assessed), 'assessment_coverage' => $ratio($qualityResponses, $viewed),
                'definite_assessments' => $assessed, 'unsure' => $rows->where('usefulness', 'unsure')->count(),
                'material_correction_rate' => $ratio($corrections->where('correction', 'material')->count(), $corrections->count()), 'correction_coverage' => $ratio($corrections->count(), $used)],
            'work' => ['generations' => $work->count(), 'refused_admission' => $work->where('failure_code', 'usage')->count(),
                'failed' => $work->where('state', 'failed')->where('failure_code', '!=', 'usage')->count(),
                'states' => $work->countBy('state')->all(), 'elapsed_samples' => $elapsed->count(), 'server_elapsed_mean_ms' => $elapsed->isEmpty() ? null : $elapsed->avg()],
            'usage' => ['observed_attempts' => $attempts->count(), 'unknown_transport' => $attempts->where('state', 'unknown')->count(),
                'missing_attempt_records' => $work->pluck('generation')->unique()->diff($attempts->pluck('attempt_id'))->count(),
                'reported_input_tokens' => $attempts->whereNotNull('reported_input_tokens')->isEmpty() ? null : $attempts->sum('reported_input_tokens'),
                'reported_output_tokens' => $attempts->whereNotNull('reported_output_tokens')->isEmpty() ? null : $attempts->sum('reported_output_tokens'),
                'reported_input_samples' => $attempts->whereNotNull('reported_input_tokens')->count(), 'reported_output_samples' => $attempts->whereNotNull('reported_output_tokens')->count(),
                'charged_token_bounds' => $attempts->sum('debited_tokens'), 'held_token_bounds' => $attempts->whereIn('state', ['reserved', 'claimed'])->sum('reserved_tokens')],
            'outcomes' => ['viewed_cohort' => $outcome($cohortIds), 'used_cohort' => $outcome($usedIds)]];
    }
}
