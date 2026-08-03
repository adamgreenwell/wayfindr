<?php

declare(strict_types=1);

namespace App\Support\Release;

use InvalidArgumentException;

/**
 * The machine-readable declaration a release publishes about its own upgrade
 * impact (ADR 0012, shaped by ADR 0013).
 *
 * Deliberately free of framework dependencies. It is built during the image
 * build, before the application can boot, and read by the entrypoint guard
 * before migrations have run — neither moment has a working Laravel container.
 *
 * Two readers consume the output and they need different delivery:
 *   - the artifact guard reads the copy baked into the image, offline
 *   - the installer preflight reads the copy published as a release asset, for
 *     releases it never pulls
 * Both come from this one builder so they cannot disagree.
 */
final class ReleaseManifest
{
    /** Bumped only when the shape changes incompatibly. Readers refuse newer. */
    public const SCHEMA = 1;

    public const PHASES = ['before-pull', 'after-pull', 'after-start'];

    /** What of its own release an action needs in order to be performable. */
    public const DEPENDENCIES = ['none', 'code', 'schema'];

    public const VERIFICATION_TYPES = ['check', 'attest'];

    public const APPLICABILITY_TYPES = ['always', 'upgrade-from', 'state'];

    /**
     * Build the published manifest from the authored declaration plus the
     * identity of the release being built.
     *
     * `requires_operator_action` is DERIVED, never authored. A hand-maintained
     * boolean beside a list of actions is a drift waiting to happen, and it
     * would drift in the dangerous direction: false while actions exist.
     *
     * @param  array<string, mixed>  $declaration
     * @return array<string, mixed>
     */
    public static function build(array $declaration, string $version, string $commit): array
    {
        self::validateDeclaration($declaration);

        /** @var list<array<string, mixed>> $actions */
        $actions = $declaration['actions'] ?? [];

        // Each action records the release it belongs to. A consumer collecting a
        // span reads many manifests into one list, and an action that has lost
        // its release cannot be ordered or attributed.
        $actions = array_map(
            static fn (array $action): array => ['release' => $version] + $action,
            $actions,
        );

        return [
            'schema' => self::SCHEMA,
            'version' => $version,
            'commit' => $commit,
            'requires_operator_action' => $actions !== [],
            'minimum_upgrade_from' => $declaration['minimum_upgrade_from'] ?? null,
            'actions' => array_values($actions),
        ];
    }

    /**
     * @param  array<string, mixed>  $declaration
     *
     * @throws InvalidArgumentException
     */
    public static function validateDeclaration(array $declaration): void
    {
        $unknown = array_diff(array_keys($declaration), ['minimum_upgrade_from', 'actions']);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown key(s) in the release declaration: '.implode(', ', $unknown)
            );
        }

        $actions = $declaration['actions'] ?? [];

        if (! is_array($actions)) {
            throw new InvalidArgumentException('"actions" must be a list.');
        }

        $seen = [];

        foreach ($actions as $index => $action) {
            if (! is_array($action)) {
                throw new InvalidArgumentException("Action #{$index} must be an object.");
            }

            self::validateAction($action, $index);

            $id = $action['id'];

            if (isset($seen[$id])) {
                throw new InvalidArgumentException(
                    "Duplicate action id \"{$id}\". Ids are how an acknowledgement names "
                    .'the action it satisfies, so they must be unique within a release.'
                );
            }

            $seen[$id] = true;
        }
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private static function validateAction(array $action, int $index): void
    {
        foreach (['id', 'summary', 'phase', 'depends_on_release', 'applicability', 'verification'] as $required) {
            if (! isset($action[$required])) {
                throw new InvalidArgumentException("Action #{$index} is missing \"{$required}\".");
            }
        }

        if (! is_string($action['id']) || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $action['id']) !== 1) {
            throw new InvalidArgumentException(
                "Action #{$index} has an id that is not a lowercase slug. Ids appear in "
                .'WAYFINDR_ACKNOWLEDGED_ACTIONS, which an operator types by hand.'
            );
        }

        self::assertOneOf($action['phase'], self::PHASES, "Action \"{$action['id']}\" phase");
        self::assertOneOf($action['depends_on_release'], self::DEPENDENCIES, "Action \"{$action['id']}\" depends_on_release");
        self::validatePhaseDependency($action['phase'], $action['depends_on_release'], $action['id']);

        self::validateApplicability($action['applicability'], $action['id']);
        self::validateVerification($action['verification'], $action['id']);
    }

    /**
     * Some phase/dependency pairs describe an action that can never be performed,
     * and validating the two fields independently lets one through.
     *
     * `before-pull` runs while the OLD release is still live, so the new
     * release's code and schema are both absent. `after-pull` has the new code
     * but migrations have not run, so the new schema is not there yet — which is
     * the entire reason that phase exists.
     *
     * Publishing such a pair leaves the preflight with no moment at which to tell
     * the operator to act, so the failure is silent: an action that is declared,
     * required, and impossible.
     */
    private static function validatePhaseDependency(string $phase, string $dependency, string $id): void
    {
        $allowed = [
            'before-pull' => ['none'],
            'after-pull' => ['none', 'code'],
            'after-start' => ['none', 'code', 'schema'],
        ];

        if (in_array($dependency, $allowed[$phase], true)) {
            return;
        }

        $why = match (true) {
            $phase === 'before-pull' => 'before-pull runs on the old release, where neither the new code nor its schema exists',
            default => 'after-pull runs before migrations, so the new schema does not exist yet',
        };

        throw new InvalidArgumentException(
            "Action \"{$id}\" cannot depend on the release's {$dependency} at phase {$phase}: {$why}. "
            .'Move it to a later phase, or reduce what it depends on.'
        );
    }

    /**
     * @param  mixed  $applicability
     */
    private static function validateApplicability($applicability, string $id): void
    {
        if (! is_array($applicability) || ! isset($applicability['type'])) {
            throw new InvalidArgumentException("Action \"{$id}\" applicability must be an object with a type.");
        }

        self::assertOneOf($applicability['type'], self::APPLICABILITY_TYPES, "Action \"{$id}\" applicability type");

        // A pointer at an earlier action cannot express retirement: a release
        // that removes a requirement must tell an install that RAN the earlier
        // release to undo it, while telling a direct jump that never applied it
        // nothing at all. So applicability is conditioned on where the upgrade
        // started, or on observable state.
        if ($applicability['type'] === 'upgrade-from' && ! isset($applicability['min'])) {
            throw new InvalidArgumentException(
                "Action \"{$id}\" is applicable by upgrade-from but names no \"min\"."
            );
        }

        if ($applicability['type'] === 'state' && ! isset($applicability['check'])) {
            throw new InvalidArgumentException(
                "Action \"{$id}\" is applicable by state but names no \"check\"."
            );
        }
    }

    /**
     * @param  mixed  $verification
     */
    private static function validateVerification($verification, string $id): void
    {
        if (! is_array($verification) || ! isset($verification['type'])) {
            throw new InvalidArgumentException("Action \"{$id}\" verification must be an object with a type.");
        }

        self::assertOneOf($verification['type'], self::VERIFICATION_TYPES, "Action \"{$id}\" verification type");

        // A `check` without something to run is an attestation wearing a
        // verification's label, which is the one confusion ADR 0013 forbids.
        if ($verification['type'] === 'check' && ! isset($verification['check'])) {
            throw new InvalidArgumentException(
                "Action \"{$id}\" claims machine verification but names no \"check\". "
                .'Declare it as "attest" if the artifact cannot confirm it.'
            );
        }
    }

    /**
     * @param  mixed  $value
     * @param  list<string>  $allowed
     */
    private static function assertOneOf($value, array $allowed, string $label): void
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(
                $label.' must be one of: '.implode(', ', $allowed).'.'
            );
        }
    }

    /**
     * Read a published manifest, refusing one written by a newer schema.
     *
     * Refusing is the safe direction: a manifest we only partly understand may
     * carry a requirement in a field this build has never heard of, and treating
     * that as "nothing required" is the fail-open this exists to prevent.
     *
     * @return array<string, mixed>
     */
    public static function decode(string $json): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Release manifest is not valid JSON.');
        }

        $schema = $decoded['schema'] ?? null;

        if (! is_int($schema)) {
            throw new InvalidArgumentException('Release manifest declares no schema version.');
        }

        if ($schema > self::SCHEMA) {
            throw new InvalidArgumentException(
                "Release manifest uses schema {$schema}; this build understands ".self::SCHEMA.'.'
            );
        }

        return $decoded;
    }
}
