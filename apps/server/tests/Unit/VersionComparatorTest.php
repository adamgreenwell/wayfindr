<?php

declare(strict_types=1);

use App\Support\Version\SemanticVersion;
use App\Support\Version\VersionComparator;

describe('parsing', function (): void {
    test('accepts the SemVer spec\'s valid strings', function (string $raw): void {
        expect(SemanticVersion::parse($raw))->not->toBeNull();
    })->with([
        '0.0.4', '1.2.3', '10.20.30', '1.1.2-prerelease+meta', '1.0.0-alpha',
        '1.0.0-alpha.1', '1.0.0-0A.is.legal', '1.0.0-alpha.0valid',
        '1.0.0-rc.1+build.1', '2.0.0+build.1848', '1.2.3+build-1',
        '1.0.0-alpha-a.b-c-somethinglong+build.1-aef.1-its-okay',
    ]);

    test('rejects strings that merely look like versions', function (string $raw): void {
        expect(SemanticVersion::parse($raw))->toBeNull();
    })->with([
        '01.2.3', '1.02.3', '1.2.03',   // leading zeroes in the core
        '1.2.3-01',                      // leading zero in a numeric identifier
        '1.2.3-alpha..1', '1.2.3-alpha.', '1.2.3-', '1.2.3+', '1.2.3+meta..1',
        '1.2', '1.2.3.4', 'alpha', '1.2.3-alpha_beta', 'v1-production.2.3',
    ]);

    test('treats sentinels as unparseable rather than as versions', function (?string $raw): void {
        expect(SemanticVersion::parse($raw))->toBeNull();
    })->with([null, '', '   ', 'unknown', 'UNKNOWN', 'source']);

    test('strips the leading v so both spellings of a release are one identity', function (): void {
        expect(SemanticVersion::parse('v1.2.3')?->canonical())->toBe('1.2.3')
            ->and(SemanticVersion::parse('1.2.3')?->canonical())->toBe('1.2.3');
    });

    test('keeps build metadata in the canonical form', function (): void {
        expect(SemanticVersion::parse('v0.1.0-dev+abc1234')?->canonical())->toBe('0.1.0-dev+abc1234');
    });
});

describe('development versions', function (): void {
    test('the bare generated -dev form is a development version', function (string $raw): void {
        expect(SemanticVersion::parse($raw)?->isDevelopment())->toBeTrue();
    })->with(['0.1.0-dev', 'v0.1.0-dev', '0.1.0-DEV', '0.1.0-dev+abc1234']);

    test('a deliberately tagged prerelease containing "dev" is a real release', function (string $raw): void {
        // Only the generated `<VERSION>-dev` spelling is ambiguous. Treating a
        // real tag as unverifiable would hold a site in maintenance for a pair
        // that genuinely matches.
        expect(SemanticVersion::parse($raw)?->isDevelopment())->toBeFalse();
    })->with(['0.2.0-dev.1', 'v0.2.0-dev.1', '0.2.0-development', '0.2.0-alpha.dev']);

    test('a development version identifies a build only once a commit is attached', function (): void {
        expect(SemanticVersion::parse('0.1.0-dev')?->identifiesBuild())->toBeFalse()
            ->and(SemanticVersion::parse('0.1.0-dev+abc1234')?->identifiesBuild())->toBeTrue()
            ->and(SemanticVersion::parse('0.1.0')?->identifiesBuild())->toBeTrue();
    });
});

describe('"are these the same build?"', function (): void {
    test('identical pinned identities are the same build', function (): void {
        expect(VersionComparator::sameBuild('1.2.3', '1.2.3'))->toBeTrue()
            ->and(VersionComparator::sameBuild('v1.2.3', '1.2.3'))->toBeTrue();
    });

    test('build metadata distinguishes two builds of one version', function (): void {
        // Precedence calls these equal (SemVer section 10). Identity must not.
        expect(VersionComparator::sameBuild('0.1.0-dev+aaaaaaa', '0.1.0-dev+bbbbbbb'))->toBeFalse();
    });

    test('a bare development version is never the same build, even against itself', function (): void {
        expect(VersionComparator::sameBuild('0.1.0-dev', '0.1.0-dev'))->toBeNull();
    });

    test('a sentinel on either side is indeterminate', function (): void {
        expect(VersionComparator::sameBuild('unknown', '1.2.3'))->toBeNull()
            ->and(VersionComparator::sameBuild('1.2.3', 'unknown'))->toBeNull()
            ->and(VersionComparator::sameBuild('unknown', 'unknown'))->toBeNull();
    });

    test('a differing commit defeats equality even when the versions match', function (): void {
        // The case a hand-pinned WAYFINDR_VERSION creates: the version is stale
        // and identical on both sides while the code has moved on.
        expect(VersionComparator::sameBuild('1.2.3', '1.2.3', 'aaaaaaa', 'bbbbbbb'))->toBeFalse();
    });

    test('a matching commit leaves matching versions a match', function (): void {
        expect(VersionComparator::sameBuild('1.2.3', '1.2.3', 'aaaaaaa', 'aaaaaaa'))->toBeTrue();
    });

    test('a commit recorded on only one side does not settle it', function (): void {
        expect(VersionComparator::sameBuild('1.2.3', '1.2.3', 'aaaaaaa', null))->toBeTrue();
    });

    test('a differing commit is decisive even when a version is unparseable', function (): void {
        // Archives written before ADR 0012 carry 'unknown' but may still record
        // a commit; that is enough to know the code differs.
        expect(VersionComparator::sameBuild('unknown', 'unknown', 'aaaaaaa', 'bbbbbbb'))->toBeFalse();
    });

    test('blank and sentinel commits count as not recorded', function (?string $commit): void {
        expect(VersionComparator::sameBuild('1.2.3', '1.2.3', $commit, 'bbbbbbb'))->toBeTrue();
    })->with([null, '', '   ', 'unknown', 'source']);
});

describe('"which is newer?"', function (): void {
    test('orders the SemVer spec\'s precedence chain', function (): void {
        $ascending = [
            '1.0.0-alpha', '1.0.0-alpha.1', '1.0.0-alpha.beta', '1.0.0-beta',
            '1.0.0-beta.2', '1.0.0-beta.11', '1.0.0-rc.1', '1.0.0',
        ];

        for ($i = 0; $i < count($ascending) - 1; $i++) {
            expect(VersionComparator::compare($ascending[$i], $ascending[$i + 1]))
                ->toBe(-1, "{$ascending[$i]} should precede {$ascending[$i + 1]}");
        }
    });

    test('ranks a numeric prerelease identifier below an alphanumeric one', function (): void {
        // The rule `sort -V` inverts.
        expect(VersionComparator::compare('1.0.0-alpha.1', '1.0.0-alpha.beta'))->toBe(-1);
    });

    test('orders the core numerically, not lexically', function (): void {
        expect(VersionComparator::compare('1.9.0', '1.10.0'))->toBe(-1)
            ->and(VersionComparator::compare('2.0.0', '10.0.0'))->toBe(-1);
    });

    test('ignores build metadata, per SemVer section 10', function (): void {
        expect(VersionComparator::compare('1.2.3+aaa', '1.2.3+bbb'))->toBe(0);
    });

    test('treats the two spellings of a release as equal', function (): void {
        expect(VersionComparator::compare('v1.2.3', '1.2.3'))->toBe(0);
    });

    test('has no answer when either side is a development version', function (string $a, string $b): void {
        expect(VersionComparator::compare($a, $b))->toBeNull();
    })->with([
        ['0.1.0-dev', '0.2.0'],
        ['0.2.0', '0.1.0-dev'],
        ['0.1.0-dev+aaa', '0.1.0-dev+bbb'],
        ['0.1.0-dev', '0.1.0-dev'],
    ]);

    test('has no answer when a side is unparseable', function (): void {
        expect(VersionComparator::compare('unknown', '1.2.3'))->toBeNull()
            ->and(VersionComparator::compare('1.2.3', 'garbage'))->toBeNull();
    });

    test('would order the traps the ADR names, which is exactly why it declines', function (): void {
        // Left undeclined these read as confident nonsense: a checkout predating
        // alpha.3 as newer than it, and one taken after 0.2.0 as older than it.
        expect(VersionComparator::compare('0.1.0-alpha.3', '0.1.0-dev'))->toBeNull()
            ->and(VersionComparator::compare('0.2.0-dev', '0.2.0'))->toBeNull();
    });
});
