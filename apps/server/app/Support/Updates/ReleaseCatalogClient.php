<?php

declare(strict_types=1);

namespace App\Support\Updates;

use App\Support\Release\ReleaseManifest;
use App\Support\Version\SemanticVersion;
use App\Support\Version\VersionComparator;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

/** Read public metadata from fixed Wayfindr sources without pulling or running code. */
final class ReleaseCatalogClient
{
    private const REPOSITORY = 'adamgreenwell/wayfindr';

    private const API = 'https://api.github.com/repos/'.self::REPOSITORY;

    private const RELEASES = 'https://github.com/'.self::REPOSITORY.'/releases';

    private const RAW = 'https://raw.githubusercontent.com/'.self::REPOSITORY;

    private const MANIFEST_CONTRACT_FROM = '0.1.0';

    public function fetch(?string $tag = null): ReleaseCatalog
    {
        $requestedTag = $tag === null ? null : $this->stableTag($tag);
        $releaseApiUrl = self::API.'/releases/'.($requestedTag === null ? 'latest' : 'tags/'.$requestedTag);
        $release = $this->object($this->get($releaseApiUrl), 'malformed_release');
        if (! is_int($release['id'] ?? null) || $release['id'] < 1
            || ($release['draft'] ?? null) !== false || ($release['prerelease'] ?? null) !== false
            || ! is_string($release['tag_name'] ?? null)) {
            throw new ReleaseMetadataException('malformed_release', 'GitHub did not identify a published stable release.');
        }

        $tag = $this->stableTag($release['tag_name']);
        $releaseUrl = self::RELEASES.'/tag/'.$tag;
        if ($tag !== $release['tag_name'] || ($requestedTag !== null && $tag !== $requestedTag)
            || ($release['html_url'] ?? null) !== $releaseUrl
            || (! is_string($release['body'] ?? null) && ($release['body'] ?? null) !== null)) {
            throw new ReleaseMetadataException('identity_mismatch', 'GitHub release metadata does not match the selected release.');
        }

        $commit = $this->tagCommit($tag);
        $manifestUrl = self::RELEASES.'/download/'.$tag.'/release-manifest.json';
        $digestUrl = self::RELEASES.'/download/'.$tag.'/release-image-digest.txt';
        $manifestAssetDigest = $this->assetDigest($release, 'release-manifest.json', $manifestUrl);
        $digestAssetDigest = $this->assetDigest($release, 'release-image-digest.txt', $digestUrl);
        $manifestBody = $this->get($manifestUrl, asset: true);
        $this->verifyAsset($manifestBody, $manifestAssetDigest);
        try {
            $manifest = ReleaseManifest::decode($manifestBody);
        } catch (Throwable $exception) {
            throw new ReleaseMetadataException('malformed_manifest', 'The release manifest is invalid or incompatible.', $exception);
        }
        if ($manifest['version'] !== substr($tag, 1) || $manifest['commit'] !== $commit) {
            throw new ReleaseMetadataException('identity_mismatch', 'The release manifest does not match the resolved tag commit and version.');
        }

        $digestBody = $this->get($digestUrl, asset: true);
        $this->verifyAsset($digestBody, $digestAssetDigest);
        $imageDigest = rtrim($digestBody, "\r\n");
        if (preg_match('/^sha256:[0-9a-f]{64}$/D', $imageDigest) !== 1) {
            throw new ReleaseMetadataException('malformed_digest', 'The release image digest is malformed.');
        }

        // History is an authored release contract. Resolve the tag to a commit
        // first so a moving tag cannot silently change the reviewed declarations.
        $historyUrl = self::RAW.'/'.$commit.'/releases/history.json';
        $historyBody = $this->get($historyUrl);
        $history = $this->history($historyBody, $manifest);

        return new ReleaseCatalog(
            targetManifest: $manifest,
            history: $history,
            tag: $tag,
            imageDigest: $imageDigest,
            releaseNotes: $release['body'] ?? '',
            releaseUrl: $releaseUrl,
            provenance: [
                'repository' => self::REPOSITORY,
                'tag' => $tag,
                'commit' => $commit,
                'release_id' => $release['id'],
                'release_api_url' => $releaseApiUrl,
                'release_url' => $releaseUrl,
                'manifest_url' => $manifestUrl,
                'manifest_sha256' => hash('sha256', $manifestBody),
                'digest_url' => $digestUrl,
                'digest_asset_sha256' => hash('sha256', $digestBody),
                'history_url' => $historyUrl,
                'history_sha256' => hash('sha256', $historyBody),
                'history_floor' => $manifest['minimum_upgrade_from'],
                'history_contract_from' => self::MANIFEST_CONTRACT_FROM,
                'history_complete' => true,
                'history_coverage_basis' => 'validated_committed_release_contract',
            ],
        );
    }

    private function stableTag(string $value): string
    {
        $parsed = SemanticVersion::parse($value);
        if ($parsed === null || $parsed->prerelease !== [] || $parsed->build !== null) {
            throw new ReleaseMetadataException('invalid_target', 'Select an exact stable release such as v1.1.1.');
        }

        return 'v'.$parsed->canonical();
    }

    private function get(string $url, bool $asset = false): string
    {
        $request = Http::acceptJson()->withUserAgent('Wayfindr release planner')
            ->connectTimeout(5)->timeout(15);
        // Public release assets redirect to GitHub's signed download host. API
        // and commit-bound source reads must answer directly. No auth is sent.
        $request = $asset
            ? $request->withOptions(['allow_redirects' => [
                'max' => 3,
                'protocols' => ['https'],
                'on_redirect' => static function (RequestInterface $request, ResponseInterface $response, UriInterface $uri): void {
                    if (! in_array($uri->getHost(), ['github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com'], true)
                        || $uri->getScheme() !== 'https' || $uri->getUserInfo() !== ''
                        || ! in_array($uri->getPort(), [null, 443], true)) {
                        throw new ReleaseMetadataException('metadata_http_error', 'The release asset redirected outside its trusted download origins.');
                    }
                },
            ]])
            : $request->withoutRedirecting();
        try {
            $response = $request->get($url);
        } catch (ConnectionException|GuzzleException $exception) {
            throw new ReleaseMetadataException('metadata_unavailable', 'The release metadata source is unavailable. Retry later.', $exception);
        }
        if ($response->status() !== 200) {
            $reason = match (true) {
                $response->status() === 429,
                $response->status() === 403 && $response->header('X-RateLimit-Remaining') === '0' => 'metadata_rate_limited',
                $response->status() === 403 => 'metadata_forbidden',
                $response->status() === 404 => 'metadata_missing',
                $response->serverError() => 'metadata_unavailable',
                default => 'metadata_http_error',
            };
            throw new ReleaseMetadataException($reason, 'Release metadata could not be verified (HTTP '.$response->status().').');
        }
        $body = $response->body();
        if (strlen($body) > 2_000_000) {
            throw new ReleaseMetadataException('metadata_too_large', 'The release metadata exceeds the supported size.');
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private function object(string $body, string $reason): array
    {
        try {
            $object = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new ReleaseMetadataException($reason, 'The release metadata is not valid JSON.', $exception);
        }
        if (! is_array($object) || array_is_list($object)) {
            throw new ReleaseMetadataException($reason, 'The release metadata must be a JSON object.');
        }

        return $object;
    }

    private function tagCommit(string $tag): string
    {
        $reference = $this->object($this->get(self::API.'/git/ref/tags/'.$tag), 'malformed_release');
        if (($reference['ref'] ?? null) !== 'refs/tags/'.$tag) {
            throw new ReleaseMetadataException('identity_mismatch', 'GitHub returned a different release tag reference.');
        }
        $object = $reference['object'] ?? null;
        $seen = [];
        for ($depth = 0; $depth < 5; $depth++) {
            if (! is_array($object) || ! is_string($object['sha'] ?? null)
                || preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/D', $object['sha']) !== 1
                || isset($seen[$object['sha']])) {
                throw new ReleaseMetadataException('malformed_release', 'The release tag has an invalid or cyclic commit reference.');
            }
            $seen[$object['sha']] = true;
            if (($object['type'] ?? null) === 'commit') {
                return $object['sha'];
            }
            if (($object['type'] ?? null) !== 'tag') {
                throw new ReleaseMetadataException('malformed_release', 'The release tag does not resolve to a source commit.');
            }
            $annotated = $this->object($this->get(self::API.'/git/tags/'.$object['sha']), 'malformed_release');
            $object = $annotated['object'] ?? null;
        }

        throw new ReleaseMetadataException('malformed_release', 'The release tag exceeds the supported annotation depth.');
    }

    /** @param array<string, mixed> $release */
    private function assetDigest(array $release, string $name, string $url): string
    {
        $assets = $release['assets'] ?? null;
        if (! is_array($assets) || ! array_is_list($assets)) {
            throw new ReleaseMetadataException('malformed_release', 'GitHub release metadata has no valid asset list.');
        }
        $matches = array_values(array_filter($assets, static fn (mixed $asset): bool => is_array($asset) && ($asset['name'] ?? null) === $name));
        if ($matches === []) {
            throw new ReleaseMetadataException('metadata_missing', 'The published release is missing '.$name.'.');
        }
        if (count($matches) !== 1 || ($matches[0]['state'] ?? null) !== 'uploaded'
            || ($matches[0]['browser_download_url'] ?? null) !== $url
            || ! is_string($matches[0]['digest'] ?? null)
            || preg_match('/^sha256:[0-9a-f]{64}$/D', $matches[0]['digest']) !== 1) {
            throw new ReleaseMetadataException('malformed_release', 'The published release asset metadata is ambiguous or invalid.');
        }

        return substr($matches[0]['digest'], 7);
    }

    private function verifyAsset(string $body, string $digest): void
    {
        if (! hash_equals($digest, hash('sha256', $body))) {
            throw new ReleaseMetadataException('asset_integrity_mismatch', 'The downloaded release asset does not match its published checksum.');
        }
    }

    /**
     * @param  array<string, mixed>  $target
     * @return list<array<string, mixed>>
     */
    private function history(string $body, array $target): array
    {
        $decoded = $this->object($body, 'malformed_history');
        if (($decoded['schema'] ?? null) !== ReleaseManifest::SCHEMA
            || ! is_array($decoded['releases'] ?? null) || ! array_is_list($decoded['releases'])
            || $decoded['releases'] === []) {
            throw new ReleaseMetadataException('malformed_history', 'The committed release history is missing or incompatible.');
        }
        $seen = [];
        $history = [];
        $targetRecorded = false;
        $earliest = null;
        foreach ($decoded['releases'] as $entry) {
            try {
                if (! is_array($entry)) {
                    throw new \InvalidArgumentException('History entry is not an object.');
                }
                ReleaseManifest::assertPublished($entry);
            } catch (Throwable $exception) {
                throw new ReleaseMetadataException('malformed_history', 'The committed history contains an invalid release declaration.', $exception);
            }
            $version = $entry['version'];
            $comparison = VersionComparator::compare($version, $target['version']);
            if (isset($seen[$version]) || $comparison === null || $comparison > 0) {
                throw new ReleaseMetadataException('malformed_history', 'The committed history has duplicate, unorderable, or future releases.');
            }
            $seen[$version] = true;
            if ($earliest === null || VersionComparator::compare($version, $earliest) < 0) {
                $earliest = $version;
            }
            if ($version === $target['version']) {
                // Recording occurs before the release commit exists. Empty is
                // therefore expected here; a different nonempty SHA is not.
                if ($entry['commit'] !== '' && $entry['commit'] !== $target['commit']) {
                    throw new ReleaseMetadataException('identity_mismatch', 'The committed target history names a different source commit.');
                }
                $entry['commit'] = $target['commit'];
                if ($this->normalise($entry) !== $this->normalise($target)) {
                    throw new ReleaseMetadataException('identity_mismatch', 'The published target declaration differs from its committed history.');
                }
                $entry = $target;
                $targetRecorded = true;
            }
            $history[] = $entry;
        }
        $floor = $target['minimum_upgrade_from'];
        $coverageFrom = is_string($floor) && VersionComparator::compare($floor, self::MANIFEST_CONTRACT_FROM) > 0
            ? $floor : self::MANIFEST_CONTRACT_FROM;
        if (! $targetRecorded || VersionComparator::compare($earliest, $coverageFrom) > 0) {
            throw new ReleaseMetadataException('history_coverage_unverifiable', 'The committed release history does not establish target and upgrade-floor coverage.');
        }

        return $history;
    }

    private function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->normalise(...), $value);
    }
}
