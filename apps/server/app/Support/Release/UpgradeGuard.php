<?php

declare(strict_types=1);

namespace App\Support\Release;

use App\Support\Version\VersionComparator;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\DB;
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
        $live = getenv(self::ACKNOWLEDGED_ENV);

        if (is_string($live) && $live !== '') {
            return $live;
        }

        $fromFile = $this->fromEnvironmentFile();

        if ($fromFile !== null) {
            return $fromFile;
        }

        $configured = config('wayfindr.release.acknowledged_actions');

        return is_string($configured) ? $configured : null;
    }

    /**
     * Parsed with the same library that loads `.env` normally, so quoting,
     * escapes and interpolation behave identically to an uncached boot. A
     * hand-rolled grep would differ from the parser on exactly the values people
     * quote because they contain something awkward.
     */
    private function fromEnvironmentFile(): ?string
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

        $value = $values[self::ACKNOWLEDGED_ENV] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @var list<array<string, mixed>> */
    private array $lastOutstanding = [];

    public function __construct(
        private readonly ReleaseState $state,
        private readonly CheckRegistry $checks,
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
        $manifest = $this->read($this->manifestPath());

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

        $history = $this->history();

        // The target's own declaration may not be in the baked history yet — a
        // source build, or a release cut before the history step ran. Include it
        // so an upgrade is never evaluated without the release it is upgrading TO.
        if (! $this->historyContains($history, $target)) {
            $history[] = $manifest;
        }

        $recorded = $this->state->recordedVersion();
        $existing = $this->hasExistingInstall();
        $legacy = $recorded === null && $existing;

        // No state file AND no prior schema: nothing to upgrade from. Left as a
        // null start it would take the legacy path and evaluate the whole
        // history, so a brand-new install of a later image would be handed
        // upgrade-only work from releases it never ran.
        $fresh = $recorded === null && ! $existing;

        // The floor: releases below it cannot upgrade directly, because the
        // migration path that would carry them has been retired. This is checked
        // before requirements, since an unsupported jump is not something an
        // acknowledgement can make safe - there is no work the operator could do
        // to make the missing migrations exist.
        $floor = $manifest['minimum_upgrade_from'] ?? null;

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
        $from = $this->state->satisfiedThrough() ?? $recorded;

        $outstanding = UpgradeRequirements::outstanding(
            $history,
            $from,
            $target,
            UpgradeRequirements::parseAcknowledged($this->acknowledged()),
            fn (string $name): ?bool => $this->checks->evaluate($name),
            freshInstall: $fresh,
            includeTarget: $includeTarget,
        );

        $this->lastOutstanding = $outstanding;

        // Only the phases that must precede the schema change may block it. An
        // unmet after-start action needs the migrated schema to be performed at
        // all, so blocking migration on it could never be satisfied.
        $blocking = array_values(array_filter(
            $outstanding,
            static fn (array $a): bool => in_array(
                $a['phase'] ?? '', UpgradeRequirements::BLOCKS_MIGRATION, true,
            ),
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
    private function history(): array
    {
        $raw = $this->readRaw($this->historyPath());

        if ($raw === null) {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($decoded) || ! is_array($decoded['releases'] ?? null)) {
            return [];
        }

        $releases = [];

        foreach ($decoded['releases'] as $release) {
            if (is_array($release)) {
                $releases[] = $release;
            }
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
    private function read(string $path): ?array
    {
        $raw = $this->readRaw($path);

        if ($raw === null) {
            return null;
        }

        try {
            return ReleaseManifest::decode($raw);
        } catch (Throwable) {
            // A manifest we cannot read is not a manifest that permits anything,
            // but refusing every migration on a corrupt file would strand an
            // install with no way forward. Treated as absent and surfaced by the
            // command, which reports the reason.
            return null;
        }
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
    private function hasExistingInstall(): bool
    {
        try {
            return DB::table('migrations')->exists();
        } catch (Throwable) {
            // No table, or no database yet: a genuinely fresh install.
            return false;
        }
    }
}
