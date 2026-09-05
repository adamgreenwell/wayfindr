<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AccountPermission;
use App\Events\AgentAlertStored;
use App\Models\Account;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Broadcast a stored alert only after its database transaction is durable. */
final class AgentAlertBroadcaster
{
    public function stored(User $recipient, DatabaseNotification $notification): void
    {
        $accountId = $recipient->account_id;
        $agentId = (int) $recipient->id;
        $notificationId = (string) $notification->id;
        $notifiableType = $recipient->getMorphClass();

        if ($accountId === null
            || (string) $notification->notifiable_type !== $notifiableType
            || (int) $notification->notifiable_id !== $agentId) {
            return;
        }

        // Derive publication metadata from the CURRENT durable payload while
        // holding its row lock. Concurrent refresh callbacks may carry stale
        // model instances; only the first callback to record the stored state
        // gets a version and broadcast. Generic read/email writes do not alter
        // the alert-bearing fingerprint and therefore remain silent.
        $marked = DB::transaction(function () use ($agentId, $notificationId, $notifiableType): bool {
            $current = DatabaseNotification::query()
                ->whereKey($notificationId)
                ->where('notifiable_type', $notifiableType)
                ->where('notifiable_id', $agentId)
                ->lockForUpdate()
                ->first();

            if (! $current instanceof DatabaseNotification) {
                return false;
            }

            $fingerprint = AgentAlertPublicationFingerprint::for($current->data);
            $recordedFingerprint = $current->getAttribute('agent_alert_fingerprint');

            if (is_string($recordedFingerprint)
                && hash_equals($recordedFingerprint, $fingerprint)) {
                return false;
            }

            DB::table('notifications')->where('id', $notificationId)->update([
                'agent_alerted_at' => now(),
                'agent_alert_version' => (string) Str::uuid(),
                'agent_alert_fingerprint' => $fingerprint,
            ]);

            return true;
        });

        if (! $marked) {
            return;
        }

        DB::afterCommit(function () use ($accountId, $agentId, $notificationId, $notifiableType): void {
            try {
                DB::transaction(function () use ($accountId, $agentId, $notificationId, $notifiableType): void {
                    // The same account-then-user order used by role, site-access
                    // and deactivation writers. Whichever transaction gets the
                    // account lock first defines reality: either this event is
                    // sent while access is still valid, or the writer commits
                    // first and the checks below suppress it. The network send
                    // remains inside the lock boundary so a revocation cannot
                    // commit between authorization and publication.
                    $account = Account::query()
                        ->whereKey($accountId)
                        ->lockForUpdate()
                        ->first();

                    if (! $account instanceof Account) {
                        return;
                    }

                    $currentRecipient = User::query()
                        ->whereKey($agentId)
                        ->where('account_id', $account->id)
                        ->lockForUpdate()
                        ->first();
                    $currentNotification = DatabaseNotification::query()
                        ->whereKey($notificationId)
                        ->where('notifiable_type', $notifiableType)
                        ->where('notifiable_id', $agentId)
                        ->lockForUpdate()
                        ->first();

                    if (! $currentRecipient instanceof User
                        || $currentRecipient->isDeactivated()
                        || ! $currentRecipient->hasAccountPermission(AccountPermission::ViewAlerts)
                        || ! $currentNotification instanceof DatabaseNotification
                        || ! Gate::forUser($currentRecipient)->allows('view', $currentNotification)) {
                        return;
                    }

                    AgentAlertStored::dispatch($currentRecipient, $currentNotification);
                });
            } catch (Throwable $exception) {
                // The database alert is already durable and remains the source
                // of truth. A realtime outage must not turn that success into a
                // failed visitor message or ticket operation.
                Log::warning('Agent alert stored, but its realtime broadcast failed.', [
                    'account_id' => $accountId,
                    'agent_id' => $agentId,
                    'notification_id' => $notificationId,
                    'exception' => $exception->getMessage(),
                ]);
            }
        });
    }
}
