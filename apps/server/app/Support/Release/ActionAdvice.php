<?php

declare(strict_types=1);

namespace App\Support\Release;

/**
 * Everything an operator should be told about one outstanding action, decided
 * once and printed by whoever is doing the telling.
 *
 * This is the helper #647 asked for after #648: "a single helper answering what
 * should the operator be told about this action, and will an acknowledgement
 * clear it, consumed by all the message sites". Before it, the answer was
 * recomputed from two overlapping booleans at each site, and nearly every review
 * round fixed a subset — twice fixing a message in one file and not its twin.
 *
 * The readers are the migration refusal
 * (`BlockMigrationsWithUnmetRequirements`), the report command
 * (`UpgradeGuardCommand`) and the serving refusal
 * (`RefuseServingWithUnmetRequirements`). The console readers render Symfony
 * emphasis while the plain-text HTTP response strips those tags; none gets to
 * reword the underlying advice.
 *
 * Deliberately a value object rather than a formatted string. A site that needs
 * to arrange these differently — an operator console panel, say, or the advisory
 * severity ADR 0013 leaves the door open to — reads the parts rather than
 * parsing prose back apart.
 */
final readonly class ActionAdvice
{
    /**
     * @param  list<string>  $remedyLines
     */
    public function __construct(
        public ActionDisposition $disposition,
        public ?string $acknowledgeKey,
        public ?string $unreachableLead,
        public ?string $unreachableDetail,
        public array $remedyLines,
        public bool $blocksMigration,
    ) {}

    /**
     * @param  array<string, mixed>  $action
     * @param  ?string  $target  the release being upgraded TO
     * @param  ?string  $current  the release this install is recorded as running
     */
    public static function for(array $action, ?string $target, ?string $current): self
    {
        $disposition = UpgradeRequirements::disposition($action, $target, $current);
        $release = is_string($action['release'] ?? null) ? $action['release'] : null;
        $id = is_string($action['id'] ?? null) ? $action['id'] : null;
        $phase = is_string($action['phase'] ?? null) ? $action['phase'] : '';

        // The key comes first at both sites, and only when saying so would
        // actually settle the action. Offering it for work belonging to a release
        // the upgrade SKIPPED would document the bypass the refusal exists to
        // warn about.
        // A failed check is a definite negative answer. There is no honest
        // attestation past it, so do not print a key that settlement will reject.
        $checkFailed = ($action['satisfied_by'] ?? null) === 'failed';
        $key = ! $checkFailed && $disposition->acknowledgeable() && $release !== null && $id !== null
            ? $release.'/'.$id
            : null;

        // The recovery is carried for EVERY action out of reach of the running
        // code, not only the ones no acknowledgement can clear. An operator who
        // has not done the work cannot do it now either — the code it needs was
        // replaced by the pull — so a key on its own leaves them holding an
        // instruction they cannot follow.
        $lead = null;
        $detail = null;
        $remedy = [];

        if ($disposition->needsItsOwnRelease()) {
            $lead = 'Cannot be done now.';
            $detail = sprintf(
                'It needs %s, whose code this upgrade replaced.',
                $release ?? 'an intermediate release',
            );

            $remedy = match (true) {
                $checkFailed && $disposition === ActionDisposition::PerformableNow => [
                    'Roll back to that release, complete the work there, and upgrade again.',
                ],
                $checkFailed => [
                    'Install that release first, let it start, complete the work there, then upgrade again.',
                ],
                $disposition->acknowledgeable() => [
                    'If you did it before upgrading, acknowledge it with the key above.',
                    'If not, roll back to that release, do it there, and upgrade again.',
                ],
                default => [
                    'Install that release first, let it start, then upgrade again.',
                    'Acknowledging will not clear this: the work is unreachable, not undone.',
                ],
            };
        }

        if ($checkFailed) {
            $remedy[] = 'The machine check failed; an acknowledgement will not clear it.';
        }

        return new self(
            disposition: $disposition,
            acknowledgeKey: $key,
            unreachableLead: $lead,
            unreachableDetail: $detail,
            remedyLines: $remedy,
            blocksMigration: $disposition->blocksMigration($phase),
        );
    }

    /**
     * Whether the shared footer — "at least one step belongs to a release this
     * upgrade skips over" — speaks for this action.
     *
     * Only unreachable work reaches it. Counting every out-of-reach action there
     * told an operator holding a usable key that no acknowledgement could
     * substitute: two contradictory remedies in one refusal, the second costing a
     * needless rollback. That was #649.
     */
    public function countsTowardFooter(): bool
    {
        return ! $this->disposition->acknowledgeable();
    }

    /**
     * The advice as ordered lines, ready to indent and print.
     *
     * Every message site renders exactly this list, which is what makes their
     * ORDER a structural property rather than a convention two files have to
     * remember. The order was previously pinned by tests that searched each file
     * for one string appearing before another — they passed while the two files
     * said different things, and could not survive the consolidation they were
     * guarding.
     *
     * The key comes before the recovery so that "the key above" in the recovery
     * refers to something the operator has actually seen.
     *
     * Symfony style tags let both console readers render the same emphasis. The
     * serving refusal strips only the tags before writing its plain-text body.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [];

        if ($this->acknowledgeKey !== null) {
            $lines[] = 'Acknowledge with: '.$this->acknowledgeKey;
        }

        if ($this->unreachableLead !== null) {
            $lines[] = sprintf('<error>%s</error> %s', $this->unreachableLead, $this->unreachableDetail);
        }

        return [...$lines, ...$this->remedyLines];
    }
}
