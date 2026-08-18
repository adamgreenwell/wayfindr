<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\ConversationMessageAttachment;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
    /**
     * @return array{conversations: int, tickets: int, visitors: int, attachments: int}
     */
    public function purge(Site $site, User $actor): array
    {
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
        $accountId = $site->account_id;

        // Collected before the rows go, because the cascade takes the only
        // record of where each binary lives.
        $storedFiles = ConversationMessageAttachment::query()
            ->where('site_id', $siteId)
            ->get(['id', 'storage_disk', 'storage_key']);

        DB::transaction(function () use ($site, $actor, $siteId, $siteName, $siteDomain, $accountId, $summary): void {
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
