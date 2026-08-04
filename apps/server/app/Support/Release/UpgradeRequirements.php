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

                if ($settled['satisfied']) {
                    continue;
                }

                $outstanding[] = $action + ['satisfied_by' => $settled['by']];
            }
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
