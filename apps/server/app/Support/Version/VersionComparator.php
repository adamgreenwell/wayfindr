<?php

declare(strict_types=1);

namespace App\Support\Version;

/**
 * The two comparisons ADR 0012 defines over release identities.
 *
 * They are deliberately separate methods with different return types, because
 * they answer different questions and have different failure modes:
 *
 *   "Are these the same build?"  identity   — uses the FULL identity, build
 *                                             metadata included, plus the commit
 *   "Which one is newer?"        precedence — SemVer ordering, which ignores
 *                                             build metadata entirely
 *
 * Answering the first with the second is exactly the fail-open this effort
 * exists to close: `0.1.0-dev+aaaaaaa` and `0.1.0-dev+bbbbbbb` are *equal* to a
 * conforming precedence comparator and are different code.
 *
 * Both return null for "no answer", and callers must handle it rather than
 * coercing. Null is not a failure; it is the honest result for a question the
 * data cannot settle, and treating it as false or 0 puts the guess back in.
 */
final class VersionComparator
{
    /**
     * Are the archive and the running install the same build?
     *
     * Returns null when that cannot be determined, which a destructive restore
     * must surface rather than resolve — an unverifiable pair is not a match.
     *
     * The commit is decisive on its own. The manifest records it independently,
     * so when both sides carry one and they disagree, the code differs whatever
     * the version strings claim. That also removes the answer's dependence on
     * every operator deriving their version correctly: a hand-pinned
     * `WAYFINDR_VERSION` left in place across deploys is caught by the commit.
     */
    public static function sameBuild(
        ?string $archiveVersion,
        ?string $runningVersion,
        ?string $archiveCommit = null,
        ?string $runningCommit = null,
    ): ?bool {
        $archive = SemanticVersion::parse($archiveVersion);
        $running = SemanticVersion::parse($runningVersion);

        $archiveSha = self::normalizeCommit($archiveCommit);
        $runningSha = self::normalizeCommit($runningCommit);

        // A disagreeing pair of commits settles it before anything else: they
        // name different code, and no version string can argue otherwise.
        if ($archiveSha !== null && $runningSha !== null && $archiveSha !== $runningSha) {
            return false;
        }

        if ($archive === null || $running === null) {
            return null;
        }

        if (! $archive->identifiesBuild() || ! $running->identifiesBuild()) {
            return null;
        }

        // Full identity, build metadata included. Two builds of one release
        // differ in metadata alone, and that difference is the whole point.
        return $archive->canonical() === $running->canonical();
    }

    /**
     * Which version is newer? -1, 0, 1 in the usual sense, or null when the
     * question has no answer.
     *
     * Null whenever either side is a development version. The ordering there
     * *looks* meaningful and is not: `0.1.0-alpha.3 < 0.1.0-dev` makes a checkout
     * predating alpha.3 read as newer than it, and `0.2.0-dev < 0.2.0` makes a
     * checkout taken after the release read as older. Callers must treat null as
     * direction-unknown rather than reaching for a default.
     */
    public static function compare(?string $a, ?string $b): ?int
    {
        $left = SemanticVersion::parse($a);
        $right = SemanticVersion::parse($b);

        if ($left === null || $right === null) {
            return null;
        }

        if ($left->isDevelopment() || $right->isDevelopment()) {
            return null;
        }

        return self::precedence($left, $right);
    }

    /**
     * SemVer 2.0.0 section 11 precedence. Build metadata is ignored, per section 10.
     */
    private static function precedence(SemanticVersion $a, SemanticVersion $b): int
    {
        $core = [
            self::compareNumeric($a->major, $b->major),
            self::compareNumeric($a->minor, $b->minor),
            self::compareNumeric($a->patch, $b->patch),
        ];

        foreach ($core as $result) {
            if ($result !== 0) {
                return $result;
            }
        }

        // A version with no prerelease outranks one that has it.
        if ($a->prerelease === [] && $b->prerelease === []) {
            return 0;
        }

        if ($a->prerelease === []) {
            return 1;
        }

        if ($b->prerelease === []) {
            return -1;
        }

        return self::comparePrerelease($a->prerelease, $b->prerelease);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function comparePrerelease(array $a, array $b): int
    {
        $shared = min(count($a), count($b));

        for ($i = 0; $i < $shared; $i++) {
            $result = self::compareIdentifier($a[$i], $b[$i]);

            if ($result !== 0) {
                return $result;
            }
        }

        // All shared identifiers equal: more fields wins.
        return count($a) <=> count($b);
    }

    private static function compareIdentifier(string $a, string $b): int
    {
        $aNumeric = self::isNumeric($a);
        $bNumeric = self::isNumeric($b);

        if ($aNumeric && $bNumeric) {
            return self::compareNumeric($a, $b);
        }

        // Numeric identifiers always rank lower than alphanumeric ones. This is
        // the rule `sort -V` gets wrong, so it is worth being explicit about.
        if ($aNumeric) {
            return -1;
        }

        if ($bNumeric) {
            return 1;
        }

        return strcmp($a, $b) <=> 0;
    }

    /**
     * Compare two digit strings as numbers, without turning them into numbers.
     *
     * SemVer bounds neither the core components nor numeric prerelease
     * identifiers, so anything above PHP_INT_MAX saturates on cast and distinct
     * versions start comparing equal. The grammar forbids leading zeroes, so a
     * longer string is the larger number and equal lengths order lexicographically.
     */
    private static function compareNumeric(string $a, string $b): int
    {
        return [strlen($a), $a] <=> [strlen($b), $b];
    }

    private static function isNumeric(string $identifier): bool
    {
        return preg_match('/^\d+$/', $identifier) === 1;
    }

    /**
     * A commit is only usable as evidence when it is actually present. Blank and
     * sentinel values mean "not recorded", not "recorded as nothing".
     */
    private static function normalizeCommit(?string $commit): ?string
    {
        if ($commit === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($commit));

        if ($normalized === '' || $normalized === 'unknown' || $normalized === 'source') {
            return null;
        }

        return $normalized;
    }
}
