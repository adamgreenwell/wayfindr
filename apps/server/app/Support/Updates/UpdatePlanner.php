<?php

declare(strict_types=1);

namespace App\Support\Updates;

use App\Support\Release\ActionDisposition;
use App\Support\Release\ReleaseManifest;
use App\Support\Release\UpgradeGuard;
use App\Support\Release\UpgradeRequirements;
use App\Support\Version\SemanticVersion;
use App\Support\Version\VersionComparator;
use InvalidArgumentException;

/** Read-only candidate assessment shared by CLI and future operator controls. */
final class UpdatePlanner
{
    public function __construct(private readonly UpgradeGuard $guard) {}

    public function build(ReleaseCatalog $catalog, InstallationCapabilities $installation): UpdatePlan
    {
        $manifest = $catalog->targetManifest;
        ReleaseManifest::assertPublished($manifest);
        $parsedTarget = SemanticVersion::parse($manifest['version']);

        if ($parsedTarget === null || $parsedTarget->prerelease !== [] || $parsedTarget->build !== null
            || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $manifest['commit']) !== 1
            || ($catalog->provenance['history_complete'] ?? false) !== true
            || ($catalog->provenance['commit'] ?? null) !== $manifest['commit']
            || $catalog->tag !== 'v'.$manifest['version']
            || preg_match('/^sha256:[a-f0-9]{64}$/', $catalog->imageDigest) !== 1) {
            throw new InvalidArgumentException('The release catalog does not establish a complete, exact target.');
        }

        // Capture running identity before checks. Candidate assessment captures
        // recorded identity/debt, environment attestations and check outcomes.
        $runtimeVersion = config('wayfindr.release.version');
        $runtimeCommit = config('wayfindr.release.commit');
        $runtimeVersion = is_string($runtimeVersion) ? $runtimeVersion : null;
        $runtimeCommit = is_string($runtimeCommit) ? $runtimeCommit : null;
        $assessment = $this->guard->assessTarget($manifest, $catalog->history);
        $state = $assessment['source_state'];
        $comparison = VersionComparator::compare($runtimeVersion, $manifest['version']);
        $knownCommit = is_string($runtimeCommit) && preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/i', $runtimeCommit) === 1;
        $sameBuild = $knownCommit && $runtimeCommit === $manifest['commit']
            && VersionComparator::sameBuild($runtimeVersion, $manifest['version'], $runtimeCommit, $manifest['commit']) === true;

        $recordedVersion = is_string($state['version'] ?? null) ? $state['version'] : null;
        $recordedCommit = is_string($state['commit'] ?? null) ? $state['commit'] : null;
        $parsedRuntime = SemanticVersion::parse($runtimeVersion);
        $parsedRecorded = SemanticVersion::parse($recordedVersion);
        $stateConflict = ($parsedRuntime !== null && ! $parsedRuntime->isDevelopment()
            && $parsedRecorded !== null && ! $parsedRecorded->isDevelopment()
            && VersionComparator::sameBuild($recordedVersion, $runtimeVersion) !== true)
            || ($knownCommit && is_string($recordedCommit)
                && preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/i', $recordedCommit) === 1
                && strtolower($recordedCommit) !== strtolower($runtimeCommit));

        $imageConflict = $installation->imageReference !== null && $runtimeVersion !== null
            && preg_match('~^ghcr\.io/adamgreenwell/wayfindr:([^@]+)(?:@sha256:[a-f0-9]{64})?$~', $installation->imageReference, $matches) === 1
            && VersionComparator::sameBuild($matches[1], $runtimeVersion) === false;

        $status = match (true) {
            $stateConflict || $imageConflict => 'identity_conflict',
            $assessment['blocked'] => 'blocked',
            $comparison > 0 => 'downgrade_refused',
            $comparison === null || ! $knownCommit => 'identity_unknown',
            $sameBuild && $assessment['outstanding'] !== [] => 'requirements_outstanding',
            $sameBuild => 'up_to_date',
            default => 'update_available',
        };

        $blockers = $installation->managedBlockers();

        if ($stateConflict) {
            $blockers[] = 'source_state_identity_mismatch';
        }

        if ($imageConflict) {
            $blockers[] = 'source_image_identity_mismatch';
        }

        if ($installation->runtimeProfile !== $assessment['installation_profile']) {
            $blockers[] = 'runtime_profile_mismatch';
        }

        if ($comparison === null || ! $knownCommit) {
            $blockers[] = 'source_identity_unknown';
        }

        if ($status !== 'update_available') {
            $blockers[] = 'release_'.$status;
        }

        // Ownership reports are separate from artifact compatibility. Missing
        // index evidence cannot establish that a target supports this machine.
        $platformKey = $installation->platform.'/'.$installation->architecture;
        $platformEvidence = $catalog->platforms[$platformKey] ?? null;

        if (! is_array($platformEvidence)
            || ! is_string($platformEvidence['manifest_digest'] ?? null)
            || ! is_string($platformEvidence['config_digest'] ?? null)
            || preg_match('/^sha256:[a-f0-9]{64}$/', $platformEvidence['manifest_digest'] ?? '') !== 1
            || preg_match('/^sha256:[a-f0-9]{64}$/', $platformEvidence['config_digest'] ?? '') !== 1) {
            $blockers[] = $catalog->platforms === [] ? 'target_platform_unverified' : 'target_platform_unsupported';
        }

        $actions = array_map(function (array $action) use ($assessment, $manifest): array {
            $disposition = UpgradeRequirements::disposition($action, $manifest['version'], $assessment['from']);
            $acknowledgeable = $disposition->acknowledgeable() && ($action['satisfied_by'] ?? null) !== 'failed';
            $guidance = match ($disposition) {
                ActionDisposition::Unreachable => 'Upgrade to '.$action['release'].' first and complete this work there.',
                ActionDisposition::PerformableNow => 'Complete this work on the running release before replacing its image.',
                ActionDisposition::Performable => 'Complete this work in its declared phase; recheck before continuing.',
            };

            return array_replace($action, [
                'disposition' => $disposition->value,
                'blocks_migration' => $disposition->blocksMigration($action['phase']),
                'acknowledgeable' => $acknowledgeable,
                'acknowledgement_key' => $acknowledgeable ? $action['release'].'/'.$action['id'] : null,
                'guidance' => $guidance,
            ]);
        }, $assessment['outstanding']);

        return new UpdatePlan([
            'status' => $status,
            'source' => [
                'runtime_version' => $runtimeVersion,
                'runtime_commit' => $runtimeCommit,
                'runtime_profile' => $assessment['installation_profile'],
                'recorded_version' => $state['version'] ?? null,
                'recorded_commit' => $state['commit'] ?? null,
                'recorded_profile' => $state['installation_profile'] ?? null,
                'satisfied_through_recorded' => array_key_exists('satisfied_through', $state),
                'satisfied_through' => $state['satisfied_through'] ?? null,
                'fresh_install' => $assessment['fresh_install'],
                'declared_origin' => $assessment['declared_origin'],
                'image_reference' => $installation->imageReference,
                'installation_id' => $installation->installationId,
            ],
            'target' => [
                'tag' => $catalog->tag,
                'version' => $manifest['version'],
                'commit' => $manifest['commit'],
                'image_digest' => $catalog->imageDigest,
                'image_reference' => 'ghcr.io/adamgreenwell/wayfindr:'.$manifest['version'].'@'.$catalog->imageDigest,
                'platform' => $platformKey,
                'platform_evidence' => $platformEvidence,
                'release_notes' => $catalog->releaseNotes,
                'release_url' => $catalog->releaseUrl,
            ],
            'provenance' => $catalog->provenance,
            // Bind declarations themselves as well as their published hashes.
            'declarations' => ['manifest' => $manifest, 'history' => $catalog->history],
            'installation' => $installation->toArray(),
            'release_requirements' => [
                'migration_blocked' => $assessment['blocked'],
                'reason' => $assessment['reason'],
                'minimum_upgrade_from' => $manifest['minimum_upgrade_from'],
                'floor_refused' => $assessment['floor'] !== null,
                'legacy_origin' => $assessment['legacy'],
                'actions' => $actions,
                'acknowledgements' => $assessment['acknowledgements'],
                'check_evidence' => $assessment['check_evidence'],
            ],
            'advisory_notices' => $assessment['notices'],
            'managed' => [
                'eligible' => $blockers === [],
                'blockers' => array_values(array_unique($blockers)),
                'optional_backup_policy' => $installation->managedPolicyClaims,
                'policy_assessed' => false,
                'execution_available' => false,
            ],
            'manual' => [
                'prerequisites_clear' => ! $stateConflict && ! $imageConflict && ! $assessment['blocked'] && $comparison !== null && $comparison <= 0,
                'guidance' => $installation->guidance(),
                'helper_required' => false,
                'new_backup_setup_required' => false,
            ],
            'effects' => [
                'migrations' => 'Guarded application migrations may run during apply. Their exact list and duration are not declared by this release format.',
                'migration_list' => null,
                'duration_seconds' => null,
                'interruption_expected' => true,
                'interruption' => 'Application services restart; active sessions and jobs may be interrupted. Browser reconnection does not cancel an update.',
            ],
            'recovery' => [
                'before_schema_mutation' => 'The executor may retain the previous image and configuration before schema changes begin.',
                'after_schema_mutation' => 'Changed or uncertain schema state needs explicit recovery in maintenance. Switching images does not restore the database.',
                'automatic_database_restore' => false,
                'automatic_post_migration_rollback' => false,
            ],
            'recheck_required_before_execution' => true,
        ]);
    }
}
