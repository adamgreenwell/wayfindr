<?php

declare(strict_types=1);

namespace App\Support\Release;

/**
 * What an operator is told when the upgrade floor refuses them.
 *
 * `minimum_upgrade_from` produces TWO refusals that share one field, and they
 * have different remedies:
 *
 * - **Below the floor.** The install's origin is known and demonstrably older
 *   than the target allows. The only way forward is to upgrade in steps; no
 *   acknowledgement can make the jump safe, because the migrations that would
 *   carry it no longer ship.
 * - **Floor unverifiable.** Nothing records where the install is, so it *may* be
 *   below the floor — and "may be" is not permission to run. But the install may
 *   equally be perfectly current, so the remedy is to say where it is.
 *
 * Printing the first for the second sends an operator who is already current off
 * to reinstall an ancient release. That is not hypothetical: the report command
 * distinguished the two while the migration refusal did not, so a live 0.2.0
 * install whose state file was missing was told it was "older than 0.2.0 allows"
 * and never shown `WAYFINDR_UPGRADE_FROM`. The refusal an operator actually
 * meets during an upgrade is the migration one, so it had the wrong half.
 *
 * Both sites now render these lines, for the same reason ActionAdvice exists:
 * this rule has twins, and every previous fix landed in one of them.
 */
final readonly class FloorAdvice
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public bool $verifiable,
        public array $lines,
    ) {}

    /**
     * @param  ?string  $from  the recorded origin, or null when nothing records it
     * @param  ?string  $target  the release being upgraded to
     * @param  string  $floor  `minimum_upgrade_from`
     */
    public static function for(?string $from, ?string $target, string $floor): self
    {
        $release = $target ?? 'this release';

        if ($from === null) {
            // Not an accusation — a question the install cannot answer about
            // itself. The escape hatch is the whole point of this branch: an
            // operator who knows where they are can say so and proceed.
            //
            // THE VALUE IS A PLACEHOLDER, NEVER THE FLOOR. Printing the floor
            // here handed the operator the one value that defeats the check they
            // are being asked to satisfy: `declaredOrigin()` trusts what they
            // state, and an asserted origin equal to the floor compares as "not
            // below", so an install genuinely older than the floor migrates on a
            // path whose migrations no longer ship. The whole point of the floor
            // is to stop exactly that jump, and the refusal message was
            // dictating the bypass.
            //
            // The operator must supply the version they are actually on, which
            // is the only value that makes this an origin rather than an
            // override — and if that version really is below the floor, the
            // refusal stands, which is the honest outcome.
            //
            // DISAMBIGUATED BY TIMING, NOT BY INEQUALITY. An earlier attempt said
            // "not <target>", which is false on a source deployment: those stamp
            // every commit of a cycle with the same VERSION (see
            // UpgradeGuard::assess()), so an install updating between two commits
            // of one cycle has a truthful pre-pull origin EQUAL to the target.
            // Telling that operator it must differ invites them to invent an
            // older version — the same fabrication this fix exists to prevent,
            // arrived at from the other side. "Before this pull" identifies the
            // right release without asserting anything about its value.
            // AND IT MUST ASK FOR THE PRE-UPGRADE VERSION, EXPLICITLY.
            //
            // The migration guard emits this AFTER the pull, so "the version
            // this install is on" is read as the release being installed — and
            // entering the target compares at-or-above the floor, recreating the
            // same bypass by a different route. The prompt has to name the
            // release the operator upgraded FROM, and say that it is not the one
            // being installed, because the only reader who needs this message is
            // standing in front of a container that has already been replaced.
            return new self(false, [
                'This install has no recorded release, so the upgrade floor cannot be verified.',
                sprintf('%s only supports upgrading from %s or later.', $release, $floor),
                'Either upgrade to that release first, which records where you are,',
                'or state the release that was running BEFORE this pull:',
                '  WAYFINDR_UPGRADE_FROM=<the release you upgraded from>',
                sprintf('Stating a version below %s is still refused — this establishes', $floor),
                'where you started, it does not grant permission.',
            ]);
        }

        return new self(true, [
            sprintf('This install (%s) is older than %s allows to upgrade directly.', $from, $release),
            sprintf('The oldest supported starting point is %s.', $floor),
            'Upgrade to that release first, let it start, then upgrade again.',
            'Acknowledgement cannot help here: the migrations for this jump no longer ship.',
        ]);
    }
}
