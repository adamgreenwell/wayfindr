<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\ConversationMessageAttachment;
use App\Models\Site;
use App\Models\User;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Irreversibly destroys a site and everything recorded beneath it.
 *
 * Archiving is the operation an operator usually wants; this is the one for a
 * deletion obligation or a site created in error. Every relation cascades at the
 * database level - visitors, conversations, messages, tickets, cobrowse sessions
 * and the site's own audit events - so there is nothing to put back afterwards
 * except a restore from backup.
 */
class SitePurge
{
    public function __construct(
        private readonly SiteManagerCoverage $siteManagerCoverage,
    ) {}

    /**
     * Recheck every destructive precondition after serializing account changes.
     *
     * The controller's first policy and confirmation checks provide fast,
     * friendly feedback. These are the authoritative checks: role changes and
     * site edits use the same account-first lock order, so neither can land
     * between authorization and the irreversible cascade.
     *
     * @return array{conversations: int, tickets: int, visitors: int, attachments: int}
     */
    public function purgeAuthorized(Site $site, User $actor, string $confirmedName): array
    {
        return $this->performPurge($site, $actor, $confirmedName);
    }

    /**
     * Purge an already-authorized site from an internal workflow.
     *
     * HTTP callers must use purgeAuthorized(); this entry point remains for
     * internal lifecycle and cascade tests that deliberately exercise the
     * deletion primitive without pretending to be an authorization boundary.
     *
     * @return array{conversations: int, tickets: int, visitors: int, attachments: int}
     */
    public function purge(Site $site, User $actor): array
    {
        return $this->performPurge($site, $actor);
    }

    /**
     * @return array{conversations: int, tickets: int, visitors: int, attachments: int}
     */
    private function performPurge(Site $site, User $actor, ?string $confirmedName = null): array
    {
        [$summary, $storedFiles] = DB::transaction(function () use ($site, $actor, $confirmedName): array {
            $accountId = (int) $site->account_id;

            if ($confirmedName !== null) {
                $this->siteManagerCoverage->lockAccount($accountId);
                $actor = User::query()
                    ->whereKey($actor->id)
                    ->where('account_id', $accountId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $site = Site::query()
                    ->whereKey($site->id)
                    ->where('account_id', $accountId)
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless(Gate::forUser($actor)->allows('view', $site), 404);
                abort_unless(Gate::forUser($actor)->allows('purge', $site), 403);

                if (! $site->isArchived()) {
                    throw ValidationException::withMessages([
                        'confirm_name' => __('site_settings.validation.purge_archived'),
                    ]);
                }

                if ($confirmedName !== $site->name) {
                    throw ValidationException::withMessages([
                        'confirm_name' => __('site_settings.validation.purge_name'),
                    ]);
                }
            } else {
                $site = Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            }

            $summary = [
                'conversations' => $site->conversations()->count(),
                'tickets' => $site->tickets()->count(),
                'visitors' => $site->visitors()->count(),
                'attachments' => ConversationMessageAttachment::query()
                    ->where('site_id', $site->id)
                    ->count(),
            ];

            $siteName = $site->name;
            $siteDomain = $site->domain;
            $siteId = $site->id;

            // Collected before the rows go, because the cascade takes the only
            // record of where each binary lives.
            $storedFiles = ConversationMessageAttachment::query()
                ->where('site_id', $siteId)
                ->get(['id', 'storage_disk', 'storage_key']);

            // Written account-scoped with a null site_id on purpose: audit_events
            // cascades on site_id, so a site-scoped record of this purge would be
            // deleted by the very statement it exists to document.
            AuditEvent::create([
                'account_id' => $accountId,
                'site_id' => null,
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->id,
                'action' => 'site.purged',
                'metadata' => [
                    'site_id' => $siteId,
                    'site_name' => $siteName,
                    'site_domain' => $siteDomain,
                    'destroyed' => $summary,
                ],
                'occurred_at' => now(),
            ]);

            $site->delete();

            return [$summary, $storedFiles];
        });

        // Deliberately after the committed delete. A failure here leaves orphaned
        // binaries, which SweepOrphanedAttachmentsCommand already reaps on a
        // schedule (ADR 0007); doing it first would instead leave live rows
        // pointing at files that no longer exist, which nothing repairs. It also
        // means a request that times out mid-purge cannot corrupt anything.
        foreach ($storedFiles as $attachment) {
            $attachment->deleteStoredFile();
        }

        return $summary;
    }
}
