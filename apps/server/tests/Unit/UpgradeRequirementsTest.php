<?php

declare(strict_types=1);

use App\Support\Release\UpgradeRequirements;

function guardAction(array $overrides = []): array
{
    return array_replace([
        'id' => 'do-a-thing',
        'release' => '0.2.0',
        'summary' => 'Do a thing.',
        'phase' => 'after-start',
        'depends_on_release' => 'code',
        'applicability' => ['type' => 'always'],
        'verification' => ['type' => 'attest'],
    ], $overrides);
}

function guardManifest(string $version, array $actions = []): array
{
    return ['version' => $version, 'actions' => $actions];
}

/** A check evaluator that returns whatever the test tells it to. */
function guardChecks(array $results): callable
{
    return static fn (string $name): ?bool => $results[$name] ?? null;
}

describe('the span an upgrade traverses', function (): void {
    test('takes everything after the start, up to the target', function (): void {
        // A declaration describes its own release, so v1 -> v3 receives v2's
        // changes too. Reading only the target misses the skipped release.
        $history = [guardManifest('0.1.0'), guardManifest('0.2.0'), guardManifest('0.3.0'), guardManifest('0.4.0')];

        $span = UpgradeRequirements::span($history, '0.1.0', '0.3.0');

        expect(array_column($span, 'version'))->toBe(['0.2.0', '0.3.0']);
    });

    test('includes the target and excludes the starting release', function (): void {
        $history = [guardManifest('0.1.0'), guardManifest('0.2.0')];

        expect(array_column(UpgradeRequirements::span($history, '0.1.0', '0.2.0'), 'version'))
            ->toBe(['0.2.0']);
    });

    test('takes the whole history when the start is unknown', function (): void {
        // A legacy install has no state file. Evaluating nothing would be the
        // pre-enforcement bypass this exists to close.
        $history = [guardManifest('0.1.0'), guardManifest('0.2.0'), guardManifest('0.3.0')];

        expect(UpgradeRequirements::span($history, null, '0.3.0'))->toHaveCount(3);
    });

    test('keeps a release whose order cannot be determined', function (): void {
        // Precedence is undefined against a development version. Excluding would
        // be a guess in the unsafe direction.
        $history = [guardManifest('0.2.0'), guardManifest('0.3.0-dev')];

        expect(UpgradeRequirements::span($history, '0.1.0', '0.3.0-dev'))->toHaveCount(2);
    });
});

describe('outstanding actions', function (): void {
    test('an unacknowledged attestation is outstanding', function (): void {
        $out = UpgradeRequirements::outstanding(
            [guardManifest('0.2.0', [guardAction()])], '0.1.0', '0.2.0', [], guardChecks([]),
        );

        expect($out)->toHaveCount(1)
            ->and($out[0]['satisfied_by'])->toBe('unacknowledged');
    });

    test('an acknowledgement naming the action settles it', function (): void {
        $out = UpgradeRequirements::outstanding(
            [guardManifest('0.2.0', [guardAction()])], '0.1.0', '0.2.0', ['0.2.0/do-a-thing'], guardChecks([]),
        );

        expect($out)->toBeEmpty();
    });

    test('an acknowledgement for a different release does not settle it', function (): void {
        // Entries are <release>/<id> precisely so they cannot become a blanket
        // opt-out.
        $out = UpgradeRequirements::outstanding(
            [guardManifest('0.2.0', [guardAction()])], '0.1.0', '0.2.0', ['0.3.0/do-a-thing'], guardChecks([]),
        );

        expect($out)->toHaveCount(1);
    });

    test('a passing check settles it without any acknowledgement', function (): void {
        $out = UpgradeRequirements::outstanding(
            [guardManifest('0.2.0', [guardAction(['verification' => ['type' => 'check', 'check' => 'worker']])])],
            '0.1.0', '0.2.0', [], guardChecks(['worker' => true]),
        );

        expect($out)->toBeEmpty();
    });

    test('a failing check leaves it outstanding', function (): void {
        $out = UpgradeRequirements::outstanding(
            [guardManifest('0.2.0', [guardAction(['verification' => ['type' => 'check', 'check' => 'worker']])])],
            '0.1.0', '0.2.0', [], guardChecks(['worker' => false]),
        );

        expect($out)->toHaveCount(1)->and($out[0]['satisfied_by'])->toBe('failed');
    });

    test('a check that cannot be evaluated is not a pass', function (): void {
        // Absent evidence is not evidence. The point of preferring checks is that
        // they are evidence; treating "cannot tell" as satisfied would make a
        // check weaker than the attestation it replaced.
        $out = UpgradeRequirements::outstanding(
            [guardManifest('0.2.0', [guardAction(['verification' => ['type' => 'check', 'check' => 'worker']])])],
            '0.1.0', '0.2.0', [], guardChecks([]),
        );

        expect($out)->toHaveCount(1)->and($out[0]['satisfied_by'])->toBe('unevaluable');
    });

    test('an operator may acknowledge past a check that cannot be evaluated', function (): void {
        $out = UpgradeRequirements::outstanding(
            [guardManifest('0.2.0', [guardAction(['verification' => ['type' => 'check', 'check' => 'worker']])])],
            '0.1.0', '0.2.0', ['0.2.0/do-a-thing'], guardChecks([]),
        );

        expect($out)->toBeEmpty();
    });

    test('collects across the whole span, not just the target', function (): void {
        $history = [
            guardManifest('0.2.0', [guardAction(['id' => 'from-two', 'release' => '0.2.0'])]),
            guardManifest('0.3.0', [guardAction(['id' => 'from-three', 'release' => '0.3.0'])]),
        ];

        $out = UpgradeRequirements::outstanding($history, '0.1.0', '0.3.0', [], guardChecks([]));

        expect(array_column($out, 'id'))->toBe(['from-two', 'from-three']);
    });
});

describe('applicability', function (): void {
    test('upgrade-from excludes an install that started above the minimum', function (): void {
        $retirement = guardAction(['applicability' => ['type' => 'upgrade-from', 'min' => '0.2.0']]);

        expect(UpgradeRequirements::applies($retirement, '0.1.0'))->toBeFalse()
            ->and(UpgradeRequirements::applies($retirement, '0.2.0'))->toBeTrue()
            ->and(UpgradeRequirements::applies($retirement, '0.3.0'))->toBeTrue();
    });

    test('upgrade-from applies when the start is unknown', function (): void {
        // A legacy install may well have run the release that created the thing
        // being retired; it cannot be ruled out.
        $retirement = guardAction(['applicability' => ['type' => 'upgrade-from', 'min' => '0.2.0']]);

        expect(UpgradeRequirements::applies($retirement, null))->toBeTrue();
    });

    test('always applies regardless of where the upgrade started', function (): void {
        expect(UpgradeRequirements::applies(guardAction(), '0.1.0'))->toBeTrue()
            ->and(UpgradeRequirements::applies(guardAction(), null))->toBeTrue();
    });
});

describe('phase routing', function (): void {
    test('separates what blocks migration from what gates serving', function (): void {
        // after-start cannot block migration: the action needs the migrated
        // schema, so blocking would withhold the state the action requires.
        expect(UpgradeRequirements::BLOCKS_MIGRATION)->toBe(['before-pull', 'after-pull'])
            ->and(UpgradeRequirements::BLOCKS_SERVING)->toBe(['after-start']);
    });
});

describe('parsing acknowledgements', function (): void {
    test('reads a comma separated list, tolerating spacing', function (): void {
        expect(UpgradeRequirements::parseAcknowledged(' 0.2.0/a , 0.3.0/b '))
            ->toBe(['0.2.0/a', '0.3.0/b']);
    });

    test('treats absent or blank as none', function (?string $raw): void {
        expect(UpgradeRequirements::parseAcknowledged($raw))->toBe([]);
    })->with([null, '', '   ', ',', ' , ']);
});
