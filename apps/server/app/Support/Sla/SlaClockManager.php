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
        $conversation->loadMissing('site.account');
        $start = CarbonImmutable::instance($startedAt ?? $conversation->created_at ?? now());

        if (! $conversation->messages()->where('sender_type', User::class)->exists()) {
            $this->start($conversation, SlaClock::METRIC_FIRST_RESPONSE, $start);
        }

        if ($conversation->status !== 'closed') {
            $this->start($conversation, SlaClock::METRIC_RESOLUTION, $start);
        }
    }

    public function startTicket(Ticket $ticket, ?CarbonInterface $startedAt = null): void
    {
        $ticket->loadMissing('site.account');

        if ($ticket->status !== 'closed') {
            $this->start(
                $ticket,
                SlaClock::METRIC_RESOLUTION,
                CarbonImmutable::instance($startedAt ?? $ticket->created_at ?? now()),
            );
        }
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
            $this->reconcileSubject($conversation, now());
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
            $this->reconcileSubject($ticket, now());
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

        $to = CarbonImmutable::instance($at);
        $from = CarbonImmutable::instance($clock->last_counted_at);

        if ($to->lessThanOrEqualTo($from)) {
            return $clock;
        }

        $clock->loadMissing('site');
        $clock->forceFill([
            'elapsed_seconds' => $clock->elapsed_seconds + SiteAvailability::elapsedOpenSeconds($clock->site, $from, $to),
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
            $clock = SlaClock::query()->with(['site', 'subject'])->lockForUpdate()->findOrFail($clockId);

            $this->advance($clock, $at);
            $stage = null;

            if ($this->recordBreachIfCrossed($clock, $at)) {
                $stage = 'breach';
            } elseif ($recordWarning && $clock->isActive() && $clock->elapsed_seconds >= $clock->warning_seconds && $clock->warned_at === null) {
                $clock->forceFill(['warned_at' => CarbonImmutable::instance($at)])->save();
                $stage = 'warning';
            }

            return ['clock' => $clock->fresh(['site', 'subject']), 'stage' => $stage];
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
}
