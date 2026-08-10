<?php

declare(strict_types=1);

namespace App\Support\Release;

use App\Support\Version\SemanticVersion;
use App\Support\Version\VersionComparator;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Decides whether this release may migrate, given what it declares and what the
 * operator has done about it (ADR 0013).
 *
 * Reads the baked declaration and history, works out where the upgrade started,
 * and hands the evaluation to {@see UpgradeRequirements}. Everything it touches
 * has to work BEFORE migrations run: the schema is whatever the previous release
 * left, so nothing here may assume a table this release is about to create.
 */
final class UpgradeGuard
{
    /**
     * A distinguished exit code, so a caller can tell "requirements unmet" from
     * "something went wrong". The container entrypoint needs the difference —
     * its migrate loop retries on failure, and would otherwise read a refusal as
     * an unreachable database and repeat the operator's instructions thirty
     * times before reporting the wrong cause.
     */
    public const EXIT_BLOCKED = 78;

    public const MANIFEST_FILE = '/etc/wayfindr/release.json';

    public const HISTORY_FILE = '/etc/wayfindr/release-history.json';

    private const ACKNOWLEDGED_ENV = 'WAYFINDR_ACKNOWLEDGED_ACTIONS';

    private const UPGRADE_FROM_ENV = 'WAYFINDR_UPGRADE_FROM';

    private function manifestPath(): string
    {
        return $this->resolvePath(
            (string) config('wayfindr.release.manifest_path', self::MANIFEST_FILE),
            config('wayfindr.release.manifest_fallback_path'),
        );
    }

    private function historyPath(): string
    {
        return $this->resolvePath(
            (string) config('wayfindr.release.history_path', self::HISTORY_FILE),
            config('wayfindr.release.history_fallback_path'),
        );
    }

    /**
     * The baked path if it is there, otherwise the one the source tree carries.
     *
     * Only the image build writes /etc/wayfindr. A source deployment — Forge, or
     * any git checkout — has neither file at that path, and a guard that finds no
     * manifest enforces nothing: it would have been silently inert on every host
     * install, which is the half of the installed base the installer preflight
     * does not cover either.
     *
     * The checkout holds the same data. `releases/history.json` is committed with
     * each release, and the deploy generates this release's manifest from
     * `release.json` beside it.
     *
     * Resolved per call rather than in the config file, because a config-time
     * `is_file()` would be frozen by `config:cache` at whatever was true when the
     * cache was built.
     */
    private function resolvePath(string $primary, mixed $fallback): string
    {
        if (is_file($primary) || ! is_string($fallback) || $fallback === '') {
            return $primary;
        }

        return is_file($fallback) ? $fallback : $primary;
    }

    /**
     * The acknowledgements as they are RIGHT NOW, not as they were cached.
     *
     * This value is read at the one moment an operator is acting on it: the
     * upgrade has just been refused, they have added the acknowledgement the
     * refusal asked for, and they are running `migrate` again. On a host
     * deployment `bootstrap/cache/config.php` is still serving the value from
     * before they edited anything, so `config()` alone would refuse a second
     * time, with the same message, and the only way forward would be clearing a
     * cache nothing told them about. A guard that cannot be satisfied by doing
     * what it asked is worse than no guard.
     *
     * The process environment comes first (containers pass it straight through),
     * then the environment file (which is what the operator actually edited on a
     * host), and only then config — which is still the right answer for a test
     * or a tool that set it programmatically.
     */
    private function acknowledged(): ?string
    {
        return $this->live(self::ACKNOWLEDGED_ENV, 'wayfindr.release.acknowledged_actions');
    }

    /**
     * The version an operator states this install is upgrading FROM.
     *
     * Only consulted when there is no recorded release — an install predating
     * the state file. It is an attestation, in the same shape and read over the
     * same channel as an acknowledgement, and it exists so that "we cannot
     * establish where this install started" has an answer other than a refusal
     * the operator cannot clear.
     */
    private function declaredOrigin(): ?string
    {
        $declared = $this->live(self::UPGRADE_FROM_ENV, 'wayfindr.release.upgrade_from');

        if (! is_string($declared) || trim($declared) === '') {
            return null;
        }

        // It has to be an ORDERABLE release version, canonicalised, or it is not
        // evidence of anything. The floor deliberately refuses only a definite
        // "below", so a value that does not compare - a typo like `0.2.O`, or a
        // development identity, both of which yield null - would clear the
        // unknown-origin refusal without ever being ranked against the floor.
        // That turns a statement of where you are into a way to skip the check.
        $parsed = SemanticVersion::parse(trim($declared));

        if ($parsed === null || $parsed->isDevelopment()) {
            return null;
        }

        return $parsed->canonical();
    }

    /**
     * A value read as it is NOW: process environment, then the environment file,
     * then config.
     *
     * Config comes last because on a host deployment it is served from
     * `bootstrap/cache/config.php` and these are precisely the values an operator
     * edits in response to a refusal — a guard that cannot be satisfied by doing
     * what it asked is worse than no guard. It comes at all because a test or a
     * tool may have set the value programmatically.
     */
    private function live(string $key, string $configKey): ?string
    {
        $live = getenv($key);

        if (is_string($live) && $live !== '') {
            return $live;
        }

        $fromFile = $this->fromEnvironmentFile($key);

        if ($fromFile !== null) {
            return $fromFile;
        }

        $configured = config($configKey);

        return is_string($configured) ? $configured : null;
    }

    /**
     * Parsed with the same library that loads `.env` normally, so quoting,
     * escapes and interpolation behave identically to an uncached boot. A
     * hand-rolled grep would differ from the parser on exactly the values people
     * quote because they contain something awkward.
     */
    private function fromEnvironmentFile(string $key): ?string
    {
        $path = base_path('.env');

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        try {
            $values = Dotenv::createArrayBacked(dirname($path), basename($path))->safeLoad();
        } catch (Throwable) {
            // A malformed .env is not this guard's problem to report; the boot
            // that follows will say so far more clearly.
            return null;
        }

        $value = $values[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @var list<array<string, mixed>> */
    private array $lastOutstanding = [];

    private ?string $lastTarget = null;

    private ?string $lastCommit = null;

    private bool $lastAssessable = true;

    /**
     * The canonical release the last assessment was about, from the manifest.
     *
     * Not the same string as the running identity on a source deployment, where
     * the identity is `<version>-dev+<sha>` and the manifest is stamped with the
     * canonical `VERSION`. Whatever is recorded as this install's release has to
     * be the one the guard compares against, or it never equals its own target
     * and cannot be ordered against a floor.
     */
    public function lastTarget(): ?string
    {
        return $this->lastTarget;
    }

    /**
     * The commit the last assessment's manifest carried.
     *
     * Recorded alongside the version for the same reason: `buildChanged()`
     * compares what is on record against the MANIFEST, so persisting the runtime
     * identity instead means an overridden or stale `WAYFINDR_COMMIT` reads as a
     * different build on the very next request — dropping a fresh install's
     * exemption and gating serving on upgrade-only work it never owed.
     */
    public function lastCommit(): ?string
    {
        return $this->lastCommit;
    }

    /**
     * Whether the last assessment could be made at all.
     *
     * A refusal with no actions has two very different causes. The FLOOR is one:
     * the release is running perfectly and simply cannot be upgraded to from
     * here, so serving carries on. An unreadable manifest or history is the
     * other: nothing is known about what this release owes, and an empty action
     * list means "could not look", not "nothing outstanding".
     *
     * `assessAll()` returns only the action list, so those two were
     * indistinguishable to the serving gate — which read the unreadable case as
     * clear and served traffic that an unmet after-start action should have
     * stopped.
     */
    public function lastAssessable(): bool
    {
        return $this->lastAssessable;
    }

    public function __construct(
        private readonly ReleaseState $state,
        private readonly CheckRegistry $checks,
        private readonly UpgradeContext $context,
    ) {}

    /**
     * Every outstanding action, whatever its phase.
     *
     * The serving gate needs the after-start ones, which assess() deliberately
     * filters out because they must not block migration.
     *
     * @return list<array<string, mixed>>
     */
    public function assessAll(): array
    {
        // Target-inclusive: an after-start requirement belongs to the release
        // that is RUNNING, and stays in force until satisfied. Once the release
        // is recorded the ordinary span is (target, target] — empty — so without
        // this the serving gate would silently stop enforcing anything.
        $this->assess(includeTarget: true);

        return $this->lastOutstanding;
    }

    /**
     * What this upgrade still owes before it may migrate.
     *
     * @return array{blocked: bool, reason: string, actions: list<array<string, mixed>>, from: ?string, target: ?string, legacy: bool, floor: ?string}
     */
    public function assess(bool $includeTarget = false): array
    {
        $this->lastTarget = null;
        $this->lastCommit = null;
        $this->lastAssessable = true;

        try {
            $manifest = $this->read($this->manifestPath());
        } catch (Throwable) {
            $this->lastAssessable = false;

            return [
                'blocked' => true,
                'reason' => 'the release manifest is present but could not be read',
                'actions' => [],
                'from' => $this->state->recordedVersion(),
                'target' => null,
                'legacy' => false,
                'floor' => null,
            ];
        }

        // No baked declaration means a development checkout or a build that
        // predates this mechanism. Nothing is declared, so nothing is enforced —
        // this is what keeps `artisan migrate` ordinary for contributors.
        if ($manifest === null) {
            return $this->clear('no release manifest is baked into this build');
        }

        $target = $manifest['version'] ?? null;

        if (! is_string($target)) {
            return $this->clear('the baked manifest names no version');
        }

        $this->lastTarget = $target;

        $manifestCommit = $manifest['commit'] ?? null;
        $this->lastCommit = is_string($manifestCommit) && trim($manifestCommit) !== ''
            ? $manifestCommit
            : null;

        $history = $this->history();

        // Present but unreadable. Refusing is recoverable — the history ships
        // with the release, so repulling the image or the checkout replaces it —
        // and the alternative is migrating past requirements nobody could read.
        if ($history === null) {
            $this->lastAssessable = false;

            return [
                'blocked' => true,
                'reason' => 'the published release history is missing or could not be read',
                'actions' => [],
                'from' => $this->state->recordedVersion(),
                'target' => $target,
                'legacy' => false,
                'floor' => null,
            ];
        }

        // The manifest being installed is AUTHORITATIVE for its own version, so it
        // replaces any history entry claiming that version rather than merely
        // filling in when one is absent.
        //
        // A committed history entry for the target can be older than the build
        // installing it — a source commit under the same `VERSION` made after
        // that release was appended to the history. Declining to append left the
        // stale entry in the span, so an action this build declares was invisible
        // and the migration proceeded without it. Re-including the target does
        // not help when what the span selects is the wrong copy.
        $history = array_values(array_filter(
            $history,
            static fn (array $entry): bool => ($entry['version'] ?? null) !== $target,
        ));

        $history[] = $manifest;

        // An install predating the state file has no recorded release. The
        // operator may state where it is instead, which is what keeps the floor
        // check below from being a refusal they cannot clear.
        $recorded = $this->state->recordedVersion() ?? $this->declaredOrigin();
        // Asked only when it can change the answer. Both `$legacy` and `$fresh`
        // require a null `$recorded`, so on any recorded install this was three
        // database round trips — a connection check, a table lookup and an
        // existence query — added by the serving middleware to every non-health
        // HTTP request, to compute a value that could not affect the outcome.
        $existing = $recorded === null ? $this->hasExistingInstall() : true;
        $legacy = $recorded === null && $existing;

        // No state file AND no prior schema: nothing to upgrade from. Left as a
        // null start it would take the legacy path and evaluate the whole
        // history, so a brand-new install of a later image would be handed
        // upgrade-only work from releases it never ran.
        $fresh = $recorded === null && ! $existing;

        // Migrating destroys the evidence for that reading: the first thing
        // `migrate` does is populate the migrations table, so the SAME install
        // reads fresh before and legacy after. Anything assessing post-migration
        // — the recorder, which must decide whether this install owes anything —
        // has to be told what was true beforehand.
        if ($recorded === null) {
            $this->context->observeFreshInstall($fresh);
            $fresh = $this->context->wasFreshInstall() ?? $fresh;
        }

        // And once recorded, the reading has to survive into the serving gate,
        // which runs in a different process entirely. Scoped to the exact release
        // it was recorded at: an install that was fresh at 0.2.0 is not fresh
        // when it later upgrades to 0.3.0, and must be evaluated normally then.
        // Scoped to the exact BUILD, not just the version. A source deployment
        // stamps every commit of a cycle with the same VERSION, so a database
        // first installed by one commit would keep its fresh exemption when a
        // later commit adds an action under that same version — and a fresh
        // install short-circuits to "nothing outstanding" before the span is even
        // consulted, so re-including the target would not save it.
        if (! $fresh
            && $recorded !== null
            && $recorded === $target
            && ! $this->buildChanged($manifest)
            && $this->state->wasFreshInstall()) {
            $fresh = true;
        }

        // The floor: releases below it cannot upgrade directly, because the
        // migration path that would carry them has been retired. This is checked
        // before requirements, since an unsupported jump is not something an
        // acknowledgement can make safe - there is no work the operator could do
        // to make the missing migrations exist.
        $floor = $manifest['minimum_upgrade_from'] ?? null;

        if (is_string($floor) && $recorded === null && ! $fresh) {
            // A legacy install with a floor in force. Its origin is unknown, so
            // it MAY be below the floor — and "may be" is not permission to run
            // migrations whose path was explicitly retired. Skipping the check
            // because there is nothing to compare against is the fail-open
            // reading of missing evidence.
            //
            // Clearable two ways, both of which establish the origin rather than
            // override the floor: upgrade to the floor release first (which
            // records state), or state the current version outright.
            return [
                'blocked' => true,
                'reason' => 'this install has no recorded release, so the upgrade floor cannot be verified',
                'actions' => [],
                'from' => null,
                'target' => $target,
                'legacy' => true,
                'floor' => $floor,
            ];
        }

        if (is_string($floor) && $recorded !== null) {
            $comparison = VersionComparator::compare($recorded, $floor);

            // Only a definite "below" refuses. A comparison with no answer - a
            // development version on either side - is not evidence of an
            // unsupported jump, and refusing on it would strand source installs
            // that are perfectly current.
            if ($comparison !== null && $comparison < 0) {
                return [
                    'blocked' => true,
                    'reason' => 'below the minimum upgrade floor',
                    'actions' => [],
                    'from' => $recorded,
                    'target' => $target,
                    'legacy' => $legacy,
                    'floor' => $floor,
                ];
            }
        }

        // A source deployment stamps every build of a cycle with the same
        // `VERSION`, so `recorded === target` does NOT mean "this release has
        // already been dealt with" — a later commit can add an action under the
        // same version. Without this the span is (target, target], empty, and the
        // newly declared pre-migration action is skipped; serving cannot catch it
        // either, since that gates only after-start.
        //
        // The commit is what tells the two apart. Equal and both known means the
        // same build; anything else is treated as changed, which re-evaluates
        // actions that are already satisfied and finds them satisfied.
        if (! $includeTarget && $recorded === $target && $this->buildChanged($manifest)) {
            $includeTarget = true;
        }

        // The span starts at the last release that was genuinely CLEAN, not
        // simply the last one that migrated.
        //
        // A v1 -> v3 upgrade passes through v2, and an after-start action of
        // v2's cannot block the migration — it needs the migrated schema to be
        // performed at all. So the migration completes and v3 is recorded. Read
        // the span from what is running and it collapses to (v3, v3]: v2's
        // requirement is outstanding one moment and gone the next, and the
        // serving gate opens on work nobody did.
        //
        // Falls back to the recorded version, which is correct for an install
        // that has only ever been clean and for one that predates the marker.
        // A written null means "unknown origin, still owing" and must stay null;
        // only an ABSENT marker falls back to the recorded release. Collapsing
        // the two drops a legacy upgrade's outstanding intermediate work the
        // moment the target is recorded — the same disappearance the marker
        // exists to prevent, reached through its own fallback.
        $from = $this->state->satisfiedThroughRecorded()
            ? $this->state->satisfiedThrough()
            : $recorded;

        $outstanding = UpgradeRequirements::outstanding(
            $history,
            $from,
            $target,
            UpgradeRequirements::parseAcknowledged($this->acknowledged()),
            fn (string $name): ?bool => $this->checks->evaluate($name),
            freshInstall: $fresh,
            includeTarget: $includeTarget,
            // The release this install is actually running. The span may start
            // further back to keep unpaid debt in view, but a newly traversed
            // action's `upgrade-from` must be measured from here — measuring it
            // from the retained origin would decide the install never reached a
            // release it has been running for an upgrade or more.
            traversedFrom: $recorded,
        );

        $this->lastOutstanding = $outstanding;

        // Only the phases that must precede the schema change may block it. An
        // unmet after-start action needs the migrated schema to be performed at
        // all, so blocking migration on it could never be satisfied.
        //
        // Except when the action needs a release the pull replaced. That work
        // cannot be performed at any phase — so letting such an after-start
        // action through migration only to gate serving leaves the install
        // migrated, refusing traffic, and holding a requirement with no way to
        // satisfy it. The installer preflight already refuses these; every other
        // path here (the container entrypoint, both Forge scripts, a manual
        // migrate) had no such check.
        //
        // Both halves of that rule now live in ActionDisposition::blocksMigration()
        // so this filter and the two operator messages cannot disagree about
        // which actions stop the schema change. This line previously passed a
        // THIRD argument to the two-parameter stranded(), which PHP silently
        // discarded — harmless, but it read as though the filter weighed the
        // current release when it never did (#647).
        $blocking = array_values(array_filter(
            $outstanding,
            static fn (array $a): bool => UpgradeRequirements::disposition($a, $target, $recorded)
                ->blocksMigration(is_string($a['phase'] ?? null) ? $a['phase'] : ''),
        ));

        return [
            'blocked' => $blocking !== [],
            'reason' => $blocking === [] ? 'no outstanding pre-migration requirements' : 'requirements outstanding',
            'actions' => $blocking,
            'from' => $recorded,
            'target' => $target,
            'legacy' => $legacy,
            'floor' => null,
        ];
    }

    /**
     * @return array{blocked: bool, reason: string, actions: list<array<string, mixed>>, from: ?string, target: ?string, legacy: bool, floor: ?string}
     */
    /**
     * Whether the build on record differs from the one being assessed.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function buildChanged(array $manifest): bool
    {
        $recordedCommit = $this->state->recordedCommit();
        $currentCommit = $manifest['commit'] ?? null;

        // Only two known, equal commits mean the same build. An unknown on either
        // side is not evidence of sameness, and assuming it would skip exactly the
        // actions this exists to catch.
        return ! (is_string($recordedCommit)
            && is_string($currentCommit)
            && $recordedCommit === $currentCommit);
    }

    private function clear(string $reason): array
    {
        return [
            'blocked' => false,
            'reason' => $reason,
            'actions' => [],
            'from' => null,
            'target' => null,
            'legacy' => false,
            'floor' => null,
        ];
    }

    /**
     * Declarations of every release from the floor up to this one.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * The published history, or NULL when it cannot be trusted.
     *
     * Absent counts as untrustworthy here, which it did not before. This is only
     * ever called once a manifest has been read, and both producers write the
     * pair together — the image build emits `--out` and `--history` from one
     * invocation, and the Forge deploy generates the manifest beside the history
     * committed with the release. So a manifest with no history beside it is an
     * incomplete artifact or a partial checkout, not a release predating the
     * mechanism.
     *
     * Read as "no prior release declared anything" it reduces a v1 -> v3 upgrade
     * to the target alone, skipping every intermediate requirement — which is the
     * failure this whole file exists to prevent, reached by finding nothing.
     *
     * @return ?list<array<string, mixed>>
     */
    private function history(): ?array
    {
        $raw = $this->readRaw($this->historyPath());

        if ($raw === null) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decoded) || ! is_array($decoded['releases'] ?? null)) {
            return null;
        }

        $releases = [];
        $seenVersions = [];

        foreach ($decoded['releases'] as $release) {
            // Dropping the entry would shorten the history rather than reject it,
            // and a release that quietly disappears takes its before-pull and
            // after-pull requirements with it. Valid JSON is not the same as a
            // history we can trust.
            if (! is_array($release)) {
                return null;
            }

            // Validated as a published manifest, not merely as an array. An entry
            // like `{"version":"0.2.0"}` is structurally an array and contributes
            // no actions - and if its version matches the target it also makes
            // historyContains() true, which suppresses the REAL target manifest
            // being appended and takes its pre-migration requirements with it.
            try {
                ReleaseManifest::assertPublished($release);
            } catch (Throwable) {
                return null;
            }

            // One entry per release, across the WHOLE history.
            //
            // Each manifest validates on its own, so two entries for the same
            // canonical release both pass — and an action id reused between them
            // gives two different pieces of work the same
            // `<release>/<action-id>` acknowledgement key. Acknowledging either
            // then settles both. The builder dedupes on canonical version, so a
            // repeat here means the file was edited or merged by something else.
            $version = $release['version'];

            if (isset($seenVersions[$version])) {
                return null;
            }

            $seenVersions[$version] = true;

            $releases[] = $release;
        }

        return $releases;
    }

    /**
     * @param  list<array<string, mixed>>  $history
     */
    private function historyContains(array $history, string $version): bool
    {
        foreach ($history as $manifest) {
            if (($manifest['version'] ?? null) === $version) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * The decoded manifest, or null when there is no manifest file at all.
     *
     * Throws when the file is PRESENT but unreadable. Those are different
     * answers: absent means a development checkout or a build predating this
     * mechanism, and must migrate as normal; unreadable means the release cannot
     * say what it requires, and answering "then it requires nothing" disables
     * the guard entirely — floor included — on the strength of a corrupt file.
     *
     * It is recoverable, which is what makes refusing the right call: the
     * manifest ships with the release, so repulling the image or the checkout
     * replaces it.
     */
    private function read(string $path): ?array
    {
        $raw = $this->readRaw($path);

        if ($raw === null) {
            return null;
        }

        return ReleaseManifest::decode($raw);
    }

    private function readRaw(string $path): ?string
    {
        return is_file($path) ? ((string) @file_get_contents($path)) : null;
    }

    /**
     * Has this install been running, even though it has no state file?
     *
     * Every install predating the state file has none, so absence alone cannot
     * mean "fresh" — reading it that way would evaluate no span for exactly the
     * population the history exists to protect. A populated `migrations` table
     * is the evidence, and it belongs to the OLD schema so it is readable here.
     */
    /**
     * Whether this database already carries migrations.
     *
     * Throws when the question cannot be answered at all. "Cannot reach the
     * database" was being answered "this is a fresh install", which exempts the
     * entire history AND the floor — so a connection that blips during the
     * guard's check and recovers before the migrator's own access would let a
     * legacy install migrate with every requirement skipped. That is the exact
     * population this guard exists for.
     *
     * The missing table is asked about separately from the query, because those
     * two failures arrive as the same exception type from most drivers.
     */
    private function hasExistingInstall(): bool
    {
        // Forces a connection. A failure here is an infrastructure problem, and
        // it propagates rather than being read as a statement about the schema.
        DB::connection()->getPdo();

        if (! Schema::hasTable('migrations')) {
            return false;
        }

        return DB::table('migrations')->exists();
    }
}
