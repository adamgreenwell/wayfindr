<?php

declare(strict_types=1);

use App\Support\Release\ReleaseManifest;
use App\Support\Updates\ReleaseCatalogClient;
use App\Support\Updates\ReleaseMetadataException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;

function releaseCatalogFixture(): array
{
    $commit = str_repeat('a', 40);
    $api = 'https://api.github.com/repos/adamgreenwell/wayfindr';
    $releases = 'https://github.com/adamgreenwell/wayfindr/releases';
    $manifest = ReleaseManifest::build([
        'minimum_upgrade_from' => '0.1.0-alpha.1',
        'actions' => [],
    ], '1.1.1', $commit);
    $earlier = ReleaseManifest::build([
        'minimum_upgrade_from' => '0.1.0-alpha.1',
        'actions' => [[
            'id' => 'host-worker',
            'summary' => 'Restart the host worker.',
            'detail' => 'Restart the worker after installing this release.',
            'phase' => 'after-start',
            'depends_on_release' => 'code',
            'installation_profiles' => ['host'],
            'applicability' => ['type' => 'always'],
            'verification' => ['type' => 'attest'],
        ]],
    ], '0.9.0', '');
    $historyTarget = $manifest;
    $historyTarget['commit'] = '';
    $manifestUrl = $releases.'/download/v1.1.1/release-manifest.json';
    $digestUrl = $releases.'/download/v1.1.1/release-image-digest.txt';

    return [
        'api_url' => $api.'/releases/latest',
        'tag_api_url' => $api.'/releases/tags/v1.1.1',
        'ref_url' => $api.'/git/ref/tags/v1.1.1',
        'manifest_url' => $manifestUrl,
        'digest_url' => $digestUrl,
        'history_url' => 'https://raw.githubusercontent.com/adamgreenwell/wayfindr/'.$commit.'/releases/history.json',
        'release' => [
            'id' => 123,
            'tag_name' => 'v1.1.1',
            'draft' => false,
            'prerelease' => false,
            'html_url' => $releases.'/tag/v1.1.1',
            'body' => "A published release.\n\nRead its notes before upgrading.",
            'assets' => [
                ['name' => 'release-manifest.json', 'state' => 'uploaded', 'browser_download_url' => $manifestUrl],
                ['name' => 'release-image-digest.txt', 'state' => 'uploaded', 'browser_download_url' => $digestUrl],
            ],
        ],
        'ref' => ['ref' => 'refs/tags/v1.1.1', 'object' => ['type' => 'commit', 'sha' => $commit]],
        'manifest' => $manifest,
        'digest_body' => 'sha256:'.str_repeat('b', 64)."\n",
        'history' => [
            'schema' => ReleaseManifest::SCHEMA,
            'releases' => [
                ReleaseManifest::build(['minimum_upgrade_from' => '0.1.0-alpha.1', 'actions' => []], '0.1.0', ''),
                $earlier,
                $historyTarget,
            ],
        ],
    ];
}

function releaseCatalogJson(mixed $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
}

function fakeReleaseCatalog(array $fixture, array $responses = []): void
{
    $manifestBody = releaseCatalogJson($fixture['manifest']);
    $release = $fixture['release'];
    foreach ($release['assets'] as &$asset) {
        $body = $asset['name'] === 'release-manifest.json' ? $manifestBody : $fixture['digest_body'];
        if (! array_key_exists('digest', $asset)) {
            $asset['digest'] = 'sha256:'.hash('sha256', $body);
        }
    }
    unset($asset);

    Http::fake($responses + [
        $fixture['api_url'] => Http::response($release),
        $fixture['tag_api_url'] => Http::response($release),
        $fixture['ref_url'] => Http::response($fixture['ref']),
        $fixture['manifest_url'] => Http::response($manifestBody),
        $fixture['digest_url'] => Http::response($fixture['digest_body']),
        $fixture['history_url'] => Http::response(releaseCatalogJson($fixture['history'])),
    ]);
}

function expectReleaseCatalogRefusal(callable $fetch, string $reason): void
{
    try {
        $fetch();
    } catch (ReleaseMetadataException $exception) {
        expect($exception->reason)->toBe($reason);

        return;
    }

    Assert::fail('Unverifiable release metadata must not produce a release catalog.');
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('the catalog binds a published target and its complete source history to an exact commit', function (): void {
    $fixture = releaseCatalogFixture();
    fakeReleaseCatalog($fixture);

    $catalog = app(ReleaseCatalogClient::class)->fetch();

    expect($catalog->targetManifest)->toBe($fixture['manifest'])
        ->and($catalog->tag)->toBe('v1.1.1')
        ->and($catalog->imageDigest)->toBe(trim($fixture['digest_body']))
        ->and($catalog->releaseNotes)->toBe($fixture['release']['body'])
        ->and($catalog->releaseUrl)->toBe($fixture['release']['html_url'])
        ->and($catalog->platforms)->toBe([])
        ->and($catalog->history)->toHaveCount(3)
        ->and($catalog->history[1]['actions'][0]['installation_profiles'])->toBe(['host'])
        ->and($catalog->history[2])->toBe($fixture['manifest'])
        ->and($catalog->provenance['commit'])->toBe($fixture['manifest']['commit'])
        ->and($catalog->provenance['manifest_sha256'])->toBe(hash('sha256', releaseCatalogJson($fixture['manifest'])))
        ->and($catalog->provenance['history_url'])->toBe($fixture['history_url'])
        ->and($catalog->provenance['history_sha256'])->toBe(hash('sha256', releaseCatalogJson($fixture['history'])))
        ->and($catalog->provenance['history_complete'])->toBeTrue()
        ->and($catalog->provenance['history_contract_from'])->toBe('0.1.0');

    Http::assertSentCount(5);
    Http::assertSent(fn (Request $request): bool => $request->url() === $fixture['history_url'] && ! $request->hasHeader('Authorization'));
});

test('an exact selected release uses the canonical tag endpoint', function (): void {
    $fixture = releaseCatalogFixture();
    fakeReleaseCatalog($fixture);

    expect(app(ReleaseCatalogClient::class)->fetch('1.1.1')->tag)->toBe('v1.1.1');

    Http::assertNotSent(fn (Request $request): bool => $request->url() === $fixture['api_url']);
    Http::assertSent(fn (Request $request): bool => $request->url() === $fixture['tag_api_url']);
});

test('annotated release tags are resolved to their underlying commit', function (): void {
    $fixture = releaseCatalogFixture();
    $tagSha = str_repeat('c', 40);
    $fixture['ref']['object'] = ['type' => 'tag', 'sha' => $tagSha];
    fakeReleaseCatalog($fixture, [
        'https://api.github.com/repos/adamgreenwell/wayfindr/git/tags/'.$tagSha => Http::response([
            'object' => ['type' => 'commit', 'sha' => $fixture['manifest']['commit']],
        ]),
    ]);

    expect(app(ReleaseCatalogClient::class)->fetch()->provenance['commit'])->toBe($fixture['manifest']['commit']);
    Http::assertSentCount(6);
});

test('an alpha history entry preceding the manifest contract remains orderable', function (): void {
    $fixture = releaseCatalogFixture();
    array_unshift($fixture['history']['releases'], ReleaseManifest::build(['actions' => []], '0.1.0-alpha.1', ''));
    fakeReleaseCatalog($fixture);

    expect(app(ReleaseCatalogClient::class)->fetch()->history)->toHaveCount(4);
});

test('invalid requested targets are refused before any request', function (string $tag): void {
    expectReleaseCatalogRefusal(fn () => app(ReleaseCatalogClient::class)->fetch($tag), 'invalid_target');
    Http::assertNothingSent();
})->with(['latest', 'main', 'https://example.test/release.json', 'v1.1.1-beta.1', '1.1.1+build', 'v01.1.1']);

test('unavailable or denied metadata is distinct from a verified catalog', function (int $status, array $headers, string $reason): void {
    $fixture = releaseCatalogFixture();
    Http::fake([$fixture['api_url'] => Http::response('', $status, $headers)]);

    expectReleaseCatalogRefusal(fn () => app(ReleaseCatalogClient::class)->fetch(), $reason);
    Http::assertSentCount(1);
})->with([
    'missing' => [404, [], 'metadata_missing'],
    'forbidden' => [403, [], 'metadata_forbidden'],
    'exhausted API limit' => [403, ['X-RateLimit-Remaining' => '0'], 'metadata_rate_limited'],
    'rate limited' => [429, [], 'metadata_rate_limited'],
    'unavailable' => [503, [], 'metadata_unavailable'],
    'server failure' => [500, [], 'metadata_unavailable'],
    'redirected API' => [302, ['Location' => 'https://example.test'], 'metadata_http_error'],
]);

test('a connection failure cannot be reported as up to date', function (): void {
    Http::fake(fn () => throw new ConnectionException('Synthetic connection failure'));

    expectReleaseCatalogRefusal(fn () => app(ReleaseCatalogClient::class)->fetch(), 'metadata_unavailable');
});

test('oversized metadata is refused', function (): void {
    $fixture = releaseCatalogFixture();
    Http::fake([$fixture['api_url'] => Http::response(str_repeat('x', 2_000_001))]);

    expectReleaseCatalogRefusal(fn () => app(ReleaseCatalogClient::class)->fetch(), 'metadata_too_large');
});

test('malformed release metadata cannot authorize reading its assets', function (Closure $change, string $reason): void {
    $fixture = releaseCatalogFixture();
    $change($fixture);
    fakeReleaseCatalog($fixture);

    expectReleaseCatalogRefusal(fn () => app(ReleaseCatalogClient::class)->fetch('v1.1.1'), $reason);
})->with([
    'draft' => [function (array &$fixture): void {
        $fixture['release']['draft'] = true;
    }, 'malformed_release'],
    'prerelease' => [function (array &$fixture): void {
        $fixture['release']['prerelease'] = true;
    }, 'malformed_release'],
    'different selected release' => [function (array &$fixture): void {
        $fixture['release']['tag_name'] = 'v1.1.2';
    }, 'identity_mismatch'],
    'foreign release URL' => [function (array &$fixture): void {
        $fixture['release']['html_url'] = 'https://example.test/release';
    }, 'identity_mismatch'],
    'missing digest asset' => [function (array &$fixture): void {
        array_pop($fixture['release']['assets']);
    }, 'metadata_missing'],
    'duplicate manifest asset' => [function (array &$fixture): void {
        $fixture['release']['assets'][] = $fixture['release']['assets'][0];
    }, 'malformed_release'],
    'foreign download URL' => [function (array &$fixture): void {
        $fixture['release']['assets'][0]['browser_download_url'] = 'https://example.test/manifest';
    }, 'malformed_release'],
    'missing published checksum' => [function (array &$fixture): void {
        $fixture['release']['assets'][0]['digest'] = null;
    }, 'malformed_release'],
]);

test('unpublished or corrupt asset bytes fail their published checksums', function (string $asset): void {
    $fixture = releaseCatalogFixture();
    $url = $fixture[$asset.'_url'];
    fakeReleaseCatalog($fixture, [$url => Http::response('tampered bytes')]);

    expectReleaseCatalogRefusal(fn () => app(ReleaseCatalogClient::class)->fetch(), 'asset_integrity_mismatch');
})->with(['manifest', 'digest']);

test('a valid checksum does not replace manifest identity validation', function (Closure $change, string $reason): void {
    $fixture = releaseCatalogFixture();
    $change($fixture['manifest']);
    fakeReleaseCatalog($fixture);

    expectReleaseCatalogRefusal(fn () => app(ReleaseCatalogClient::class)->fetch(), $reason);
})->with([
    'wrong commit' => [function (array &$manifest): void {
        $manifest['commit'] = str_repeat('d', 40);
    }, 'identity_mismatch'],
    'wrong version' => [function (array &$manifest): void {
        $manifest['version'] = '1.1.0';
    }, 'identity_mismatch'],
    'unsupported schema' => [function (array &$manifest): void {
        $manifest['schema'] = 999;
    }, 'malformed_manifest'],
    'malformed actions' => [function (array &$manifest): void {
        $manifest['actions'] = 'none';
    }, 'malformed_manifest'],
]);

test('a published image digest must identify exactly one SHA256', function (string $body): void {
    $fixture = releaseCatalogFixture();
    $fixture['digest_body'] = $body;
    fakeReleaseCatalog($fixture);

    expectReleaseCatalogRefusal(fn () => app(ReleaseCatalogClient::class)->fetch(), 'malformed_digest');
})->with(['latest', 'sha256:short', 'sha256:'.str_repeat('B', 64), ' sha256:'.str_repeat('b', 64), 'sha256:'.str_repeat('b', 64)."\nsecond-line"]);

test('committed history must validate the target and upgrade-floor coverage', function (Closure $change, string $reason): void {
    $fixture = releaseCatalogFixture();
    $change($fixture['history']);
    fakeReleaseCatalog($fixture);

    expectReleaseCatalogRefusal(fn () => app(ReleaseCatalogClient::class)->fetch(), $reason);
})->with([
    'unsupported schema' => [function (array &$history): void {
        $history['schema'] = 999;
    }, 'malformed_history'],
    'empty history' => [function (array &$history): void {
        $history['releases'] = [];
    }, 'malformed_history'],
    'invalid declaration' => [function (array &$history): void {
        $history['releases'][0]['requires_operator_action'] = true;
    }, 'malformed_history'],
    'duplicate release' => [function (array &$history): void {
        $history['releases'][] = $history['releases'][0];
    }, 'malformed_history'],
    'future release' => [function (array &$history): void {
        $history['releases'][] = ReleaseManifest::build(['actions' => []], '1.2.0', '');
    }, 'malformed_history'],
    'unorderable development release' => [function (array &$history): void {
        $history['releases'][] = ReleaseManifest::build(['actions' => []], '0.5.0-dev', '');
    }, 'malformed_history'],
    'missing target' => [function (array &$history): void {
        array_pop($history['releases']);
    }, 'history_coverage_unverifiable'],
    'incomplete floor' => [function (array &$history): void {
        array_shift($history['releases']);
    }, 'history_coverage_unverifiable'],
    'different target declaration' => [function (array &$history): void {
        $history['releases'][2]['minimum_upgrade_from'] = '0.9.0';
    }, 'identity_mismatch'],
    'different recorded commit' => [function (array &$history): void {
        $history['releases'][2]['commit'] = str_repeat('e', 40);
    }, 'identity_mismatch'],
]);

test('missing or malformed committed history is an explicit metadata failure', function (string $body, int $status, string $reason): void {
    $fixture = releaseCatalogFixture();
    fakeReleaseCatalog($fixture, [$fixture['history_url'] => Http::response($body, $status)]);

    expectReleaseCatalogRefusal(fn () => app(ReleaseCatalogClient::class)->fetch(), $reason);
})->with([
    'missing' => ['', 404, 'metadata_missing'],
    'invalid JSON' => ['{', 200, 'malformed_history'],
    'list instead of contract' => ['[]', 200, 'malformed_history'],
]);

test('cyclic annotated tags cannot escape the bounded source resolution', function (): void {
    $fixture = releaseCatalogFixture();
    $tagSha = str_repeat('c', 40);
    $fixture['ref']['object'] = ['type' => 'tag', 'sha' => $tagSha];
    fakeReleaseCatalog($fixture, [
        'https://api.github.com/repos/adamgreenwell/wayfindr/git/tags/'.$tagSha => Http::response([
            'object' => ['type' => 'tag', 'sha' => $tagSha],
        ]),
    ]);

    expectReleaseCatalogRefusal(fn () => app(ReleaseCatalogClient::class)->fetch(), 'malformed_release');
    Http::assertSentCount(3);
});
