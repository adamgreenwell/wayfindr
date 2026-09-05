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
        /** @var array{fingerprint: string, version: string}|null $claim */
        $claim = DB::transaction(function () use ($agentId, $notificationId, $notifiableType): ?array {
            $current = DatabaseNotification::query()
                ->whereKey($notificationId)
                ->where('notifiable_type', $notifiableType)
                ->where('notifiable_id', $agentId)
                ->lockForUpdate()
                ->first();

            if (! $current instanceof DatabaseNotification) {
                return null;
            }

            $fingerprint = AgentAlertPublicationFingerprint::for($current->data);
            $recordedFingerprint = $current->getAttribute('agent_alert_fingerprint');
            $recordedVersion = $current->getAttribute('agent_alert_version');
            $fingerprintMatches = is_string($recordedFingerprint)
                && hash_equals($recordedFingerprint, $fingerprint);
            $publicationAlreadyVersioned = $fingerprintMatches
                && is_string($recordedVersion)
                && $recordedVersion !== '';
            $version = $publicationAlreadyVersioned
                ? $recordedVersion
                : (blank($recordedFingerprint) ? $notificationId : (string) Str::uuid());
            $claimedVersion = $current->getAttribute('agent_alert_broadcast_claim_version');

            if (is_string($claimedVersion)
                && hash_equals($claimedVersion, $version)) {
                return null;
            }

            $updates = ['agent_alert_broadcast_claim_version' => $version];

            if (! $publicationAlreadyVersioned) {
                // Before the first claim, reconciliation deliberately exposes
                // the notification ID as its stable fallback version. Keep that
                // same version so catch-up and live delivery deduplicate.
                $updates = [
                    ...$updates,
                    'agent_alerted_at' => now(),
                    'agent_alert_version' => $version,
                    'agent_alert_fingerprint' => $fingerprint,
                ];
            }

            DB::table('notifications')->where('id', $notificationId)->update($updates);

            return [
                'fingerprint' => $fingerprint,
                'version' => $version,
            ];
        });

        if ($claim === null) {
            return;
        }

        DB::afterCommit(function () use ($accountId, $agentId, $claim, $notificationId, $notifiableType): void {
            try {
                DB::transaction(function () use ($accountId, $agentId, $claim, $notificationId, $notifiableType): void {
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

                    $currentFingerprint = $currentNotification instanceof DatabaseNotification
                        ? $currentNotification->getAttribute('agent_alert_fingerprint')
                        : null;
                    $currentVersion = $currentNotification instanceof DatabaseNotification
                        ? $currentNotification->getAttribute('agent_alert_version')
                        : null;
                    $currentClaimVersion = $currentNotification instanceof DatabaseNotification
                        ? $currentNotification->getAttribute('agent_alert_broadcast_claim_version')
                        : null;

                    if (! $currentRecipient instanceof User
                        || $currentRecipient->isDeactivated()
                        || ! $currentRecipient->hasAccountPermission(AccountPermission::ViewAlerts)
                        || ! $currentNotification instanceof DatabaseNotification
                        || ! is_string($currentFingerprint)
                        || ! hash_equals($claim['fingerprint'], $currentFingerprint)
                        || ! hash_equals($claim['fingerprint'], AgentAlertPublicationFingerprint::for($currentNotification->data))
                        || ! is_string($currentVersion)
                        || ! hash_equals($claim['version'], $currentVersion)
                        || ! is_string($currentClaimVersion)
                        || ! hash_equals($claim['version'], $currentClaimVersion)
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
