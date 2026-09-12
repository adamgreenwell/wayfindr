#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace Wayfindr\ReleaseContract;

use App\Support\Release\ReleaseManifest;
use App\Support\Version\SemanticVersion;
use App\Support\Version\VersionComparator;
use JsonException;
use RuntimeException;
use Throwable;

require_once __DIR__.'/../apps/server/app/Support/Version/SemanticVersion.php';
require_once __DIR__.'/../apps/server/app/Support/Version/VersionComparator.php';
require_once __DIR__.'/../apps/server/app/Support/Release/ReleaseManifest.php';

/**
 * The human release verdict, the authored declaration, and VERSION are one
 * contract (ADR 0012). The release builder validates the declaration itself;
 * this guard validates the seams between those three independently-authored
 * files before a tag can turn their disagreement into operator instructions.
 */

/** @return array<string, mixed> */
function generatedManifest(string $root, string $version): array
{
    $command = [
        PHP_BINARY,
        $root.'/scripts/release/build-manifest.php',
        '--version='.$version,
        '--commit=contract-test',
    ];

    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $root);

    if (! is_resource($process)) {
        throw new RuntimeException('could not start the release-manifest builder.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $status = proc_close($process);

    if ($status !== 0) {
        $detail = trim($stderr);

        throw new RuntimeException(
            'the release-manifest builder failed'.($detail === '' ? '.' : ":\n{$detail}")
        );
    }

    try {
        $manifest = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException(
            'the release-manifest builder did not return valid JSON: '.$exception->getMessage()
        );
    }

    if (! is_array($manifest)) {
        throw new RuntimeException('the release-manifest builder did not return an object.');
    }

    return $manifest;
}

function requiredFile(string $path): string
{
    $contents = @file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("could not read {$path}.");
    }

    return $contents;
}

function candidateVersion(string $contents): SemanticVersion
{
    $version = trim($contents);

    // VERSION names the next stable line. Source builds add their own -dev
    // identity later; allowing it here makes the patch/minor question
    // indeterminate exactly where this guard is meant to answer it.
    if (preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/', $version) !== 1) {
        throw new RuntimeException(
            "VERSION must contain one plain stable SemVer (x.y.z); found \"{$version}\"."
        );
    }

    $parsed = SemanticVersion::parse($version);

    if ($parsed === null) {
        throw new RuntimeException("VERSION \"{$version}\" could not be parsed.");
    }

    return $parsed;
}

function normalizeMarkdown(string $markdown): string
{
    $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

    return str_starts_with($markdown, "\xEF\xBB\xBF")
        ? substr($markdown, 3)
        : $markdown;
}

function withoutHtmlComments(string $markdown): string
{
    return preg_replace('/<!--.*?-->/s', '', $markdown) ?? $markdown;
}

function hasVisibleContent(string $markdown): bool
{
    return trim(withoutHtmlComments($markdown)) !== '';
}

/**
 * Return bracketed level-two sections without letting an example inside a
 * fenced code block terminate the real release section.
 *
 * @return array<string, string>
 */
function releaseSections(string $markdown): array
{
    $sections = [];
    $dates = [];
    $current = null;
    $body = [];
    $fenceCharacter = null;
    $fenceLength = 0;

    $store = static function () use (&$sections, &$current, &$body): void {
        if ($current === null) {
            return;
        }

        if (array_key_exists($current, $sections)) {
            throw new RuntimeException("CHANGELOG contains more than one [{$current}] section.");
        }

        $sections[$current] = implode("\n", $body);
        $current = null;
        $body = [];
    };

    foreach (explode("\n", normalizeMarkdown($markdown)) as $line) {
        if ($fenceCharacter !== null) {
            if ($current !== null) {
                $body[] = $line;
            }

            $closing = '/^[ ]{0,3}'.preg_quote($fenceCharacter, '/').'{'.$fenceLength.',}[ \t]*$/';

            if (preg_match($closing, $line) === 1) {
                $fenceCharacter = null;
                $fenceLength = 0;
            }

            continue;
        }

        if (preg_match('/^[ ]{0,3}(`{3,}|~{3,})/', $line, $fence) === 1) {
            if ($current !== null) {
                $body[] = $line;
            }

            $fenceCharacter = $fence[1][0];
            $fenceLength = strlen($fence[1]);

            continue;
        }

        if (preg_match('/^##(?:[ \t]+|$)/', $line) === 1) {
            $store();

            if (preg_match('/^##[ \t]+\[([^\]]+)](?:[ \t]+-[ \t]+(.*))?[ \t]*$/', $line, $heading) === 1) {
                $current = trim($heading[1]);
                // Captured HERE rather than by a second scan of the raw lines,
                // because this loop is the one that skips fenced blocks. A
                // separate scanner reads a heading inside a ```markdown example
                // as if it were the real section.
                $dates[$current] = isset($heading[2]) && trim($heading[2]) !== ''
                    ? trim($heading[2])
                    : null;
            }

            continue;
        }

        if ($current !== null) {
            $body[] = $line;
        }
    }

    $store();

    return ['bodies' => $sections, 'dates' => $dates];
}

/** @return array<string, string> */
function subsections(string $section): array
{
    $sections = [];
    $current = null;
    $body = [];
    $fenceCharacter = null;
    $fenceLength = 0;

    $store = static function () use (&$sections, &$current, &$body): void {
        if ($current === null) {
            return;
        }

        if (array_key_exists($current, $sections)) {
            throw new RuntimeException("release notes contain more than one {$current} subsection.");
        }

        $sections[$current] = implode("\n", $body);
        $current = null;
        $body = [];
    };

    foreach (explode("\n", normalizeMarkdown($section)) as $line) {
        if ($fenceCharacter !== null) {
            if ($current !== null) {
                $body[] = $line;
            }

            $closing = '/^[ ]{0,3}'.preg_quote($fenceCharacter, '/').'{'.$fenceLength.',}[ \t]*$/';

            if (preg_match($closing, $line) === 1) {
                $fenceCharacter = null;
                $fenceLength = 0;
            }

            continue;
        }

        if (preg_match('/^[ ]{0,3}(`{3,}|~{3,})/', $line, $fence) === 1) {
            if ($current !== null) {
                $body[] = $line;
            }

            $fenceCharacter = $fence[1][0];
            $fenceLength = strlen($fence[1]);

            continue;
        }

        if (preg_match('/^###[ \t]+(.+?)[ \t]*$/', $line, $heading) === 1) {
            $store();
            $current = trim($heading[1]);

            continue;
        }

        if ($current !== null) {
            $body[] = $line;
        }
    }

    $store();

    return $sections;
}

function humanVerdict(string $section): bool
{
    $visible = withoutHtmlComments(normalizeMarkdown($section));

    foreach (preg_split('/\n[ \t]*\n/', $visible) ?: [] as $paragraph) {
        $paragraph = trim((string) preg_replace('/[ \t]*\n[ \t]*/', ' ', $paragraph));

        if ($paragraph === '') {
            continue;
        }

        if (preg_match('/^\*\*No operator action required\.\*\*(?:\s|$)/u', $paragraph) === 1) {
            return false;
        }

        if (preg_match('/^\*\*Requires operator action(?=\s|[.:,;—-]|\*\*)(?:(?!\*\*).)*\*\*(?:\s|$)/u', $paragraph) === 1) {
            return true;
        }

        throw new RuntimeException(
            'the active changelog section must open with a bold "Requires operator action" '
            .'or "No operator action required" verdict.'
        );
    }

    throw new RuntimeException('the active changelog section has no operator-action verdict.');
}

function hasAddedContent(string $section): bool
{
    $added = subsections($section)['Added'] ?? null;

    return $added !== null && hasVisibleContent($added);
}

/**
 * @return array{current_recorded: bool, current_manifest: array<string, mixed>|null, previous: SemanticVersion|null}
 */
function historyContext(string $contents, SemanticVersion $candidate): array
{
    try {
        $history = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('releases/history.json is invalid: '.$exception->getMessage());
    }

    if (! is_array($history)
        || ($history['schema'] ?? null) !== ReleaseManifest::SCHEMA
        || ! is_array($history['releases'] ?? null)
        || ! array_is_list($history['releases'])) {
        throw new RuntimeException('releases/history.json is not a valid release history.');
    }

    $previous = null;
    $currentRecorded = false;
    $currentManifest = null;
    $seenVersions = [];

    foreach ($history['releases'] as $index => $release) {
        if (! is_array($release)) {
            throw new RuntimeException("release history entry #{$index} is not an object.");
        }

        try {
            ReleaseManifest::assertPublished($release);
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                "release history entry #{$index} is invalid: {$throwable->getMessage()}"
            );
        }

        $raw = $release['version'];
        $version = SemanticVersion::parse($raw);

        if ($version === null || $version->isDevelopment()) {
            throw new RuntimeException("release history entry #{$index} has an unusable version.");
        }

        if (isset($seenVersions[$raw])) {
            throw new RuntimeException("release history repeats release {$raw}.");
        }

        $seenVersions[$raw] = true;

        $comparison = VersionComparator::compare($version->canonical(), $candidate->canonical());

        if ($comparison === null) {
            throw new RuntimeException(
                "could not compare historical version {$version->canonical()} with VERSION {$candidate->canonical()}."
            );
        }

        if ($comparison > 0) {
            throw new RuntimeException(
                "VERSION {$candidate->canonical()} is older than published history {$version->canonical()}."
            );
        }

        if ($comparison === 0) {
            $currentRecorded = true;
            $currentManifest = $release;

            continue;
        }

        // A prerelease is not the baseline for deciding whether the next stable
        // version is a patch or a minor. Compare with the highest stable release.
        if ($version->prerelease !== []) {
            continue;
        }

        if ($previous === null || VersionComparator::compare(
            $version->canonical(),
            $previous->canonical(),
        ) > 0) {
            $previous = $version;
        }
    }

    return [
        'current_recorded' => $currentRecorded,
        'current_manifest' => $currentManifest,
        'previous' => $previous,
    ];
}

/** @return array<string, mixed> */
function comparableManifest(array $manifest): array
{
    unset($manifest['commit']);

    $normalise = static function (mixed $value) use (&$normalise): mixed {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($normalise, $value);
        }

        ksort($value);

        return array_map($normalise, $value);
    };

    return $normalise($manifest);
}

/**
 * Once notes move from Unreleased to the candidate's versioned section, the
 * committed history becomes part of the release artifact contract. A later
 * image seeds its offline span from this file, so a missing or stale candidate
 * entry would silently erase this release's actions from direct upgrades.
 *
 * @param  array{current_recorded: bool, current_manifest: array<string, mixed>|null, previous: SemanticVersion|null}  $history
 * @param  array<string, mixed>  $generated
 */
function assertVersionedReleaseRecorded(array $history, array $generated): void
{
    if (! $history['current_recorded'] || $history['current_manifest'] === null) {
        throw new RuntimeException(
            "versioned release notes require a matching {$generated['version']} entry in releases/history.json."
        );
    }

    if (comparableManifest($history['current_manifest']) !== comparableManifest($generated)) {
        throw new RuntimeException(
            "releases/history.json has a stale {$generated['version']} declaration; rebuild it from release.json."
        );
    }
}

/**
 * Preparation may legitimately keep the next release in Unreleased and out of
 * history. Publication may not: once a tag can create public artifacts, its
 * notes and exact declaration must already be frozen in the tagged tree.
 *
 * @param  array{current_recorded: bool, current_manifest: array<string, mixed>|null, previous: SemanticVersion|null}  $history
 * @param  array<string, mixed>  $generated
 */
function assertPublishingReleaseReady(
    SemanticVersion $candidate,
    bool $unreleasedHasContent,
    bool $versionedHasContent,
    array $history,
    array $generated,
): void {
    if ($unreleasedHasContent) {
        throw new RuntimeException(
            'publishing requires the [Unreleased] section to be empty.'
        );
    }

    if (! $versionedHasContent) {
        throw new RuntimeException(
            "publishing requires a non-empty [{$candidate->canonical()}] changelog section."
        );
    }

    assertVersionedReleaseRecorded($history, $generated);
}

/**
 * The date of the commit being released, which is what the heading is compared
 * against. The publishing workflow checks out the tagged ref and verifies HEAD
 * equals it, so this is the tagged commit -- and unlike a wall clock it is
 * identical on every rerun, forever.
 */
function releaseCommitDate(string $root): string
{
    $command = 'git -C '.escapeshellarg($root).' show -s --format=%cs HEAD 2>/dev/null';
    $date = trim((string) shell_exec($command));

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        throw new RuntimeException(
            'could not read the release commit date from git, so the changelog date cannot be checked. '
            .'Publishing runs inside a checkout of the tagged ref; if this is a local run, run it from the repository.'
        );
    }

    return $date;
}

/**
 * A release section written days before the cut ships a date that was never
 * true. Nothing else catches it: the date is not part of any declaration, so
 * every other contract check passes over a section headed with last week.
 *
 * Publishing only. On a pull request the candidate section is legitimately
 * dated whenever its author expected to cut, and failing every unrelated PR
 * until someone bumps it would make the guard a tax rather than a check.
 *
 * The comparison is against the RELEASE COMMIT, never the current time. A
 * wall-clock check fails an accurate heading whenever the publish job is rerun
 * more than a day after the tag -- which release-image.yml explicitly supports,
 * reusing a partial draft or a recorded digest. With a protected tag that
 * cannot simply be replaced, a transient failure plus a delayed rerun would
 * leave the release permanently unpublishable. A guard against a cosmetic
 * defect must not be able to block a release.
 *
 * The falsifiable property is therefore one-sided: notes cannot predate the
 * commit they describe. A heading LATER than the commit is fine and expected --
 * a tag pushed some days after the release commit landed is an ordinary thing
 * to do, and refusing it would recreate the same blocking failure from the
 * other side. One day of slack absorbs timezone skew between whoever wrote the
 * heading and the committer.
 */
function assertReleaseDateIsCurrent(?string $date, string $version, string $releasedOn): void
{
    if ($date === null) {
        throw new RuntimeException(
            "publishing requires the [{$version}] changelog heading to carry a date."
        );
    }

    $heading = parseContractDate($date)
        ?? throw new RuntimeException(
            "the [{$version}] changelog heading is dated \"{$date}\", which is not a YYYY-MM-DD date."
        );

    $commit = parseContractDate($releasedOn)
        ?? throw new RuntimeException("could not read the release commit date: \"{$releasedOn}\".");

    if ($heading >= $commit->modify('-1 day')) {
        return;
    }

    $drift = (int) $heading->diff($commit)->days;

    throw new RuntimeException(
        "the [{$version}] changelog section is dated {$date}, {$drift} days before the release commit ({$releasedOn}). "
        .'Set it to the day the release is tagged; it ships as the release date.'
    );
}

/**
 * Round-tripped through its own format on purpose: createFromFormat rolls
 * 2026-09-31 forward into October without complaining.
 */
function parseContractDate(string $date): ?\DateTimeImmutable
{
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));

    if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
        return null;
    }

    return $parsed;
}

function isPatchLine(SemanticVersion $candidate, ?SemanticVersion $previous): bool
{
    return $previous !== null
        && $candidate->major === $previous->major
        && $candidate->minor === $previous->minor;
}

function operatorActionVersionIsAllowed(SemanticVersion $candidate, ?SemanticVersion $previous): bool
{
    if ($previous === null) {
        return true;
    }

    // Before 1.0, ADR 0012 assigns operator-impacting changes to 0.MINOR.
    // From 1.0 onward they consume MAJOR. Keeping the two eras explicit avoids
    // accidentally treating a post-1.0 minor as sufficient just because it is
    // not a patch.
    if ($previous->major === '0') {
        return $candidate->major !== '0'
            || VersionComparator::compare(
                "0.{$candidate->minor}.0",
                "0.{$previous->minor}.0",
            ) > 0;
    }

    return VersionComparator::compare(
        "{$candidate->major}.0.0",
        "{$previous->major}.0.0",
    ) > 0;
}

function assertPublishingWorkflowGuarded(string $root): void
{
    $workflow = requiredFile($root.'/.github/workflows/release-image.yml');
    $releaseGuide = requiredFile($root.'/RELEASING.md');
    $markers = [
        'VERSION read' => 'version="$(tr -d',
        'tag identity check' => 'expected_tag="v${version}"',
        'main-branch ancestry check' => 'git merge-base --is-ancestor',
        'publishing contract check' => 'make release-publish-contract-test',
        'full main CI check' => 'name: Verify full main CI for the release commit',
        'manifest build' => '--out=release-manifest.json',
        'remote tag check before mutation' => 'name: Verify the remote release tag before staging',
        'draft release staging' => 'name: Stage the draft release and manifest',
        'registry login' => 'uses: docker/login-action@',
        'digest-only image publication' => 'name: Build and push the release image by digest',
        'candidate image verification' => 'name: Verify the release image before tagging',
        'durable image digest recording' => 'name: Attach and verify the exact image digest',
        'remote tag check before image tagging' => 'name: Verify the remote release tag before image tagging',
        'exact image tag creation' => 'name: Create or verify the exact release image tag',
        'stable-alias decision' => 'name: Decide whether this release may move stable aliases',
        'GitHub Release publication' => 'name: Publish the GitHub Release',
        'published artifact verification' => 'name: Verify the published release and exact image',
        'floating alias promotion' => 'name: Promote stable image aliases',
    ];
    $positions = [];

    foreach ($markers as $label => $marker) {
        $position = strpos($workflow, $marker);

        if ($position === false) {
            throw new RuntimeException("release workflow has no {$label}.");
        }

        $positions[$label] = $position;
    }

    $expectedOrder = array_keys($markers);

    for ($index = 1; $index < count($expectedOrder); $index++) {
        $before = $expectedOrder[$index - 1];
        $after = $expectedOrder[$index];

        if ($positions[$before] >= $positions[$after]) {
            throw new RuntimeException(
                "release workflow runs {$after} before {$before}."
            );
        }
    }

    if (preg_match_all('/^[ \t]*uses:[ \t]+([^@\s]+)@([^\s#]+)/m', $workflow, $uses, PREG_SET_ORDER) === false) {
        throw new RuntimeException('could not inspect release workflow action references.');
    }

    foreach ($uses as $use) {
        if (preg_match('/^[0-9a-f]{40}$/', $use[2]) !== 1) {
            throw new RuntimeException(
                "release workflow action {$use[1]} is not pinned to an immutable commit SHA."
            );
        }
    }

    if (! str_contains($workflow, 'group: release-image-publication')) {
        throw new RuntimeException('release workflow does not serialize repository-wide publication.');
    }

    if (! str_contains($workflow, 'queue: max')) {
        throw new RuntimeException('release workflow can discard an earlier queued release run.');
    }

    foreach ([
        'eligible **pre-guard** `Release image` run',
        '30-day window',
        'explicit repository-owner approval',
        'immediately before pushing the first guarded tag',
        'active ruleset covering `v*` tags',
        'restricts creation and blocks',
        'stable `x.y.z` releases only',
    ] as $legacyRunGate) {
        if (! str_contains($releaseGuide, $legacyRunGate)) {
            throw new RuntimeException('release guide does not preserve the pre-guard workflow-run gate.');
        }
    }

    if (! str_contains(
        $workflow,
        'outputs: type=image,name=${{ steps.identity.outputs.image }},push-by-digest=true,name-canonical=true,push=true',
    )) {
        throw new RuntimeException('release workflow does not publish the candidate image by digest only.');
    }

    $digestBuild = substr(
        $workflow,
        $positions['digest-only image publication'],
        $positions['durable image digest recording'] - $positions['digest-only image publication'],
    );

    if (str_contains($digestBuild, 'tags:') || str_contains($digestBuild, 'push: true')) {
        throw new RuntimeException('release image build can mutate the exact version tag before recording its digest.');
    }

    if (! str_contains($workflow, 'ref: ${{ needs.validate.outputs.release_sha }}')) {
        throw new RuntimeException('release publisher checkout is not bound to the validated commit.');
    }

    if (substr_count(
        $workflow,
        'gh api "repos/$GITHUB_REPOSITORY/commits/$TAG" --jq .sha',
    ) < 4) {
        throw new RuntimeException('release workflow does not guard the remote tag across publication.');
    }

    if (! str_contains($workflow, 'refusing to overwrite it with $EXPECTED_DIGEST')
        || ! str_contains($workflow, 'Cannot determine whether $IMAGE:$VERSION exists')) {
        throw new RuntimeException('release workflow can overwrite or guess about an existing exact image tag.');
    }

    foreach ([
        '["linux/amd64", "linux/arm64"]',
        '--platform linux/amd64',
        'org.opencontainers.image.revision',
        'org.opencontainers.image.source',
        'org.opencontainers.image.version',
        'cmp --silent release-manifest.json "$artifact_dir/release-manifest.json"',
    ] as $artifactProof) {
        if (! str_contains($workflow, $artifactProof)) {
            throw new RuntimeException('release workflow does not verify the built image identity and contents.');
        }
    }

    if (preg_match(
        "/- name: Promote stable image aliases\n[ \t]+if: steps\.promotion\.outputs\.promote == 'true'/",
        $workflow,
    ) !== 1) {
        throw new RuntimeException('release workflow can move floating aliases without the newest-stable gate.');
    }
}

function assertGenericHostDeployGuarded(string $root): void
{
    $guide = requiredFile($root.'/docs/self-hosting/runtime-requirements.md');
    $writer = requiredFile($root.'/deploy/write-release-manifest.sh');
    $deployFlow = strpos($guide, '## Deploy Flow');

    if ($deployFlow === false) {
        throw new RuntimeException('generic host guide has no Deploy Flow section.');
    }

    $guide = substr($guide, $deployFlow);
    $markers = [
        'fail-fast shell mode' => 'set -euo pipefail',
        'host-upgrade acknowledgement gate' => 'WAYFINDR_REQUIRED_ACTION="$required_action" php -r',
        'clean target-manifest gate' => 'WAYFINDR_REQUIRE_CLEAN=1 bash deploy/write-release-manifest.sh',
        'release identity' => 'export WAYFINDR_VERSION=',
        'configuration cache' => 'php artisan config:cache',
        'migration' => 'php artisan migrate --force',
    ];
    $positions = [];

    foreach ($markers as $label => $marker) {
        $position = strpos($guide, $marker);

        if ($position === false) {
            throw new RuntimeException("generic host deploy guide has no {$label} step.");
        }

        $positions[$label] = $position;
    }

    $expectedOrder = array_keys($markers);

    for ($index = 1; $index < count($expectedOrder); $index++) {
        $before = $expectedOrder[$index - 1];
        $after = $expectedOrder[$index];

        if ($positions[$before] >= $positions[$after]) {
            throw new RuntimeException(
                "generic host deploy guide runs {$after} before {$before}."
            );
        }
    }

    foreach (['scripts/release/build-manifest.php', '--out="$root/release-manifest.json"'] as $marker) {
        if (! str_contains($writer, $marker)) {
            throw new RuntimeException('generic host manifest writer does not build the guarded target file.');
        }
    }

    if (preg_match(
        '/^(WAYFINDR_REQUIRED_ACTION="\$required_action" php -r \'[^\n]+\')$/m',
        $guide,
        $acknowledgementGate,
    ) !== 1) {
        throw new RuntimeException('generic host guide acknowledgement gate could not be exercised.');
    }

    $sentinel = sys_get_temp_dir().'/wayfindr-host-mutation-'.bin2hex(random_bytes(8));
    $fixture = implode("\n", [
        'set -eu',
        'required_action="0.8.0/php-runtime-extensions"',
        'export WAYFINDR_ACKNOWLEDGED_ACTIONS="0.7.0/other 0.8.0/php-runtime-extensions"',
        $acknowledgementGate[1],
        ': > '.escapeshellarg($sentinel),
    ]);
    $process = proc_open(['/bin/sh', '-c', $fixture], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $root);

    if (! is_resource($process)) {
        throw new RuntimeException('could not start the generic host acknowledgement fixture.');
    }

    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $mutated = file_exists($sentinel);

    if ($mutated) {
        unlink($sentinel);
    }

    if ($status === 0 || $mutated) {
        throw new RuntimeException(
            'generic host guide accepts whitespace-only action separation before checkout mutation.'
        );
    }
}

function main(string $root, bool $publishing = false): void
{
    // A PR-only contract races a tag-triggered publisher. The workflow itself
    // must make every identity and declaration check before registry auth or an
    // image push, and this assertion prevents that ordering from drifting.
    assertPublishingWorkflowGuarded($root);
    assertGenericHostDeployGuarded($root);

    $candidate = candidateVersion(requiredFile($root.'/VERSION'));
    $manifest = generatedManifest($root, $candidate->canonical());

    if (! array_key_exists('requires_operator_action', $manifest)
        || ! is_bool($manifest['requires_operator_action'])) {
        throw new RuntimeException(
            'the generated manifest has no boolean requires_operator_action verdict.'
        );
    }

    $machineRequiresAction = $manifest['requires_operator_action'];
    $changelog = releaseSections(requiredFile($root.'/CHANGELOG.md'));
    $sections = $changelog['bodies'];

    if (! array_key_exists('Unreleased', $sections)) {
        throw new RuntimeException('CHANGELOG has no [Unreleased] section.');
    }

    $unreleased = $sections['Unreleased'];
    $versioned = $sections[$candidate->canonical()] ?? null;
    $unreleasedHasContent = hasVisibleContent($unreleased);
    $versionedHasContent = $versioned !== null && hasVisibleContent($versioned);

    if ($unreleasedHasContent && $versionedHasContent) {
        throw new RuntimeException(
            "both [Unreleased] and [{$candidate->canonical()}] contain release notes; the active declaration is ambiguous."
        );
    }

    $history = historyContext(requiredFile($root.'/releases/history.json'), $candidate);

    if ($publishing) {
        assertPublishingReleaseReady(
            $candidate,
            $unreleasedHasContent,
            $versionedHasContent,
            $history,
            $manifest,
        );

        assertReleaseDateIsCurrent(
            $changelog['dates'][$candidate->canonical()] ?? null,
            $candidate->canonical(),
            releaseCommitDate($root),
        );
    }

    if ($unreleasedHasContent && $history['current_recorded']) {
        throw new RuntimeException(
            "VERSION {$candidate->canonical()} is already in release history while [Unreleased] contains new work."
        );
    }

    $active = $unreleasedHasContent
        ? $unreleased
        : ($versionedHasContent ? $versioned : null);

    if ($versionedHasContent) {
        assertVersionedReleaseRecorded($history, $manifest);
    }

    if ($active === null) {
        if ($machineRequiresAction) {
            throw new RuntimeException(
                'the generated manifest requires operator action, but no active changelog section declares it.'
            );
        }

        printf(
            "Release contract passed: VERSION %s has no active notes and the generated manifest requires no operator action.\n",
            $candidate->canonical(),
        );

        return;
    }

    $humanRequiresAction = humanVerdict($active);

    if ($humanRequiresAction !== $machineRequiresAction) {
        throw new RuntimeException(sprintf(
            'the active changelog says operator action is %s, but the generated manifest says %s.',
            $humanRequiresAction ? 'required' : 'not required',
            $machineRequiresAction ? 'required' : 'not required',
        ));
    }

    $previous = $history['previous'];

    if (isPatchLine($candidate, $previous) && hasAddedContent($active)) {
        throw new RuntimeException(sprintf(
            'VERSION %s is a patch over %s, but the active changelog section contains Added content; ADR 0012 requires a minor bump.',
            $candidate->canonical(),
            $previous?->canonical() ?? 'the previous release',
        ));
    }

    if ($machineRequiresAction && ! operatorActionVersionIsAllowed($candidate, $previous)) {
        throw new RuntimeException(sprintf(
            'VERSION %s follows %s but the generated manifest requires operator action; ADR 0012 requires 0.MINOR before 1.0 and MAJOR from 1.0 onward.',
            $candidate->canonical(),
            $previous?->canonical() ?? 'the previous release',
        ));
    }

    printf(
        "Release contract passed: changelog and manifest agree that operator action is %s; VERSION %s follows %s.\n",
        $machineRequiresAction ? 'required' : 'not required',
        $candidate->canonical(),
        $previous?->canonical() ?? 'the bootstrap release',
    );
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $arguments = array_slice($argv, 1);

        if (array_diff($arguments, ['--publishing']) !== []) {
            throw new RuntimeException('unknown argument; expected only --publishing.');
        }

        main(dirname(__DIR__), in_array('--publishing', $arguments, true));
    } catch (Throwable $throwable) {
        fwrite(STDERR, 'Release contract failed: '.$throwable->getMessage()."\n");
        exit(1);
    }
}
