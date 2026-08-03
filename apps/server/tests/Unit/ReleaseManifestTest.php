<?php

declare(strict_types=1);

use App\Support\Release\ReleaseManifest;

function validAction(array $overrides = []): array
{
    return array_replace([
        'id' => 'backups-queue-worker',
        'summary' => 'Run a second queue worker on the backups connection.',
        'phase' => 'after-start',
        'depends_on_release' => 'code',
        'applicability' => ['type' => 'always'],
        'verification' => ['type' => 'check', 'check' => 'backups-queue-consumer'],
    ], $overrides);
}

describe('building', function (): void {
    test('derives requires_operator_action from the actions themselves', function (): void {
        // Never authored: a hand-maintained boolean beside the list it summarises
        // drifts, and it drifts toward false while actions exist.
        $none = ReleaseManifest::build(['actions' => []], '0.2.0', 'abc');
        $some = ReleaseManifest::build(['actions' => [validAction()]], '0.2.0', 'abc');

        expect($none['requires_operator_action'])->toBeFalse()
            ->and($some['requires_operator_action'])->toBeTrue();
    });

    test('stamps each action with the release it belongs to', function (): void {
        // A consumer collecting a span reads many manifests into one list; an
        // action that has lost its release cannot be ordered or attributed.
        $manifest = ReleaseManifest::build(['actions' => [validAction()]], '0.2.0', 'abc');

        expect($manifest['actions'][0]['release'])->toBe('0.2.0');
    });

    test('carries the identity and the floor', function (): void {
        $manifest = ReleaseManifest::build(
            ['minimum_upgrade_from' => '0.1.0', 'actions' => []],
            '0.2.0',
            'abc123',
        );

        expect($manifest['schema'])->toBe(ReleaseManifest::SCHEMA)
            ->and($manifest['version'])->toBe('0.2.0')
            ->and($manifest['commit'])->toBe('abc123')
            ->and($manifest['minimum_upgrade_from'])->toBe('0.1.0');
    });
});

describe('impossible phase and dependency pairs', function (): void {
    test('rejects a before-pull action that needs the new release', function (string $dependency): void {
        expect(fn () => ReleaseManifest::build(
            ['actions' => [validAction(['phase' => 'before-pull', 'depends_on_release' => $dependency])]],
            '0.2.0',
            'abc',
        ))->toThrow(InvalidArgumentException::class, 'before-pull runs on the old release');
    })->with(['code', 'schema']);

    test('rejects an after-pull action that needs the new schema', function (): void {
        // after-pull exists precisely because migrations have not run yet.
        expect(fn () => ReleaseManifest::build(
            ['actions' => [validAction(['phase' => 'after-pull', 'depends_on_release' => 'schema'])]],
            '0.2.0',
            'abc',
        ))->toThrow(InvalidArgumentException::class, 'the new schema does not exist yet');
    });

    test('accepts the pairs that can actually be performed', function (string $phase, string $dependency): void {
        $manifest = ReleaseManifest::build(
            ['actions' => [validAction(['phase' => $phase, 'depends_on_release' => $dependency])]],
            '0.2.0',
            'abc',
        );

        expect($manifest['actions'])->toHaveCount(1);
    })->with([
        ['before-pull', 'none'],
        ['after-pull', 'none'],
        ['after-pull', 'code'],
        ['after-start', 'none'],
        ['after-start', 'code'],
        ['after-start', 'schema'],
    ]);
});

describe('validation', function (): void {
    test('rejects a check that names nothing to run', function (): void {
        // Otherwise it is an attestation wearing a verification's label.
        expect(fn () => ReleaseManifest::build(
            ['actions' => [validAction(['verification' => ['type' => 'check']])]],
            '0.2.0',
            'abc',
        ))->toThrow(InvalidArgumentException::class, 'names no "check"');
    });

    test('accepts an attestation, which needs nothing to run', function (): void {
        $manifest = ReleaseManifest::build(
            ['actions' => [validAction(['verification' => ['type' => 'attest']])]],
            '0.2.0',
            'abc',
        );

        expect($manifest['actions'][0]['verification']['type'])->toBe('attest');
    });

    test('rejects duplicate ids', function (): void {
        expect(fn () => ReleaseManifest::build(
            ['actions' => [validAction(), validAction()]],
            '0.2.0',
            'abc',
        ))->toThrow(InvalidArgumentException::class, 'Duplicate action id');
    });

    test('rejects an id an operator could not type reliably', function (string $id): void {
        expect(fn () => ReleaseManifest::build(
            ['actions' => [validAction(['id' => $id])]],
            '0.2.0',
            'abc',
        ))->toThrow(InvalidArgumentException::class, 'lowercase slug');
    })->with(['Backups Worker', 'backups_worker', 'BACKUPS', 'backups--worker', '-backups']);

    test('rejects unknown top-level keys', function (): void {
        expect(fn () => ReleaseManifest::build(['requires_operator_action' => false], '0.2.0', 'abc'))
            ->toThrow(InvalidArgumentException::class, 'Unknown key');
    });

    test('requires applicability to say how it applies', function (array $applicability, string $expected): void {
        expect(fn () => ReleaseManifest::build(
            ['actions' => [validAction(['applicability' => $applicability])]],
            '0.2.0',
            'abc',
        ))->toThrow(InvalidArgumentException::class, $expected);
    })->with([
        [['type' => 'upgrade-from'], 'names no "min"'],
        [['type' => 'state'], 'names no "check"'],
        [['type' => 'whenever'], 'must be one of'],
    ]);
});

describe('decoding', function (): void {
    test('refuses a manifest from a newer schema', function (): void {
        // Partly understanding a manifest is the fail-open: a requirement could
        // sit in a field this build has never heard of.
        $future = json_encode(['schema' => ReleaseManifest::SCHEMA + 1, 'version' => '9.9.9']);

        expect(fn () => ReleaseManifest::decode($future))
            ->toThrow(InvalidArgumentException::class, 'this build understands');
    });

    test('reads a manifest of its own schema', function (): void {
        $json = json_encode(ReleaseManifest::build(['actions' => []], '0.2.0', 'abc'));

        expect(ReleaseManifest::decode($json)['version'])->toBe('0.2.0');
    });

    test('refuses input that is not a manifest', function (string $json): void {
        expect(fn () => ReleaseManifest::decode($json))->toThrow(InvalidArgumentException::class);
    })->with(['not json', '"a string"', '{}', '{"version":"1.0.0"}']);
});
