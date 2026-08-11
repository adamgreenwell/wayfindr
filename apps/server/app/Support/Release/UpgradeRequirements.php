<?php

declare(strict_types=1);

namespace App\Support\Release;

use App\Support\Version\VersionComparator;

/**
 * Which declared actions an upgrade still owes, given where it started.
 *
 * Pure: it is handed the history, the starting version and the acknowledgements,
 * and returns an assessment. Reading files, evaluating machine checks and
 * deciding what to do about the answer all live elsewhere, because this is the
 * part whose rules are subtle enough to need testing in isolation.
 *
 * The rules come from ADR 0013.
 */
final class UpgradeRequirements
{
    /** Phases whose unmet actions may block the migration. */
    public const BLOCKS_MIGRATION = ['before-pull', 'after-pull'];

    /** Phases whose unmet actions gate serving instead, after migration. */
    public const BLOCKS_SERVING = ['after-start'];

    /**
     * Whether an action can never be performed on this upgrade, whatever its
     * phase, because the release it belongs to is skipped past.
     *
     * An action needing its OWN release's code or schema is unperformable in a
     * direct jump: `before-pull` has the old release, and `after-pull` and
     * `after-start` both have the target. The only way to satisfy it is to stop
     * at the release it belongs to.
     *
     * This has to block MIGRATION even when the phase says otherwise. A stranded
     * `after-start` action would otherwise let the migration through and gate
     * serving afterwards — leaving the install migrated, refusing traffic, and
     * facing a requirement that cannot be met at all. Refusing first leaves the
     * previous release running and the operator able to step through.
     */
    public static function stranded(array $action, ?string $target): bool
    {
        return $target !== null
            && ($action['release'] ?? null) !== $target
            && in_array($action['depends_on_release'] ?? 'none', ['code', 'schema'], true);
    }

    /**
     * What this upgrade can still do about the action — the single answer every
     * other site derives its behaviour from.
     *
     * This is the consolidation #647 asked for. The same three states were
     * already being computed in six places from two overlapping booleans, and
     * `stranded` meant different things in two of them: the installer's local
     * variable is cleared for the middle case while `stranded()` below is not.
     * Everything that needs to know now asks here.
     *
     * The two questions underneath must stay apart, and conflating them is how
     * the guard got this wrong twice in opposite directions:
     *
     * - `stranded()` asks whether the work can be performed on this jump, and is
     *   answered by the code PRESENT — always the target's, because the pull has
     *   happened.
     * - `reached()` asks whether the operator could credibly have done it
     *   already, and is answered by where the install has actually been.
     */
    public static function disposition(array $action, ?string $target, ?string $current): ActionDisposition
    {
        if (! self::stranded($action, $target)) {
            return ActionDisposition::Performable;
        }

        // The action's own release is the one running, so its code is here until
        // the pull. The work is possible now, and an acknowledgement afterwards
        // is a claim about the past that could be true.
        if (self::reached($action, $current)) {
            return ActionDisposition::PerformableNow;
        }

        return ActionDisposition::Unreachable;
    }

    /**
     * Whether nothing the operator can say will settle this action.
     *
     * Retained as the narrow question some callers genuinely want; it is now
     * derived from `disposition()` so it cannot drift from it. Prefer
     * `ActionAdvice::for()` at any site that also has to say something about the
     * action — the two travelled separately for four releases and disagreed.
     */
    public static function unacknowledgeable(array $action, ?string $target, ?string $current): bool
    {
        return ! self::disposition($action, $target, $current)->acknowledgeable();
    }

    /**
     * Whether an ACKNOWLEDGEMENT of a stranded action is credible.
     *
     * These are two different questions and conflating them is how the guard got
     * this wrong twice, in opposite directions.
     *
     * "Stranded" asks whether the work can be performed on this jump, and it is
     * answered by the code that is present — which, everywhere the artifact runs,
     * is the TARGET's, because the pull has already happened. A `0.2.0` action
     * needing `0.2.0` code cannot be done once `0.3.0` is installed, whatever the
     * install used to be running.
     *
     * This asks something narrower: could the operator credibly have done it
     * ALREADY? Yes, if the install actually ran that release — the code was there
     * before the pull, and the attestation is about the past. No, if the upgrade
     * skipped it, because the operator never had that code at all and no
     * attestation about it can be true.
     *
     * So an unacknowledged prior-release action still blocks, and an acknowledged
     * one settles.
     */
    public static function reached(array $action, ?string $current): bool
    {
        $release = $action['release'] ?? null;

        if ($current === null || ! is_string($release)) {
            return false;
        }

        // EQUALITY ONLY. Version ordering does not prove traversal, because
        // direct jumps are supported and normal: a restored 0.4 install that
        // originally went 0.1 -> 0.4 never ran 0.2, and `0.2 <= 0.4` would have
        // accepted an acknowledgement for 0.2 work it could never have done.
        //
        // The recorded release is the one piece of evidence that holds: it is
        // what is installed, so its code was present. Anything older needs proof
        // of traversal that nothing here has — `satisfied_through` is the only
        // candidate and it is deliberately unknown after a restore, which is
        // exactly the case that would be wrong.
        return $release === $current;
    }

    /**
     * Collect the actions in `(from, target]` that are still outstanding.
     *
     * `$from` is null for an upgrade whose starting point cannot be recovered —
     * a legacy install with no state file. That is NOT treated as "nothing to
     * do": the whole history is evaluated instead, because the alternative is
     * silence for precisely the installs with the furthest to travel.
     *
     * @param  list<array<string, mixed>>  $history  published manifests, any order
     * @param  list<string>  $acknowledged  `<release>/<action-id>` entries
     * @param  callable(string): ?bool  $evaluateCheck  runs a named machine check;
     *                                                  null means "cannot evaluate"
     * @return list<array<string, mixed>> outstanding actions, each with its
     *                                    `release`, plus `satisfied_by` describing
     *                                    how it was (or was not) settled
     */
    public static function outstanding(
        array $history,
        ?string $from,
        string $target,
        array $acknowledged,
        callable $evaluateCheck,
        bool $freshInstall = false,
        bool $includeTarget = false,
        ?string $traversedFrom = null,
    ): array {
        // A genuinely fresh install has not upgraded from anywhere. Passing its
        // null start into the legacy path would evaluate the entire history, so a
        // new install of a later image could be blocked by upgrade-only work from
        // releases it never ran.
        if ($freshInstall) {
            return [];
        }

        $outstanding = [];

        foreach (self::span($history, $from, $target, $includeTarget) as $manifest) {
            $origin = self::applicabilityOrigin($manifest['version'] ?? null, $from, $traversedFrom);

            foreach ($manifest['actions'] ?? [] as $action) {
                if (! self::applies($action, $origin, $evaluateCheck)) {
                    continue;
                }

                $settled = self::settled($action, $acknowledged, $evaluateCheck);

                // An acknowledgement cannot settle an UNREACHABLE action: the
                // operator never had that release's code, so no attestation about
                // it can be true, and the refusal printing a key beside every
                // action would otherwise document the bypass it is warning
                // against.
                //
                // It CAN settle one belonging to a release the install actually
                // ran. The code was there before the pull, so the work was
                // performable then and the attestation is about the past.
                //
                // A machine check is different again: if it answers true then the
                // thing exists, whatever route it took to get there.
                //
                // Asked through disposition() so the settlement and the messages
                // cannot disagree about what an acknowledgement is worth — they
                // did, twice, and each time the preflight said "clear" while the
                // artifact refused the same acknowledgement and exited 78.
                $bypassed = $settled['by'] === 'acknowledged'
                    && ! self::disposition($action, $target, $traversedFrom)->acknowledgeable();

                if ($settled['satisfied'] && ! $bypassed) {
                    continue;
                }

                $outstanding[] = $action + ['satisfied_by' => $settled['by']];
            }
        }

        return $outstanding;
    }

    /**
     * Collect the advisory notices in `(from, target]` that are still unmet.
     *
     * Notices are ADR 0013's third response: reported wherever an operator meets
     * them, never blocking. They share this class's span and applicability rules
     * — the same "which releases does this upgrade traverse, and does this apply
     * to an install starting there" questions — and none of its blocking
     * machinery, because there is no blocking to do.
     *
     * Three differences from `outstanding()`, each following from never blocking:
     *
     * - No disposition. Strandedness decides whether something can block; a
     *   notice cannot, and advisory work must be performable at any time anyway
     *   (see `ReleaseManifest::validateNotices`).
     * - The target's notices are ALWAYS in scope. An action's are held back until
     *   the release is recorded, because an action is about the upgrade; a notice
     *   is about the install's ongoing state, and the running release's advice
     *   applies while it is running.
     * - An acknowledgement settles one outright. There is no bypass to guard
     *   against when nothing was being blocked.
     *
     * @param  list<array<string, mixed>>  $history  published manifests, any order
     * @param  list<string>  $acknowledged  `<release>/<id>` entries
     * @param  callable(string): ?bool  $evaluateCheck
     * @return list<array<string, mixed>> unmet notices, each with `satisfied_by`
     */
    public static function outstandingNotices(
        array $manifest,
        array $acknowledged,
        callable $evaluateCheck,
    ): array {
        $outstanding = [];

        // THE RUNNING RELEASE'S OWN NOTICES, AND NOTHING ELSE. No span, no
        // origin, no freshness.
        //
        // The first version of this evaluated a span like actions do, and that
        // was wrong twice over. An action belongs to an UPGRADE: it is work owed
        // because of a particular hop, so where the hop started decides whether
        // it applies. A notice belongs to the INSTALL: it is advice about how the
        // release now running wants to be operated, and the upgrade that got here
        // has no bearing on whether a backups worker should exist.
        //
        // Reading a span produced two defects, both found in review:
        //
        //  - `upgrade-from` applicability was measured against an origin that
        //    stops meaning "where the upgrade started" the moment the release is
        //    recorded, so a retirement notice correctly skipped during the
        //    upgrade reappeared immediately afterwards, and on fresh installs.
        //  - An unmet notice from an intermediate release vanished after
        //    migration, because `satisfied_through` advances on ACTIONS alone and
        //    the next span became (target, target].
        //
        // Reading only the target's manifest removes both, and makes the
        // published carry-over rule the mechanism rather than a workaround: a
        // notice that still applies is re-declared by the next release (the
        // release reset deliberately does not clear `notices`), and one that is
        // removed stops being reported. See RELEASING.md and
        // docs/self-hosting/release-manifest.md.
        foreach ($manifest['notices'] ?? [] as $notice) {
            // Origin is passed as null because there is none: `upgrade-from` is
            // rejected for notices at validation, so the only applicability
            // types that reach here are `always` and `state`, neither of which
            // consults it.
            if (! self::applies($notice, null, $evaluateCheck)) {
                continue;
            }

            $settled = self::settled($notice, $acknowledged, $evaluateCheck);

            if ($settled['satisfied']) {
                continue;
            }

            $outstanding[] = $notice + ['satisfied_by' => $settled['by']];
        }

        return $outstanding;
    }

    /**
     * The manifests an upgrade actually traverses: everything after the starting
     * version, up to and including the target.
     *
     * A declaration describes its own release, so an install on v1 upgrading to
     * v3 receives v2's changes as well. Reading only the target's declaration
     * misses exactly the several-releases-behind case this exists to catch.
     *
     * @param  list<array<string, mixed>>  $history
     * @return list<array<string, mixed>>
     */
    public static function span(
        array $history,
        ?string $from,
        string $target,
        bool $includeTarget = false,
    ): array {
        $span = array_filter($history, static function (array $manifest) use ($from, $target, $includeTarget): bool {
            $version = $manifest['version'] ?? null;

            if (! is_string($version)) {
                return false;
            }

            // Above the target is a different upgrade's problem.
            $toTarget = VersionComparator::compare($version, $target);

            if ($toTarget !== null && $toTarget > 0) {
                return false;
            }

            // The running release's own requirements stay in force until they
            // are satisfied, however the install got here. Without this the
            // serving gate dies the moment the release is recorded: the span
            // becomes (target, target], which is empty, and unmet after-start
            // actions vanish rather than being enforced.
            if ($includeTarget && $version === $target) {
                return true;
            }

            // Unknown start: take the lot. Also covers the case where precedence
            // has no answer — a development version on either side — where
            // excluding would be a guess in the unsafe direction.
            if ($from === null) {
                return true;
            }

            $fromStart = VersionComparator::compare($version, $from);

            return $fromStart === null || $fromStart > 0;
        });

        return array_values($span);
    }

    /**
     * Does this action apply to an upgrade that began at `$from`?
     *
     * @param  array<string, mixed>  $action
     */
    /**
     * Which starting point an action's `upgrade-from` is measured against.
     *
     * The span can reach further back than this upgrade actually started, because
     * an unmet after-start action from an earlier one holds it open. The two
     * halves answer different questions and must not share an origin:
     *
     * - the RETAINED half is debt from a previous upgrade, and its applicability
     *   was settled by where THAT upgrade began
     * - anything above the release this install is running is newly traversed,
     *   and its applicability is settled by where the install is now
     *
     * Sharing one origin fails open in the worst direction. An install that ran
     * v3 and still owes v2 work would evaluate a v4 action retiring v3's state
     * against v1, conclude the install never had that state, and skip it — the
     * retirement silently dropped on exactly the install that needs it.
     */
    private static function applicabilityOrigin(?string $release, ?string $from, ?string $traversedFrom): ?string
    {
        if ($traversedFrom === null || ! is_string($release)) {
            return $from;
        }

        $rank = VersionComparator::compare($release, $traversedFrom);

        // An undecidable rank keeps the wider origin, which over-applies rather
        // than under-applies.
        return $rank !== null && $rank > 0 ? $traversedFrom : $from;
    }

    public static function applies(array $action, ?string $from, ?callable $evaluateCheck = null): bool
    {
        $applicability = $action['applicability'] ?? ['type' => 'always'];
        $type = $applicability['type'] ?? 'always';

        if ($type === 'always') {
            return true;
        }

        if ($type === 'upgrade-from') {
            // Retirement: undo something only an install that ran the earlier
            // release ever created. An unknown start cannot be excluded — it may
            // well have run it — so it is treated as applying.
            if ($from === null) {
                return true;
            }

            $min = $applicability['min'] ?? null;

            if (! is_string($min)) {
                return true;
            }

            $comparison = VersionComparator::compare($from, $min);

            return $comparison === null || $comparison >= 0;
        }

        if ($type === 'state') {
            $result = $evaluateCheck === null
                ? null
                : $evaluateCheck((string) ($applicability['check'] ?? ''));

            // Applies unless the check positively says otherwise. "Cannot tell"
            // must not silently drop a requirement — but a check that answers
            // false has demonstrated the action is irrelevant to this install,
            // and demanding it anyway would make an operator acknowledge work
            // that does not apply to them.
            return $result !== false;
        }

        return true;
    }

    /**
     * Has this action been satisfied, and by what?
     *
     * @param  array<string, mixed>  $action
     * @param  list<string>  $acknowledged
     * @param  callable(string): ?bool  $evaluateCheck
     * @return array{satisfied: bool, by: string}
     */
    private static function settled(array $action, array $acknowledged, callable $evaluateCheck): array
    {
        $verification = $action['verification'] ?? ['type' => 'attest'];

        if (($verification['type'] ?? 'attest') === 'check') {
            $result = $evaluateCheck((string) ($verification['check'] ?? ''));

            // A check that cannot be evaluated is NOT a pass. The whole point of
            // preferring checks is that they are evidence; absent evidence, the
            // action is outstanding and the operator can still acknowledge it.
            if ($result === true) {
                return ['satisfied' => true, 'by' => 'verified'];
            }

            if (self::isAcknowledged($action, $acknowledged)) {
                return ['satisfied' => true, 'by' => 'acknowledged'];
            }

            return ['satisfied' => false, 'by' => $result === null ? 'unevaluable' : 'failed'];
        }

        if (self::isAcknowledged($action, $acknowledged)) {
            return ['satisfied' => true, 'by' => 'acknowledged'];
        }

        return ['satisfied' => false, 'by' => 'unacknowledged'];
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  list<string>  $acknowledged
     */
    private static function isAcknowledged(array $action, array $acknowledged): bool
    {
        $needle = ($action['release'] ?? '').'/'.($action['id'] ?? '');

        return in_array($needle, $acknowledged, true);
    }

    /**
     * Parse `WAYFINDR_ACKNOWLEDGED_ACTIONS`.
     *
     * @return list<string>
     */
    public static function parseAcknowledged(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $entries = array_map(trim(...), explode(',', $raw));

        return array_values(array_filter($entries, static fn (string $e): bool => $e !== ''));
    }
}
