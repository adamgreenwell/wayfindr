<?php

declare(strict_types=1);

use App\Support\Release\ReleaseManifest;

function validAction(array $overrides = []): array
{
    return array_replace([
        'id' => 'backups-queue-worker',
        'summary' => 'Run a second queue worker on the backups connection.',
        'detail' => 'php artisan queue:work backups --queue=backups',
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
        ))->toThrow(InvalidArgumentException::class, 'names no check to run');
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

    test('requires prose the guard can actually show an operator', function (array $overrides, string $field): void {
        // ADR 0013 makes the message part of the feature: the guard halts, so an
        // action with nothing actionable in it is a halt with no recovery.
        expect(fn () => ReleaseManifest::build(
            ['actions' => [validAction($overrides)]], '0.2.0', 'abc',
        ))->toThrow(InvalidArgumentException::class, $field);
    })->with([
        [['summary' => '   '], 'summary'],
        [['summary' => 123], 'summary'],
        [['detail' => ''], 'detail'],
    ]);

    test('requires version-shaped fields to be versions', function (): void {
        // They are compared against the upgrade's start, so an unparseable value
        // would silently make an action apply to every upgrade.
        expect(fn () => ReleaseManifest::build(['minimum_upgrade_from' => 'soon'], '0.2.0', 'abc'))
            ->toThrow(InvalidArgumentException::class, 'must be a version');

        expect(fn () => ReleaseManifest::build(
            ['actions' => [validAction(['applicability' => ['type' => 'upgrade-from', 'min' => 'old']])]],
            '0.2.0', 'abc',
        ))->toThrow(InvalidArgumentException::class, 'must be a version');
    });

    test('canonicalises the release so an acknowledgement key is stable', function (): void {
        // The workflow passes the git tag verbatim, so the same release arrives
        // as v0.2.0 officially and 0.2.0 from source. An acknowledgement typed
        // for one must satisfy the other.
        $tagged = ReleaseManifest::build(['actions' => [validAction()]], 'v0.2.0', 'abc');
        $plain = ReleaseManifest::build(['actions' => [validAction()]], '0.2.0', 'abc');

        expect($tagged['version'])->toBe('0.2.0')
            ->and($tagged['actions'][0]['release'])->toBe('0.2.0')
            ->and($tagged['actions'][0]['release'])->toBe($plain['actions'][0]['release']);
    });

    test('rejects a check name that names nothing', function (array $overrides): void {
        // isset() is satisfied by an empty string, which gives the guard nothing
        // to dispatch — declared but unusable, the same shape the phase rules
        // already reject.
        expect(fn () => ReleaseManifest::build(['actions' => [validAction($overrides)]], '0.2.0', 'abc'))
            ->toThrow(InvalidArgumentException::class, 'names no check to run');
    })->with([
        [['verification' => ['type' => 'check', 'check' => '']]],
        [['verification' => ['type' => 'check', 'check' => '   ']]],
        [['verification' => ['type' => 'check', 'check' => 123]]],
        [['applicability' => ['type' => 'state', 'check' => '']]],
        [['applicability' => ['type' => 'state']]],
    ]);

    test('rejects a development identity as a comparison bound', function (): void {
        // Precedence against a development version is undefined, so the
        // comparator returns no answer — a bound that can never be compared.
        expect(fn () => ReleaseManifest::build(['minimum_upgrade_from' => '0.2.0-dev'], '0.3.0', 'abc'))
            ->toThrow(InvalidArgumentException::class, 'cannot be a development version');

        expect(fn () => ReleaseManifest::build(
            ['actions' => [validAction(['applicability' => ['type' => 'upgrade-from', 'min' => '0.2.0-dev']])]],
            '0.3.0', 'abc',
        ))->toThrow(InvalidArgumentException::class, 'cannot be a development version');
    });

    test('still accepts a real prerelease as a bound', function (): void {
        // Only the generated -dev form is uncomparable; a tagged prerelease
        // orders perfectly well.
        $manifest = ReleaseManifest::build(['minimum_upgrade_from' => '0.1.0-alpha.3'], '0.3.0', 'abc');

        expect($manifest['minimum_upgrade_from'])->toBe('0.1.0-alpha.3');
    });

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
        [['type' => 'state'], 'names no check to run'],
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

    test('refuses a manifest whose flag contradicts its actions', function (): void {
        // decode() is a trust boundary; a matching schema number is not evidence
        // the contents are sound, and this disagreement reads as "nothing needed".
        $manifest = ReleaseManifest::build(['actions' => [validAction()]], '0.2.0', 'abc');
        $manifest['requires_operator_action'] = false;

        expect(fn () => ReleaseManifest::decode(json_encode($manifest)))
            ->toThrow(InvalidArgumentException::class, 'contradicts its action list');
    });

    test('refuses a published action that does not name its release', function (): void {
        $manifest = ReleaseManifest::build(['actions' => [validAction()]], '0.2.0', 'abc');
        unset($manifest['actions'][0]['release']);

        expect(fn () => ReleaseManifest::decode(json_encode($manifest)))
            ->toThrow(InvalidArgumentException::class, 'does not say which release');
    });

    test('refuses a published action that is malformed', function (): void {
        $manifest = ReleaseManifest::build(['actions' => [validAction()]], '0.2.0', 'abc');
        $manifest['actions'][0]['phase'] = 'whenever';

        expect(fn () => ReleaseManifest::decode(json_encode($manifest)))
            ->toThrow(InvalidArgumentException::class, 'must be one of');
    });

    test('reads a manifest of its own schema', function (): void {
        $json = json_encode(ReleaseManifest::build(['actions' => []], '0.2.0', 'abc'));

        expect(ReleaseManifest::decode($json)['version'])->toBe('0.2.0');
    });

    test('refuses input that is not a manifest', function (string $json): void {
        expect(fn () => ReleaseManifest::decode($json))->toThrow(InvalidArgumentException::class);
    })->with([
        'not json',
        '"a string"',
        '{}',
        '{"version":"1.0.0"}',
        '{"schema":1}',                                    // schema alone is not a manifest
        '{"schema":1,"version":"","commit":"a","requires_operator_action":false,"actions":[]}',
    ]);
});
