<?php

namespace App\Support\Sla;

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\SlaClock;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Sites\SiteAvailability;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Owns every persisted SLA clock transition.
 *
 * The row stores time already counted under the calendar that was in force.
 * Before support hours change, AgentSiteController advances the rows to that
 * instant. A later hours edit therefore changes the future without rewriting
 * yesterday's elapsed time.
 */
final class SlaClockManager
{
    public function startConversation(Conversation $conversation, ?CarbonInterface $startedAt = null): void
    {
        DB::transaction(function () use ($conversation, $startedAt): void {
            $conversation->loadMissing('site.account');
            $this->lockAccountPolicy((int) $conversation->site->account_id);
            $start = CarbonImmutable::instance($startedAt ?? $conversation->created_at ?? now());

            if (! $conversation->messages()->where('sender_type', User::class)->exists()) {
                $this->start($conversation, SlaClock::METRIC_FIRST_RESPONSE, $start);
            }

            if ($conversation->status !== 'closed') {
                $this->start($conversation, SlaClock::METRIC_RESOLUTION, $start);
            }
        });
    }

    public function startTicket(Ticket $ticket, ?CarbonInterface $startedAt = null): void
    {
        DB::transaction(function () use ($startedAt, $ticket): void {
            $ticket->loadMissing('site.account');
            $this->lockAccountPolicy((int) $ticket->account_id);

            if ($ticket->status !== 'closed') {
                $this->start(
                    $ticket,
                    SlaClock::METRIC_RESOLUTION,
                    CarbonImmutable::instance($startedAt ?? $ticket->created_at ?? now()),
                );
            }
        });
    }

    public function conversationMessageCreated(ConversationMessage $message): void
    {
        if ($message->sender_type !== User::class) {
            return;
        }

        $conversation = $message->conversation()->with('site')->first();

        if ($conversation) {
            $this->satisfy($conversation, SlaClock::METRIC_FIRST_RESPONSE, $message->created_at ?? now());
        }
    }

    public function conversationUpdated(Conversation $conversation): void
    {
        $conversation->loadMissing('site.account');

        if ($conversation->wasChanged('priority')) {
            DB::transaction(function () use ($conversation): void {
                $this->lockAccountPolicy((int) $conversation->site->account_id);
                $this->reconcileSubject($conversation, now());
            });
        }

        if (! $conversation->wasChanged('status')) {
            return;
        }

        if ($conversation->status === 'closed') {
            $this->cancel($conversation, SlaClock::METRIC_FIRST_RESPONSE, $conversation->closed_at ?? now());
            $this->satisfy($conversation, SlaClock::METRIC_RESOLUTION, $conversation->closed_at ?? now());

            return;
        }

        $this->startConversation($conversation, now());
    }

    public function ticketUpdated(Ticket $ticket): void
    {
        $ticket->loadMissing('site.account');

        if ($ticket->wasChanged('priority')) {
            DB::transaction(function () use ($ticket): void {
                $this->lockAccountPolicy((int) $ticket->account_id);
                $this->reconcileSubject($ticket, now());
            });
        }

        if (! $ticket->wasChanged('status')) {
            return;
        }

        if ($ticket->status === 'closed') {
            $this->satisfy($ticket, SlaClock::METRIC_RESOLUTION, $ticket->closed_at ?? now());

            return;
        }

        if ((string) $ticket->getOriginal('status') === 'closed') {
            $this->start($ticket, SlaClock::METRIC_RESOLUTION, now());
        }
    }

    public function advanceSite(Site $site, CarbonInterface $at): void
    {
        SlaClock::query()
            ->where('site_id', $site->id)
            ->whereNull('satisfied_at')
            ->whereNull('cancelled_at')
            ->with('site')
            ->lazyById(250)
            ->each(fn (SlaClock $clock) => $this->advance($clock, $at));
    }

    /** Resume clocks at the restoration boundary without charging archived time. */
    public function resumeSite(Site $site, CarbonInterface $at): void
    {
        SlaClock::query()
            ->where('site_id', $site->id)
            ->whereNull('satisfied_at')
            ->whereNull('cancelled_at')
            ->update(['last_counted_at' => CarbonImmutable::instance($at)]);
    }

    public function advanceAccount(Account $account, CarbonInterface $at): void
    {
        SlaClock::query()
            ->where('account_id', $account->id)
            ->whereNull('satisfied_at')
            ->whereNull('cancelled_at')
            ->with('site')
            ->lazyById(250)
            ->each(fn (SlaClock $clock) => $this->advance($clock, $at));
    }

    /** Persist breaches crossed under the policy that is still in force. */
    public function recordAccountBreaches(Account $account, CarbonInterface $at): void
    {
        SlaClock::query()
            ->select('id')
            ->where('account_id', $account->id)
            ->whereNull('satisfied_at')
            ->whereNull('cancelled_at')
            ->lazyById(250)
            ->each(fn (SlaClock $clock) => $this->evaluate((int) $clock->id, $at, recordWarning: false));
    }

    public function advance(SlaClock $clock, CarbonInterface $at): SlaClock
    {
        if (! $clock->isActive()) {
            return $clock;
        }

        $clock->load('site');

        return $this->advanceUsingSite($clock, $clock->site, $at);
    }

    private function advanceUsingSite(SlaClock $clock, Site $site, CarbonInterface $at): SlaClock
    {
        if (! $clock->isActive()) {
            return $clock;
        }

        $to = CarbonImmutable::instance($at);
        $from = CarbonImmutable::instance($clock->last_counted_at);

        if ($to->lessThanOrEqualTo($from)) {
            return $clock;
        }

        if ($site->isArchived()) {
            $clock->forceFill(['last_counted_at' => $to])->save();

            return $clock;
        }

        $clock->forceFill([
            'elapsed_seconds' => $clock->elapsed_seconds + SiteAvailability::elapsedOpenSeconds($site, $from, $to),
            'last_counted_at' => $to,
        ])->save();

        return $clock;
    }

    /**
     * Advance one row under a lock and persist a newly crossed boundary.
     *
     * @return array{clock: SlaClock, stage: 'warning'|'breach'|null}
     */
    public function evaluate(int $clockId, CarbonInterface $at, bool $recordWarning = true): array
    {
        return DB::transaction(function () use ($at, $clockId, $recordWarning): array {
            $candidate = SlaClock::query()->select(['id', 'site_id'])->findOrFail($clockId);
            // Calendar mutations lock the site before advancing its clock
            // rows. The minute evaluator takes the same site-then-clock order,
            // so it cannot project beyond an uncommitted schedule boundary
            // under whichever calendar happened to be visible first.
            $site = Site::query()->whereKey($candidate->site_id)->lockForUpdate()->firstOrFail();
            $clock = SlaClock::query()->with('subject')->lockForUpdate()->findOrFail($clockId);
            $clock->setRelation('site', $site);

            if ($site->isArchived()) {
                $this->advanceUsingSite($clock, $site, $at);

                return ['clock' => $clock->fresh(['site', 'subject']), 'stage' => null];
            }

            $this->advanceUsingSite($clock, $site, $at);
            $this->recordBreachIfCrossed($clock, $at);

            if ($recordWarning && $clock->isActive() && $clock->breached_at === null && $clock->elapsed_seconds >= $clock->warning_seconds && $clock->warned_at === null) {
                $clock->forceFill(['warned_at' => CarbonImmutable::instance($at)])->save();
            }

            $stage = match (true) {
                ! $clock->isActive() => null,
                $clock->breached_at !== null && $clock->breach_alerted_at === null => 'breach',
                $clock->breached_at === null && $clock->warned_at !== null && $clock->warning_alerted_at === null => 'warning',
                default => null,
            };

            return ['clock' => $clock->fresh(['site', 'subject']), 'stage' => $stage];
        });
    }

    public function recordAlertHandoff(int $clockId, string $stage, string $channel, int $userId): void
    {
        DB::transaction(function () use ($channel, $clockId, $stage, $userId): void {
            $clock = SlaClock::query()->lockForUpdate()->findOrFail($clockId);
            $recipientColumn = $this->alertRecipientColumn($stage, $channel);
            $ids = collect($clock->alertedUserIds($stage, $channel))
                ->push($userId)
                ->unique()
                ->values()
                ->all();

            $clock->forceFill([$recipientColumn => $ids])->save();
        });
    }

    public function completeAlertHandoff(int $clockId, string $stage, CarbonInterface $at): void
    {
        DB::transaction(function () use ($at, $clockId, $stage): void {
            $clock = SlaClock::query()->lockForUpdate()->findOrFail($clockId);
            $completedColumn = $this->alertCompletionColumn($stage);
            $clock->forceFill([$completedColumn => CarbonImmutable::instance($at)])->save();
        });
    }

    /** Complete a stage only while its routing facts stay locked and current. */
    public function completeAlertHandoffIfCurrent(
        int $clockId,
        string $stage,
        CarbonInterface $at,
        SlaAlertRouting $routing,
    ): bool {
        return DB::transaction(function () use ($at, $clockId, $routing, $stage): bool {
            $candidate = SlaClock::query()
                ->select(['id', 'account_id', 'site_id', 'subject_type', 'subject_id'])
                ->find($clockId);

            if (! $candidate) {
                return false;
            }

            // Every supported assignment, access, preference, and site-state
            // mutation starts with this account lock. Lock the routable rows in
            // their normal order too, then recheck and stamp inside this one
            // transaction so a new recipient cannot appear in between.
            $this->lockAccountPolicy((int) $candidate->account_id);
            $site = Site::query()->whereKey($candidate->site_id)->lockForUpdate()->first();
            $subject = $candidate->subject()->lockForUpdate()->first();
            $clock = SlaClock::query()->lockForUpdate()->find($clockId);

            if (! $site || ! $subject || ! $clock) {
                return false;
            }

            $clock->setRelation('site', $site);
            $clock->setRelation('subject', $subject);

            if (! $clock->alertStageIsCurrent($stage)) {
                return false;
            }

            $handoffPending = $routing->recipients($clock)
                ->contains(fn (User $agent): bool => collect($agent->wantsImmediateAlertEmail()
                    ? ['database', 'mail']
                    : ['database'])
                    ->contains(fn (string $channel): bool => ! $clock->alertWasHandedOff(
                        $stage,
                        $channel,
                        (int) $agent->id,
                    )));

            if ($handoffPending) {
                return false;
            }

            // With no recipients, completing also prevents stale delivery if
            // somebody opts in only after the operational moment has passed.
            $clock->forceFill([
                $this->alertCompletionColumn($stage) => CarbonImmutable::instance($at),
            ])->save();

            return true;
        });
    }

    /**
     * Apply a newly saved account policy to active work without charging the
     * account for time before it chose a target.
     */
    public function reconcileAccount(Account $account, CarbonInterface $at): void
    {
        $this->advanceAccount($account, $at);

        SlaClock::query()
            ->where('account_id', $account->id)
            ->whereNull('satisfied_at')
            ->whereNull('cancelled_at')
            ->with(['site', 'subject'])
            ->lazyById(250)
            ->each(fn (SlaClock $clock) => $clock->subject
                ? $this->reconcileClock($clock, $clock->subject, $at)
                : $clock->forceFill(['cancelled_at' => $at])->save());

        Conversation::query()
            ->whereHas('site', fn ($query) => $query->where('account_id', $account->id))
            ->where('status', '!=', 'closed')
            ->with('site.account')
            ->lazyById(250)
            ->each(fn (Conversation $conversation) => $this->startConversation($conversation, $at));

        Ticket::query()
            ->where('account_id', $account->id)
            ->where('status', '!=', 'closed')
            ->with('site.account')
            ->lazyById(250)
            ->each(fn (Ticket $ticket) => $this->startTicket($ticket, $at));
    }

    private function start(Model $subject, string $metric, CarbonInterface $startedAt): ?SlaClock
    {
        $site = $subject->site;
        $accountId = $subject instanceof Ticket ? (int) $subject->account_id : (int) $site->account_id;
        $priority = (string) ($subject->priority ?: 'normal');
        $policy = SlaPolicy::query()
            ->where('account_id', $accountId)
            ->where('priority', $priority)
            ->first();
        $targetMinutes = $policy?->targetMinutes($metric);

        if ($targetMinutes === null || $subject->slaClocks()->where('metric', $metric)->whereNull('satisfied_at')->whereNull('cancelled_at')->exists()) {
            return null;
        }

        $targetSeconds = $targetMinutes * 60;
        $start = CarbonImmutable::instance($startedAt);

        return $subject->slaClocks()->create([
            'account_id' => $accountId,
            'site_id' => $site->id,
            'metric' => $metric,
            'priority' => $priority,
            'target_seconds' => $targetSeconds,
            'warning_seconds' => SlaClock::warningSeconds($targetSeconds),
            'elapsed_seconds' => 0,
            'started_at' => $start,
            'last_counted_at' => $start,
        ]);
    }

    private function satisfy(Model $subject, string $metric, CarbonInterface $at): void
    {
        $subject->slaClocks()
            ->where('metric', $metric)
            ->whereNull('satisfied_at')
            ->whereNull('cancelled_at')
            ->with('site')
            ->orderBy('id')
            ->get()
            ->each(function (SlaClock $clock) use ($at): void {
                $this->advance($clock, $at);
                $attributes = ['satisfied_at' => CarbonImmutable::instance($at)];

                if ($clock->elapsed_seconds > $clock->target_seconds && $clock->breached_at === null) {
                    // The work completed before the scheduler noticed. Keep the
                    // missed target in history without sending a stale alert.
                    $attributes['breached_at'] = CarbonImmutable::instance($at);
                }

                $clock->forceFill($attributes)->save();
            });
    }

    private function cancel(Model $subject, string $metric, CarbonInterface $at): void
    {
        $subject->slaClocks()
            ->where('metric', $metric)
            ->whereNull('satisfied_at')
            ->whereNull('cancelled_at')
            ->with('site')
            ->orderBy('id')
            ->get()
            ->each(function (SlaClock $clock) use ($at): void {
                $this->advance($clock, $at);
                $this->recordBreachIfCrossed($clock, $at);
                $clock->forceFill(['cancelled_at' => CarbonImmutable::instance($at)])->save();
            });
    }

    private function reconcileSubject(Model $subject, CarbonInterface $at): void
    {
        $subject->slaClocks()
            ->whereNull('satisfied_at')
            ->whereNull('cancelled_at')
            ->with('site')
            ->get()
            ->each(fn (SlaClock $clock) => $this->reconcileClock($clock, $subject, $at));

        if ($subject instanceof Conversation) {
            $this->startConversation($subject, $at);
        } elseif ($subject instanceof Ticket) {
            $this->startTicket($subject, $at);
        }
    }

    private function reconcileClock(SlaClock $clock, Model $subject, CarbonInterface $at): void
    {
        $this->advance($clock, $at);
        $this->recordBreachIfCrossed($clock, $at);

        if ($clock->breached_at !== null) {
            return;
        }

        $priority = (string) ($subject->priority ?: 'normal');
        $policy = SlaPolicy::query()
            ->where('account_id', $clock->account_id)
            ->where('priority', $priority)
            ->first();
        $targetMinutes = $policy?->targetMinutes($clock->metric);

        if ($targetMinutes === null) {
            $clock->forceFill(['cancelled_at' => CarbonImmutable::instance($at)])->save();

            return;
        }

        $targetSeconds = $targetMinutes * 60;
        $clock->forceFill([
            'priority' => $priority,
            'target_seconds' => $targetSeconds,
            'warning_seconds' => SlaClock::warningSeconds($targetSeconds),
        ])->save();
    }

    private function recordBreachIfCrossed(SlaClock $clock, CarbonInterface $at): bool
    {
        if (! $clock->isActive() || $clock->elapsed_seconds < $clock->target_seconds || $clock->breached_at !== null) {
            return false;
        }

        $clock->forceFill([
            // A delayed evaluator may cross both boundaries in one pass.
            // Record the warning boundary as crossed, but send only the useful
            // present-tense breach alert. Reconciliation uses the same seam so
            // a longer or disabled replacement target cannot erase history.
            'warned_at' => $clock->warned_at ?? CarbonImmutable::instance($at),
            'breached_at' => CarbonImmutable::instance($at),
        ])->save();

        return true;
    }

    /** Serialize policy reads with account-wide policy reconciliation. */
    private function lockAccountPolicy(int $accountId): void
    {
        Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
    }

    private function alertCompletionColumn(string $stage): string
    {
        return match ($stage) {
            'warning' => 'warning_alerted_at',
            'breach' => 'breach_alerted_at',
            default => throw new \InvalidArgumentException('Unknown SLA alert stage.'),
        };
    }

    private function alertRecipientColumn(string $stage, string $channel): string
    {
        return match ([$stage, $channel]) {
            ['warning', 'database'] => 'warning_alerted_user_ids',
            ['warning', 'mail'] => 'warning_mail_alerted_user_ids',
            ['breach', 'database'] => 'breach_alerted_user_ids',
            ['breach', 'mail'] => 'breach_mail_alerted_user_ids',
            default => throw new \InvalidArgumentException('Unknown SLA alert handoff.'),
        };
    }
}
