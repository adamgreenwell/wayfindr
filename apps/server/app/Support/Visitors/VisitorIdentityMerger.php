<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use App\Enums\AccountPermission;
use App\Events\VisitorPresenceUpdated;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorIdentityAlias;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Collapse one same-site visitor into the canonical contact an agent chose. */
final class VisitorIdentityMerger
{
    private const MAX_PREVIOUS_VISITOR_IDS = 50;

    public function merge(User $actor, Visitor $source, int $targetId): Visitor
    {
        [$target, $site, $sourceId] = DB::transaction(function () use ($actor, $source, $targetId): array {
            $accountId = (int) $actor->account_id;
            Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
            $actor = User::query()
                ->whereKey($actor->id)
                ->where('account_id', $accountId)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($actor->hasAccountPermission(AccountPermission::ManageContacts), 403);

            // Public widget paths that create visitor-owned rows take a shared
            // site lock before their final alias/visitor resolution. The
            // exclusive partner here makes the merge and those writes observe
            // one complete identity state or the other.
            $site = Site::query()
                ->whereKey($source->site_id)
                ->where('account_id', $accountId)
                ->lockForUpdate()
                ->firstOrFail();

            $visitors = Visitor::query()
                ->where('site_id', $site->id)
                ->whereIn('id', [(int) $source->id, $targetId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $source = $visitors->get((int) $source->id);
            $target = $visitors->get($targetId);

            abort_unless($source instanceof Visitor && $target instanceof Visitor, 404);
            abort_unless(Gate::forUser($actor)->allows('view', $source), 404);
            abort_unless(Gate::forUser($actor)->allows('view', $target), 404);

            if ((int) $source->id === (int) $target->id) {
                throw ValidationException::withMessages([
                    'target_id' => __('visitor_merge.errors.same_contact'),
                ]);
            }

            if ($this->hasConflictingExternalIds($source, $target)) {
                throw ValidationException::withMessages([
                    'target_id' => __('visitor_merge.errors.external_id_conflict'),
                ]);
            }

            if ($this->hasConflictingEmails($source, $target)) {
                throw ValidationException::withMessages([
                    'target_id' => __('visitor_merge.errors.email_conflict'),
                ]);
            }

            $mergedAttributes = $this->mergedAttributes($source, $target);
            $counts = $this->moveRelationships($source, $target, $site);

            // Delete only after every cascading relationship has moved. The
            // source browser ID already belongs to the target alias by then,
            // so an old tab has a durable path to the canonical row.
            $sourceId = (int) $source->id;
            $source->delete();

            DB::table('visitors')->where('id', $target->id)->update([
                ...$mergedAttributes,
                'presence_only' => false,
                'updated_at' => now(),
            ]);
            $target = Visitor::query()->whereKey($target->id)->firstOrFail();

            AuditEvent::query()->create([
                'account_id' => $accountId,
                'site_id' => $site->id,
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->id,
                'subject_type' => $target->getMorphClass(),
                'subject_id' => $target->id,
                'action' => 'visitor.merged',
                // Internal ids and moved-row counts only. Identity and note
                // values stay on the contact instead of being copied into the
                // longer-lived audit trail.
                'metadata' => [
                    'source_visitor_id' => $sourceId,
                    'destination_visitor_id' => (int) $target->id,
                    'moved' => $counts,
                ],
                'occurred_at' => now(),
            ]);

            return [$target, $site, $sourceId];
        });

        // The source row can already be rendered on an agent's live board.
        // Announce only after the identity transaction has committed so no
        // subscriber can observe the destination before its relationships and
        // aliases move. Realtime failure never undoes the durable merge; the
        // periodic board resync remains the fallback.
        try {
            event(new VisitorPresenceUpdated($site, $target, $sourceId));
        } catch (\Throwable $e) {
            report($e);
        }

        return $target;
    }

    /** @return array<string, int> */
    private function moveRelationships(Visitor $source, Visitor $target, Site $site): array
    {
        $sourceId = (int) $source->id;
        $targetId = (int) $target->id;
        $visitorType = $source->getMorphClass();

        $aliases = VisitorIdentityAlias::query()
            ->where('site_id', $site->id)
            ->where(function ($query) use ($source, $sourceId): void {
                $query->where('visitor_id', $sourceId);

                if ($source->anonymous_id !== null) {
                    $query->orWhere('anonymous_id', $source->anonymous_id);
                }
            })
            ->lockForUpdate()
            ->get();
        $sourceIdAlias = $source->anonymous_id === null
            ? null
            : $aliases->firstWhere('anonymous_id', $source->anonymous_id);

        if ($sourceIdAlias instanceof VisitorIdentityAlias && ! in_array((int) $sourceIdAlias->visitor_id, [$sourceId, $targetId], true)) {
            throw ValidationException::withMessages([
                'target_id' => __('visitor_merge.errors.alias_conflict'),
            ]);
        }

        if ($source->anonymous_id !== null) {
            $sourceIdAlias ??= new VisitorIdentityAlias([
                'site_id' => $site->id,
                'anonymous_id' => $source->anonymous_id,
            ]);

            $sourceIdAlias->forceFill([
                'visitor_id' => $targetId,
                'previous_visitor_ids' => $this->appendPreviousVisitorId($sourceIdAlias, $sourceId),
            ])->save();
        }

        foreach ($aliases->where('visitor_id', $sourceId) as $alias) {
            if ($sourceIdAlias instanceof VisitorIdentityAlias && (int) $alias->id === (int) $sourceIdAlias->id) {
                continue;
            }

            $alias->forceFill([
                'visitor_id' => $targetId,
                'previous_visitor_ids' => $this->appendPreviousVisitorId($alias, $sourceId),
            ])->save();
        }

        return [
            'conversations' => DB::table('conversations')->where('visitor_id', $sourceId)->update(['visitor_id' => $targetId]),
            'tickets' => DB::table('tickets')->where('requester_id', $sourceId)->update(['requester_id' => $targetId]),
            'cobrowse_sessions' => DB::table('cobrowse_sessions')->where('visitor_id', $sourceId)->update(['visitor_id' => $targetId]),
            'contact_notes' => DB::table('visitor_notes')->where('visitor_id', $sourceId)->update(['visitor_id' => $targetId]),
            'messages' => DB::table('conversation_messages')
                ->where('sender_type', $visitorType)
                ->where('sender_id', $sourceId)
                ->update(['sender_id' => $targetId]),
            'attachments' => DB::table('conversation_message_attachments')
                ->where('uploaded_by_type', $visitorType)
                ->where('uploaded_by_id', $sourceId)
                ->update(['uploaded_by_id' => $targetId]),
            // Audit facts stay append-only: action, actor/subject type, time,
            // and metadata do not change. Re-anchor only the polymorphic row
            // id so the same person remains resolvable and searchable after
            // the duplicate row is deleted.
            'audit_subjects' => DB::table('audit_events')
                ->where('subject_type', $visitorType)
                ->where('subject_id', $sourceId)
                ->update(['subject_id' => $targetId]),
            'audit_actors' => DB::table('audit_events')
                ->where('actor_type', $visitorType)
                ->where('actor_id', $sourceId)
                ->update(['actor_id' => $targetId]),
        ];
    }

    /** @return array<string, mixed> */
    private function mergedAttributes(Visitor $source, Visitor $target): array
    {
        $sourceMetadata = is_array($source->metadata) ? $source->metadata : [];
        $targetMetadata = is_array($target->metadata) ? $target->metadata : [];
        $metadata = array_replace_recursive($sourceMetadata, $targetMetadata);
        $sourceHasLatestWebSighting = $this->isAfter($source->last_web_seen_at, $target->last_web_seen_at);

        if ($sourceHasLatestWebSighting && array_key_exists('last_page_url', $sourceMetadata)) {
            $metadata['last_page_url'] = $sourceMetadata['last_page_url'];
        }

        return [
            'external_id' => $this->firstFilled($target->external_id, $source->external_id),
            'name' => $this->firstFilled($target->name, $source->name),
            'email' => $this->firstFilled($target->email, $source->email),
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'last_seen_at' => $this->later($source->last_seen_at, $target->last_seen_at),
            'last_web_seen_at' => $this->later($source->last_web_seen_at, $target->last_web_seen_at),
            'current_visit_started_at' => $sourceHasLatestWebSighting
                ? $source->current_visit_started_at
                : $target->current_visit_started_at,
            'created_at' => $this->earlier($source->created_at, $target->created_at),
        ];
    }

    private function hasConflictingExternalIds(Visitor $source, Visitor $target): bool
    {
        return $this->isFilled($source->external_id)
            && $this->isFilled($target->external_id)
            && ! hash_equals((string) $source->external_id, (string) $target->external_id);
    }

    private function hasConflictingEmails(Visitor $source, Visitor $target): bool
    {
        return $this->isFilled($source->email)
            && $this->isFilled($target->email)
            // Inbound mail resolves a visitor case-insensitively by email.
            // Reject only addresses that would take different routing paths.
            && mb_strtolower(trim((string) $source->email)) !== mb_strtolower(trim((string) $target->email));
    }

    /** @return array<int, int> */
    private function appendPreviousVisitorId(VisitorIdentityAlias $alias, int $visitorId): array
    {
        return collect(is_array($alias->previous_visitor_ids) ? $alias->previous_visitor_ids : [])
            ->map(fn ($id): int => (int) $id)
            ->push($visitorId)
            ->unique()
            ->take(-self::MAX_PREVIOUS_VISITOR_IDS)
            ->values()
            ->all();
    }

    private function firstFilled(mixed $preferred, mixed $fallback): ?string
    {
        if ($this->isFilled($preferred)) {
            return (string) $preferred;
        }

        return $this->isFilled($fallback) ? (string) $fallback : null;
    }

    private function isFilled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function isAfter(?CarbonInterface $left, ?CarbonInterface $right): bool
    {
        return $left !== null && ($right === null || $left->gt($right));
    }

    private function later(?CarbonInterface $left, ?CarbonInterface $right): ?CarbonInterface
    {
        return $this->isAfter($left, $right) ? $left : $right;
    }

    private function earlier(?CarbonInterface $left, ?CarbonInterface $right): ?CarbonInterface
    {
        if ($left === null) {
            return $right;
        }

        return $right === null || $left->lt($right) ? $left : $right;
    }
}
