<?php

declare(strict_types=1);

use App\Support\Release\ReleaseManifest;
use App\Support\Version\SemanticVersion;

use function Wayfindr\ReleaseContract\assertPublishingReleaseReady;
use function Wayfindr\ReleaseContract\assertReleaseDateIsCurrent;
use function Wayfindr\ReleaseContract\assertVersionedReleaseRecorded;
use function Wayfindr\ReleaseContract\historyContext;
use function Wayfindr\ReleaseContract\operatorActionVersionIsAllowed;
use function Wayfindr\ReleaseContract\releaseSectionDate;

require_once dirname(__DIR__, 4).'/scripts/test-release-contract.php';

function releaseContractVersion(string $version): SemanticVersion
{
    return SemanticVersion::parse($version)
        ?? throw new LogicException("Invalid release-contract test version: {$version}");
}

test('operator actions consume the version slot assigned by the platform policy', function (
    string $previous,
    string $candidate,
    bool $allowed,
): void {
    expect(operatorActionVersionIsAllowed(
        releaseContractVersion($candidate),
        releaseContractVersion($previous),
    ))->toBe($allowed);
})->with([
    'pre-1.0 patch is too small' => ['0.7.0', '0.7.1', false],
    'pre-1.0 minor carries operator action' => ['0.7.0', '0.8.0', true],
    '1.0 transition carries operator action' => ['0.9.0', '1.0.0', true],
    'post-1.0 patch is too small' => ['1.0.0', '1.0.1', false],
    'post-1.0 minor is too small' => ['1.0.0', '1.1.0', false],
    'post-1.0 major carries operator action' => ['1.8.0', '2.0.0', true],
]);

/** @param list<array<string, mixed>> $releases */
function releaseContractHistory(array $releases): string
{
    return json_encode([
        'schema' => ReleaseManifest::SCHEMA,
        'releases' => $releases,
    ], JSON_THROW_ON_ERROR);
}

test('versioned release notes require the generated declaration in history', function (): void {
    $candidate = releaseContractVersion('0.8.0');
    $generated = ReleaseManifest::build([
        'actions' => [[
            'id' => 'prepare-host',
            'summary' => 'Prepare the host.',
            'detail' => 'Verify it.',
            'phase' => 'before-pull',
            'depends_on_release' => 'none',
            'installation_profiles' => ['host'],
            'applicability' => ['type' => 'always'],
            'verification' => ['type' => 'attest'],
        ]],
    ], '0.8.0', 'release-commit');
    $recorded = $generated;
    $recorded['commit'] = '';

    $matching = historyContext(releaseContractHistory([$recorded]), $candidate);
    expect(fn () => assertVersionedReleaseRecorded($matching, $generated))->not->toThrow(Throwable::class);

    $missing = historyContext(releaseContractHistory([
        ReleaseManifest::build(['actions' => []], '0.7.0', ''),
    ]), $candidate);
    expect(fn () => assertVersionedReleaseRecorded($missing, $generated))
        ->toThrow(RuntimeException::class, 'matching 0.8.0 entry');

    $stale = historyContext(releaseContractHistory([
        ReleaseManifest::build(['actions' => []], '0.8.0', ''),
    ]), $candidate);
    expect(fn () => assertVersionedReleaseRecorded($stale, $generated))
        ->toThrow(RuntimeException::class, 'stale 0.8.0 declaration');
});

test('release history rejects malformed and duplicate retained entries', function (): void {
    $candidate = releaseContractVersion('0.8.0');

    expect(fn () => historyContext(releaseContractHistory([
        ['version' => '0.7.0'],
    ]), $candidate))->toThrow(RuntimeException::class, 'entry #0 is invalid');

    $release = ReleaseManifest::build(['actions' => []], '0.7.0', 'abc');
    expect(fn () => historyContext(releaseContractHistory([$release, $release]), $candidate))
        ->toThrow(RuntimeException::class, 'repeats release 0.7.0');
});

test('publishing refuses preparation state and requires frozen release history', function (): void {
    $candidate = releaseContractVersion('0.8.0');
    $generated = ReleaseManifest::build(['actions' => []], '0.8.0', 'release-commit');
    $recorded = $generated;
    $recorded['commit'] = '';
    $matching = historyContext(releaseContractHistory([$recorded]), $candidate);

    expect(fn () => assertPublishingReleaseReady(
        $candidate,
        false,
        true,
        $matching,
        $generated,
    ))->not->toThrow(Throwable::class)
        ->and(fn () => assertPublishingReleaseReady(
            $candidate,
            true,
            true,
            $matching,
            $generated,
        ))->toThrow(RuntimeException::class, '[Unreleased] section to be empty')
        ->and(fn () => assertPublishingReleaseReady(
            $candidate,
            false,
            false,
            $matching,
            $generated,
        ))->toThrow(RuntimeException::class, 'non-empty [0.8.0] changelog section')
        ->and(fn () => assertPublishingReleaseReady(
            $candidate,
            false,
            true,
            historyContext(releaseContractHistory([]), $candidate),
            $generated,
        ))->toThrow(RuntimeException::class, 'matching 0.8.0 entry');
});

test('the release date is read from the candidate heading only', function (): void {
    $markdown = <<<'MD'
        ## [Unreleased]

        ## [0.8.0] - 2026-09-12

        ## [0.7.0] - 2026-08-25
        MD;

    expect(releaseSectionDate($markdown, '0.8.0'))->toBe('2026-09-12')
        ->and(releaseSectionDate($markdown, '0.7.0'))->toBe('2026-08-25')
        // Unreleased carries no date, and neither does a version that is absent.
        ->and(releaseSectionDate($markdown, 'Unreleased'))->toBeNull()
        ->and(releaseSectionDate($markdown, '0.9.0'))->toBeNull();
});

test('publishing refuses a changelog section dated days from the cut', function (
    ?string $date,
    bool $passes,
): void {
    // A section written days before the cut ships a date that was never true,
    // and nothing else catches it: the date is not part of any declaration, so
    // every other contract check passes over a heading dated last week.
    $assert = fn (): mixed => assertReleaseDateIsCurrent($date, '0.8.0', '2026-09-12');

    if ($passes) {
        $assert();

        expect(true)->toBeTrue();

        return;
    }

    expect($assert)->toThrow(RuntimeException::class);
})->with([
    // One day of slack in both directions: the tag and the workflow that reads
    // it can straddle UTC midnight, and forcing a retag over that costs more
    // than the day of drift it would catch.
    'the day itself' => ['2026-09-12', true],
    'yesterday' => ['2026-09-11', true],
    'tomorrow' => ['2026-09-13', true],
    'two days stale' => ['2026-09-10', false],
    'a week stale' => ['2026-09-05', false],
    'two days early' => ['2026-09-14', false],
    'no date at all' => [null, false],
    'not a date' => ['soon', false],
    'wrong shape' => ['12-09-2026', false],
    // createFromFormat would roll this into October without the round-trip check.
    'impossible date' => ['2026-09-31', false],
]);
