<?php

declare(strict_types=1);

namespace App\Support\Release;

use App\Support\Version\SemanticVersion;
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

        // Canonical spelling, so the acknowledgement key an operator types is
        // stable. The release workflow passes the git tag verbatim, so the same
        // release arrives as `v0.2.0` from an official build and `0.2.0` from a
        // source one; leaving both in the data would mean `v0.2.0/backups-worker`
        // satisfies nothing on an install that stamped it the other way.
        $version = SemanticVersion::parse($version)?->canonical() ?? $version;

        $declaration = self::withoutComments($declaration);

        /** @var list<array<string, mixed>> $actions */
        $actions = array_map(self::withoutComments(...), $declaration['actions'] ?? []);

        // Each action records the release it belongs to. A consumer collecting a
        // span reads many manifests into one list, and an action that has lost
        // its release cannot be ordered or attributed.
        $actions = array_map(
            static fn (array $action): array => ['release' => $version] + $action,
            $actions,
        );

        /** @var list<array<string, mixed>> $notices */
        $notices = array_map(self::withoutComments(...), $declaration['notices'] ?? []);

        $notices = array_map(
            static fn (array $notice): array => ['release' => $version] + $notice,
            $notices,
        );

        $manifest = [
            'schema' => self::SCHEMA,
            'version' => $version,
            'commit' => $commit,
            // Derived from ACTIONS ALONE. A notice never blocks, so a release
            // carrying only notices is safe to take unattended — which is exactly
            // what this flag answers. Counting notices here would put the
            // changelog's loudest heading on releases that do not need it, and
            // an operator who learns the heading overstates will stop reading it.
            'requires_operator_action' => $actions !== [],
            'minimum_upgrade_from' => $declaration['minimum_upgrade_from'] ?? null,
            'actions' => array_values($actions),
        ];

        // Emitted only when there are any, and at the END of the document, so a
        // manifest from a release that declares no notices is byte-identical to
        // one built before this key existed. Every published manifest so far is
        // still reproducible from its declaration.
        if ($notices !== []) {
            $manifest['notices'] = array_values($notices);
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $declaration
     *
     * @throws InvalidArgumentException
     */
    public static function validateDeclaration(array $declaration): void
    {
        $declaration = self::withoutComments($declaration);

        $unknown = array_diff(array_keys($declaration), ['minimum_upgrade_from', 'actions', 'notices']);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown key(s) in the release declaration: '.implode(', ', $unknown)
            );
        }

        if (isset($declaration['minimum_upgrade_from'])) {
            self::assertVersion($declaration['minimum_upgrade_from'], 'minimum_upgrade_from');
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

            self::validateAction(self::withoutComments($action), $index);

            $id = $action['id'];

            if (isset($seen[$id])) {
                throw new InvalidArgumentException(
                    "Duplicate action id \"{$id}\". Ids are how an acknowledgement names "
                    .'the action it satisfies, so they must be unique within a release.'
                );
            }

            $seen[$id] = true;
        }

        // The two lists share ONE acknowledgement namespace (`<release>/<id>`),
        // so ids must be unique across both, not merely within each. A notice
        // sharing an id with an action would let acknowledging the advisory
        // settle the blocking requirement silently.
        self::validateNotices($declaration['notices'] ?? [], $seen);
    }

    /**
     * Advisory requirements: reported wherever an operator will meet them, never
     * blocking anything (ADR 0013).
     *
     * They are a SEPARATE LIST rather than a severity on an action, and the
     * reason is structural. An advisory has to be honoured at three independent
     * gates — the migration filter, the serving filter, and the installer's
     * partition — and a severity flag means each one must remember to check it.
     * A gate that forgets turns an advisory into an outage, which is the exact
     * failure the severity exists to prevent. The gates read `actions`; they
     * cannot see this list at all.
     *
     * It is also the only shape that is backward compatible. Older readers
     * ignore an unknown top-level key, so a release carrying notices upgrades
     * cleanly from every release that predates them — verified against 0.2.0's
     * shipped reader. A severity flag inside `actions` would instead be read by
     * older code that has no concept of it and treated as required, so a
     * `before-pull` advisory would have made the OLD installer refuse the pull.
     *
     * @param  mixed  $notices
     * @param  array<string, bool>  $seen  action ids already claimed in this release
     *
     * @throws InvalidArgumentException
     */
    private static function validateNotices($notices, array $seen = []): void
    {
        if (! is_array($notices)) {
            throw new InvalidArgumentException('"notices" must be a list.');
        }

        foreach ($notices as $index => $notice) {
            if (! is_array($notice)) {
                throw new InvalidArgumentException("Notice #{$index} must be an object.");
            }

            $notice = self::withoutComments($notice);

            foreach (['id', 'summary', 'detail', 'applicability', 'verification'] as $required) {
                if (! isset($notice[$required])) {
                    throw new InvalidArgumentException("Notice #{$index} is missing \"{$required}\".");
                }
            }

            self::assertCheckName($notice['id'], "Notice #{$index} id");

            foreach (['summary', 'detail'] as $prose) {
                if (! is_string($notice[$prose]) || trim($notice[$prose]) === '') {
                    throw new InvalidArgumentException("Notice \"{$notice['id']}\" {$prose} must be text.");
                }
            }

            // No `phase` and no `depends_on_release`, and both omissions are the
            // point rather than an oversight.
            //
            // A phase says WHEN work can be performed, which only matters because
            // it decides when the response fires. A notice has no response to
            // time, so a phase on one would be a field nothing reads.
            //
            // `depends_on_release` decides strandedness, which decides blocking.
            // A notice cannot block, so the field has nothing to decide — and the
            // rule it implies is worth stating: work that can only be performed
            // on a release the upgrade passes is not advisory. Either it matters
            // enough to stop the upgrade, in which case declare an action, or it
            // does not, in which case telling an operator to do something they
            // can no longer do is noise. **Advisory work must be performable at
            // any time.**
            $unknown = array_diff(
                array_keys($notice),
                ['id', 'summary', 'detail', 'applicability', 'verification', 'release'],
            );

            if ($unknown !== []) {
                throw new InvalidArgumentException(sprintf(
                    'Notice "%s" has unknown key(s): %s. A notice takes no phase and no '
                    .'depends_on_release — it never blocks, so there is nothing to time or to strand.',
                    $notice['id'],
                    implode(', ', $unknown),
                ));
            }

            self::validateApplicability($notice['applicability'], $notice['id']);
            self::validateVerification($notice['verification'], $notice['id']);

            // Unique for the same reason actions are: an acknowledgement is keyed
            // `<release>/<id>`, and the two lists share that namespace — so this
            // collides against action ids as well as against other notices.
            if (isset($seen[$notice['id']])) {
                throw new InvalidArgumentException(sprintf(
                    'Duplicate id "%s". Actions and notices share one acknowledgement '
                    .'namespace, so an id used by either cannot be reused by the other.',
                    $notice['id'],
                ));
            }

            $seen[$notice['id']] = true;
        }
    }

    /**
     * JSON has no comments, so an authored declaration explains itself with
     * `_`-prefixed keys. They are stripped here rather than tolerated downstream:
     * the published manifest is read by machines and should carry no prose that a
     * consumer might one day start depending on.
     *
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private static function withoutComments(array $value): array
    {
        return array_filter(
            $value,
            static fn (string $key): bool => ! str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private static function validateAction(array $action, int $index): void
    {
        foreach (['id', 'summary', 'detail', 'phase', 'depends_on_release', 'applicability', 'verification'] as $required) {
            if (! isset($action[$required])) {
                throw new InvalidArgumentException("Action #{$index} is missing \"{$required}\".");
            }
        }

        // ADR 0013 makes the message part of the feature: the guard halts, so this
        // text is the operator's recovery path. A halt with nothing actionable in
        // it is a worse outcome than no guard, so an empty one fails the build.
        foreach (['summary', 'detail'] as $prose) {
            if (! is_string($action[$prose] ?? null) || trim((string) ($action[$prose] ?? '')) === '') {
                throw new InvalidArgumentException(
                    "Action #{$index} has no usable \"{$prose}\". The guard stops an upgrade with "
                    .'this text; it has to tell the operator what to do.'
                );
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
        if ($applicability['type'] === 'upgrade-from') {
            if (! isset($applicability['min'])) {
                throw new InvalidArgumentException(
                    "Action \"{$id}\" is applicable by upgrade-from but names no \"min\"."
                );
            }

            // It is compared against the upgrade's starting version, so a value
            // that does not parse would silently make the action apply to every
            // upgrade — including the ones it was written to exclude.
            self::assertVersion($applicability['min'], "Action \"{$id}\" applicability min");
        }

        if ($applicability['type'] === 'state') {
            self::assertCheckName($applicability['check'] ?? null, "Action \"{$id}\" applicability");
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
        if ($verification['type'] === 'check') {
            self::assertCheckName($verification['check'] ?? null, "Action \"{$id}\" verification");
        }
    }

    /**
     * @param  mixed  $value
     */
    private static function assertVersion($value, string $label): void
    {
        $parsed = is_string($value) ? SemanticVersion::parse($value) : null;

        if ($parsed === null) {
            throw new InvalidArgumentException(
                $label.' must be a version this build can parse, so it can be compared.'
            );
        }

        // A development identity sits at an unknown point in history, so
        // precedence against it is undefined and the comparator returns "no
        // answer" (ADR 0012). A bound that can never be compared is not a bound;
        // accepting one here would certify it as usable and leave the floor and
        // applicability checks with nothing to decide on.
        if ($parsed->isDevelopment()) {
            throw new InvalidArgumentException(
                $label.' cannot be a development version: precedence against one is '
                .'undefined, so the bound could never be compared.'
            );
        }
    }

    /**
     * @param  mixed  $value
     */
    private static function assertCheckName($value, string $label): void
    {
        // `isset()` is satisfied by an empty string, which names no condition for
        // the guard to dispatch — the same "declared but unusable" shape the
        // phase/dependency rules reject.
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                $label.' names no check to run. Declare it as "attest" if the artifact cannot confirm it.'
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

        self::assertPublished($decoded);

        return $decoded;
    }

    /**
     * The same trust boundary as {@see decode()}, for a manifest that arrives
     * already decoded.
     *
     * History entries are members of a larger document, so they never pass
     * through `decode()` — but they are published manifests and are exactly as
     * much a trust boundary. Accepting any array meant `{"version":"0.2.0"}`
     * contributed no actions, and if that version matched the target it also
     * stopped the real manifest being appended, so the target's own
     * pre-migration requirements disappeared with it.
     *
     * @param  array<string, mixed>  $manifest
     */
    public static function assertPublished(array $manifest): void
    {
        $schema = $manifest['schema'] ?? null;

        if (! is_int($schema)) {
            throw new InvalidArgumentException('Release manifest declares no schema version.');
        }

        if ($schema > self::SCHEMA) {
            throw new InvalidArgumentException(
                "Release manifest uses schema {$schema}; this build understands ".self::SCHEMA.'.'
            );
        }

        self::validatePublished($manifest);

        // The floor is a BOUND, and a bound that cannot be ordered silently
        // stops bounding: `compare()` returns null for it, and the guard
        // deliberately refuses only on a definite "below" — so an install
        // demonstrably older than the intended floor migrates anyway. `build()`
        // has always checked this; reading a published manifest did not, which is
        // the path a corrupt or hand-edited one arrives by.
        if ($manifest['minimum_upgrade_from'] !== null) {
            self::assertVersion($manifest['minimum_upgrade_from'], 'minimum_upgrade_from');
        }
    }

    /**
     * Check a manifest that claims a schema this build understands.
     *
     * `decode()` is a trust boundary — the input is a file baked by some earlier
     * build or an asset fetched from a release — so a matching schema number is
     * not evidence the contents are sound. Without this, `{"schema":1}` reads as
     * a valid declaration of nothing, and a manifest saying
     * `requires_operator_action: false` beside a non-empty action list reads as
     * "no action needed". Both are the fail-open direction: a consumer would
     * proceed past requirements rather than stop at unreadable data.
     *
     * @param  array<string, mixed>  $manifest
     */
    private static function validatePublished(array $manifest): void
    {
        // `minimum_upgrade_from` is required, and null is how "no floor" is said.
        // build() always emits it, so a manifest without the key was truncated or
        // edited - and its absence erases a declared floor silently, letting an
        // install below the supported starting point migrate.
        foreach (['version', 'commit', 'requires_operator_action', 'actions', 'minimum_upgrade_from'] as $required) {
            if (! array_key_exists($required, $manifest)) {
                throw new InvalidArgumentException("Release manifest is missing \"{$required}\".");
            }
        }

        if (! is_string($manifest['version']) || $manifest['version'] === '') {
            throw new InvalidArgumentException('Release manifest has no usable version.');
        }

        // And it must parse, or nothing downstream can use it. `build()` keeps an
        // unparseable value verbatim, the recorder writes it to the state file,
        // and `recordedVersion()` then discards it — so every later process reads
        // a populated install as legacy, re-evaluating retired requirements and
        // refusing the next floor-bearing release it meets.
        //
        // A development identity is allowed here, unlike in a bound: this is what
        // the release IS rather than a limit to compare against, and the guard
        // already treats an unorderable running version conservatively.
        $parsedVersion = SemanticVersion::parse($manifest['version']);

        if ($parsedVersion === null) {
            throw new InvalidArgumentException(
                'Release manifest version must be one this build can parse.'
            );
        }

        // And it must already be CANONICAL. `v0.3.0` parses, so it survived the
        // check above — but `ReleaseState::recordedVersion()` canonicalises what
        // it reads while the guard keeps the manifest's spelling as the target,
        // so the two stop comparing equal. A genuinely fresh install then loses
        // its exemption on the next request and starts returning 503 for
        // upgrade-only work it never owed.
        //
        // `build()` canonicalises the version and every action's release stamp,
        // so a manifest that is not canonical did not come from this builder.
        // Rejecting it keeps one spelling in play everywhere rather than leaving
        // each reader to normalise and hoping they agree.
        if ($parsedVersion->canonical() !== $manifest['version']) {
            throw new InvalidArgumentException(sprintf(
                'Release manifest version must be canonical: "%s" should be "%s".',
                $manifest['version'],
                $parsedVersion->canonical(),
            ));
        }

        // A string, and null is not one. `buildChanged()` compares the recorded
        // commit against this, and a null reads as "cannot tell" — which it
        // resolves as changed, permanently. On a fresh install that drops the
        // freshness exemption on the next request and gates serving on
        // upgrade-only work the install never owed.
        //
        // EMPTY is allowed, because the documented release flow produces it: the
        // history-recording step runs before the release commit exists and passes
        // no `--commit` (RELEASING.md), so its entries carry "". Those are never
        // compared against a running build — only the target manifest's commit is
        // — so an empty one there is honest rather than dangerous.
        if (! is_string($manifest['commit'])) {
            throw new InvalidArgumentException('Release manifest commit must be a string.');
        }

        if (! is_array($manifest['actions'])) {
            throw new InvalidArgumentException('Release manifest "actions" must be a list.');
        }

        $seen = [];

        foreach ($manifest['actions'] as $index => $action) {
            if (! is_array($action)) {
                throw new InvalidArgumentException("Published action #{$index} must be an object.");
            }

            self::validateAction(self::withoutComments($action), $index);

            if (! isset($action['release']) || ! is_string($action['release'])) {
                throw new InvalidArgumentException(
                    "Published action #{$index} does not say which release it belongs to. "
                    .'A span is read as one list, so an unattributed action cannot be ordered.'
                );
            }

            // And it must belong to THIS release. `build()` stamps them equal, so
            // a difference means the manifest was rewritten by something else —
            // and misattribution changes what the action IS. Whether an action is
            // stranded is decided by comparing its release against the target, so
            // an intermediate manifest attributing its work to the target turns an
            // unperformable action into a permitted one: migration proceeds, and
            // serving is then gated on something that cannot be done at all
            // because the release whose code it needs was skipped.
            if ($action['release'] !== $manifest['version']) {
                throw new InvalidArgumentException(
                    "Published action #{$index} claims release \"{$action['release']}\" "
                    ."in the manifest for \"{$manifest['version']}\"."
                );
            }

            // Unique, as the authored declaration already requires. An
            // acknowledgement is keyed `<release>/<action-id>`, so two actions
            // sharing an id share a key — and acknowledging the one an operator
            // read settles the other silently, with its work never done.
            //
            // The authored side has always checked this; the published side is
            // where a hand-edited or truncated manifest arrives, and it did not.
            $id = $action['id'] ?? null;

            if (is_string($id)) {
                if (isset($seen[$id])) {
                    throw new InvalidArgumentException(
                        "Published action #{$index} repeats the id \"{$id}\". "
                        .'Acknowledgements are keyed by release and id, so a repeat would '
                        .'settle more than the operator agreed to.'
                    );
                }

                $seen[$id] = true;
            }
        }

        // The flag is derived at build time. If a published manifest disagrees
        // with its own action list, something rewrote one without the other, and
        // the disagreement is only dangerous in one direction.
        //
        // NOTICES ARE DELIBERATELY NOT COUNTED HERE, matching build(). A release
        // carrying only notices is safe to take unattended, which is what this
        // flag says.
        if ($manifest['requires_operator_action'] !== ($manifest['actions'] !== [])) {
            throw new InvalidArgumentException(
                'Release manifest requires_operator_action contradicts its action list.'
            );
        }

        // Optional, because every manifest published before this key existed has
        // none — and because a release that declares no notices omits it, so old
        // manifests stay byte-reproducible.
        if (array_key_exists('notices', $manifest)) {
            self::validateNotices($manifest['notices'], $seen);

            if (! is_array($manifest['notices'])) {
                throw new InvalidArgumentException('Release manifest "notices" must be a list.');
            }

            foreach ($manifest['notices'] as $index => $notice) {
                // Attributed to THIS release, as actions are. A span is read as
                // one list, so a notice that has lost its release cannot be
                // ordered — and one claiming a different release would be
                // reported against the wrong upgrade.
                if (! isset($notice['release']) || ! is_string($notice['release'])) {
                    throw new InvalidArgumentException(
                        "Published notice #{$index} does not say which release it belongs to."
                    );
                }

                if ($notice['release'] !== $manifest['version']) {
                    throw new InvalidArgumentException(
                        "Published notice #{$index} claims release \"{$notice['release']}\" "
                        ."in the manifest for \"{$manifest['version']}\"."
                    );
                }
            }
        }
    }
}
