<?php

declare(strict_types=1);

namespace App\Support\Version;

use Stringable;

/**
 * A parsed release identity (ADR 0012).
 *
 * The leading `v` is stripped on the way in. Git tags keep it by convention and
 * the release workflow bakes the tag verbatim, so the same release reaches us
 * spelled both ways; SemVer has no `v` in it, and leaving the two forms distinct
 * would report a false skew between an official image and a source install of
 * the identical release.
 *
 * This type answers only structural questions about a version. The comparisons
 * live in {@see VersionComparator}, because there are two of them and conflating
 * them reintroduces the fail-open ADR 0012 exists to close.
 */
final readonly class SemanticVersion implements Stringable
{
    /**
     * The official SemVer 2.0.0 grammar, with an optional leading `v`.
     *
     * Anchored and strict on purpose: `01.2.3`, `1.2.3-01` and `1.2.3-alpha..1`
     * all look like versions and are none of them valid, and accepting one would
     * record an identity that nothing downstream can parse.
     */
    private const PATTERN = '/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
        .'(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?'
        .'(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

    /**
     * Strings that name no build at all. They arrive from installs that predate
     * a release identity, and from manifests written before ADR 0012.
     */
    private const SENTINELS = ['', 'unknown', 'source'];

    /**
     * @param  list<string>  $prerelease
     */
    private function __construct(
        public int $major,
        public int $minor,
        public int $patch,
        public array $prerelease,
        public ?string $build,
    ) {}

    /**
     * Returns null when the string is not a version we can reason about — either
     * a sentinel, or something that does not parse. Callers must treat null as
     * "cannot say" rather than as a comparison failure.
     */
    public static function parse(?string $raw): ?self
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);

        if (in_array(mb_strtolower($trimmed), self::SENTINELS, true)) {
            return null;
        }

        if (preg_match(self::PATTERN, $trimmed, $m) !== 1) {
            return null;
        }

        return new self(
            (int) $m[1],
            (int) $m[2],
            (int) $m[3],
            ($m[4] ?? '') === '' ? [] : explode('.', $m[4]),
            ($m[5] ?? '') === '' ? null : $m[5],
        );
    }

    /**
     * Is this the generated `<version>-dev` identity rather than a real release?
     *
     * Exactly the bare `dev` prerelease, so a deliberately tagged `v0.2.0-dev.1`
     * stays a real release identifier. A development build sits at an unknown
     * point in history — nothing in its identity says where — which is why it has
     * no precedence (ADR 0012).
     */
    public function isDevelopment(): bool
    {
        return count($this->prerelease) === 1
            && mb_strtolower($this->prerelease[0]) === 'dev';
    }

    /**
     * Does this pin a specific build, or merely a lineage?
     *
     * A development version names a lineage: two installs can both report
     * `0.1.0-dev` while sitting many commits — and migrations — apart. It only
     * pins a build once build metadata carries the commit.
     */
    public function identifiesBuild(): bool
    {
        return ! $this->isDevelopment() || $this->build !== null;
    }

    /**
     * The canonical spelling: no `v`, everything else intact.
     */
    public function canonical(): string
    {
        $version = "{$this->major}.{$this->minor}.{$this->patch}";

        if ($this->prerelease !== []) {
            $version .= '-'.implode('.', $this->prerelease);
        }

        if ($this->build !== null) {
            $version .= '+'.$this->build;
        }

        return $version;
    }

    public function __toString(): string
    {
        return $this->canonical();
    }
}
