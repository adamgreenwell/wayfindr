<?php

declare(strict_types=1);

namespace App\Support\Release;

/**
 * What an upgrade can still do about one declared action, and therefore what the
 * operator should be told about it.
 *
 * This exists because the rule it carries had to be agreed by six sites — the
 * predicate, the settlement in `UpgradeRequirements::outstanding()`, the blocking
 * filter in `UpgradeGuard`, the installer's partition, and two operator-facing
 * messages — and nearly every round of #648 fixed a subset and left another,
 * twice fixing a message in one file and not its twin.
 *
 * The three states were always there. They were spelled `STEP`/`NOW`/`DO` in
 * `scripts/self-host/install.sh` and derived from two overlapping booleans in
 * PHP, and the overlap is what made them hard to keep in step: the installer's
 * local `$stranded` is set FALSE for the middle case, while
 * `UpgradeRequirements::stranded()` stays TRUE for it. One name, two predicates,
 * in the two implementations that most needed to agree.
 *
 * The case names are ordered by how much freedom the operator has left, and they
 * map 1:1 onto the installer's codes so the two can be compared directly rather
 * than through a translation layer — see `scripts/test-self-host-classification.sh`.
 */
enum ActionDisposition: string
{
    /**
     * Performable after the upgrade completes. The ordinary case: nothing about
     * this action depends on a release the upgrade is leaving behind.
     *
     * Installer code: `DO`.
     */
    case Performable = 'DO';

    /**
     * Performable only until the pull. The action belongs to the release this
     * install is CURRENTLY RUNNING, so its code is present right now and the work
     * is possible — but the pull replaces that code, and afterwards the operator
     * can only attest that they did it.
     *
     * This is the case that has to be got right in both directions. Treating it
     * as `Unreachable` refuses an acknowledgement the operator can legitimately
     * make; treating it as `Performable` let the preflight report success and the
     * pull then removed the only code that could have done the work.
     *
     * Installer code: `NOW` — and it must stop the pull.
     */
    case PerformableNow = 'NOW';

    /**
     * Not performable at all on this jump. The action belongs to a release the
     * upgrade skips straight past, and it needs that release's own code or
     * schema, so there is no moment at which it could run. The operator never had
     * that code, so no attestation about it can be true either.
     *
     * Installer code: `STEP` — the only route is to stop at the release itself.
     */
    case Unreachable = 'STEP';

    /**
     * Whether saying so would settle this action.
     *
     * Offering an acknowledgement key for `Unreachable` work would document the
     * bypass the refusal exists to warn about, so the messages ask this before
     * printing a key.
     */
    public function acknowledgeable(): bool
    {
        return $this !== self::Unreachable;
    }

    /**
     * Whether an unmet action in this state must stop the MIGRATION, given its
     * declared phase.
     *
     * Phase alone is not enough. An action tied to a release the pull replaced
     * cannot be performed at any phase, so letting an `after-start` one through
     * to the serving gate would leave the install migrated, refusing traffic, and
     * holding a requirement with no way to satisfy it. Anything but `Performable`
     * therefore blocks regardless of phase.
     */
    public function blocksMigration(string $phase): bool
    {
        return $this !== self::Performable
            || in_array($phase, UpgradeRequirements::BLOCKS_MIGRATION, true);
    }

    /**
     * Whether the work is out of reach of the running code, whatever the operator
     * could once have done.
     *
     * Both message sites lead with this — an operator holding a usable
     * acknowledgement key still needs to know the work itself can no longer be
     * performed, or they are left with an instruction they cannot follow.
     */
    public function needsItsOwnRelease(): bool
    {
        return $this !== self::Performable;
    }
}
