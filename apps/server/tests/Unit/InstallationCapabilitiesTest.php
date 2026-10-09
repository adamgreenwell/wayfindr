<?php

declare(strict_types=1);

use App\Support\Updates\InstallationCapabilities;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

function updateCapabilityReport(array $overrides = []): array
{
    return array_replace_recursive([
        'ownership' => 'installer-managed',
        'installation_id' => 'd7db8d1e-c1b0-4bde-ae67-a0204abcde60',
        'enrolled' => true,
        'helper' => [
            'protocol' => 1,
            'version' => '0.1.0',
            'capabilities' => ['plan', 'apply', 'status', 'recover'],
        ],
        'managed_policy' => ['require_remote_backup' => true],
    ], $overrides);
}

function trustedUpdateCapabilities(array $report): InstallationCapabilities
{
    return InstallationCapabilities::authenticatedHelper(
        $report, 'image', 'linux', 'amd64', 'ghcr.io/adamgreenwell/wayfindr:1.1.1',
    );
}

test('reported authentication and ownership claims cannot authorize a managed update', function (): void {
    $report = updateCapabilityReport([
        'authenticated' => true,
        'ownership_authenticated' => true,
        'helper_authenticated' => true,
        'helper' => ['authenticated' => true],
    ]);
    $capabilities = InstallationCapabilities::reported(
        $report, 'image', 'linux', 'amd64', 'ghcr.io/adamgreenwell/wayfindr:1.1.1',
    );

    expect($capabilities->ownership)->toBe('installer-managed')
        ->and($capabilities->helperAuthenticated)->toBeFalse()
        ->and($capabilities->managedBlockers())->toContain('ownership_untrusted', 'helper_not_authenticated')
        ->and($capabilities->toArray()['managed_update_eligible'])->toBeFalse();
});

test('only the trusted adapter contract admits a complete compatible fixture', function (): void {
    $capabilities = trustedUpdateCapabilities(updateCapabilityReport());

    expect($capabilities->helperAuthenticated)->toBeTrue()
        ->and($capabilities->managedBlockers())->toBe([])
        ->and($capabilities->toArray()['managed_update_eligible'])->toBeTrue()
        ->and($capabilities->managedPolicyClaims)->toBe(['require_remote_backup' => true]);
});

test('serializing a trusted fixture does not let an untrusted report inherit authentication', function (): void {
    $trusted = trustedUpdateCapabilities(updateCapabilityReport());
    $report = json_decode(json_encode($trusted, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $reported = InstallationCapabilities::reported(
        $report, 'image', 'linux', 'amd64', 'ghcr.io/adamgreenwell/wayfindr:1.1.1',
    );

    expect($report['helper']['authenticated'])->toBeTrue()
        ->and($reported->helperAuthenticated)->toBeFalse()
        ->and($reported->enrolled)->toBeTrue()
        ->and($reported->installationId)->toBe($trusted->installationId)
        ->and($reported->managedBlockers())->toContain('helper_not_authenticated');
});

test('runtime packaging does not infer deployment ownership', function (string $profile): void {
    $report = updateCapabilityReport();
    unset($report['ownership']);
    $capabilities = InstallationCapabilities::authenticatedHelper(
        $report, $profile, 'linux', 'amd64', 'ghcr.io/adamgreenwell/wayfindr:1.1.1',
    );

    expect($capabilities->ownership)->toBe('unknown')
        ->and($capabilities->runtimeProfile)->toBe($profile)
        ->and($capabilities->managedBlockers())->toContain('ownership_unknown');
})->with(['image', 'host']);

test('deployment owners retain their appropriate manual update path', function (string $ownership, string $guidance): void {
    $capabilities = trustedUpdateCapabilities(updateCapabilityReport(['ownership' => $ownership]));

    expect($capabilities->ownership)->toBe($ownership)
        ->and($capabilities->guidance())->toContain($guidance)
        ->and($capabilities->managedBlockers())->not->toBe([]);
})->with([
    ['external-docker', 'Docker or Compose'],
    ['source-build', 'rebuild your source image'],
    ['host-php', 'dependencies, assets, migrations'],
    ['deployment-platform', 'deployment platform'],
    ['hosting-managed', 'hosting service'],
    ['unknown', 'Confirm how'],
]);

test('terminal guidance remains available without enrollment or a helper', function (): void {
    $capabilities = InstallationCapabilities::reported(
        ['ownership' => 'installer-managed'], 'image', 'linux', 'amd64',
    );

    expect($capabilities->guidance())->toContain('install.sh --upgrade')
        ->and($capabilities->managedBlockers())->toContain('helper_not_authenticated', 'helper_not_enrolled');
});

test('malformed and unrecognized ownership claims stay unknown', function (mixed $ownership): void {
    $capabilities = trustedUpdateCapabilities(updateCapabilityReport(['ownership' => $ownership]));

    expect($capabilities->ownership)->toBe('unknown')
        ->and($capabilities->managedBlockers())->toContain('ownership_unknown');
})->with([null, true, 1, '', 'docker', 'image', 'Installer-managed', [['installer-managed']]]);

test('managed updates require the supported runtime platform and architecture', function (
    string $profile, string $platform, string $architecture, string $blocker,
): void {
    $capabilities = InstallationCapabilities::authenticatedHelper(
        updateCapabilityReport(), $profile, $platform, $architecture, 'ghcr.io/adamgreenwell/wayfindr:1.1.1',
    );

    expect($capabilities->managedBlockers())->toContain($blocker);
})->with([
    ['host', 'linux', 'amd64', 'runtime_profile_not_image'],
    ['unknown', 'linux', 'amd64', 'runtime_profile_not_image'],
    ['image', 'darwin', 'arm64', 'platform_not_linux'],
    ['image', 'windows', 'amd64', 'platform_not_linux'],
    ['image', 'linux', 'armv7l', 'architecture_unsupported'],
    ['image', 'linux', '', 'architecture_unsupported'],
]);

test('host architecture spellings normalize to the published architectures', function (
    string $reported, string $normalized,
): void {
    $capabilities = InstallationCapabilities::authenticatedHelper(
        updateCapabilityReport(), 'image', 'Linux', $reported, 'ghcr.io/adamgreenwell/wayfindr:1.1.1',
    );

    expect($capabilities->architecture)->toBe($normalized)
        ->and($capabilities->platform)->toBe('linux')
        ->and($capabilities->managedBlockers())->toBe([]);
})->with([
    ['x86_64', 'amd64'],
    ['amd64', 'amd64'],
    ['aarch64', 'arm64'],
    ['arm64', 'arm64'],
]);

test('only exact official release selectors are eligible for managed updates', function (?string $image): void {
    $capabilities = InstallationCapabilities::authenticatedHelper(
        updateCapabilityReport(), 'image', 'linux', 'amd64', $image,
    );

    expect($capabilities->managedBlockers())->toContain('image_not_exact_official_release');
})->with([
    null,
    'ghcr.io/adamgreenwell/wayfindr:latest',
    'ghcr.io/adamgreenwell/wayfindr:1',
    'ghcr.io/adamgreenwell/wayfindr:1.1',
    'ghcr.io/adamgreenwell/wayfindr:1.1.1-dev',
    'ghcr.io/adamgreenwell/wayfindr:1.1.1+custom',
    'ghcr.io/adamgreenwell/wayfindr:01.1.1',
    'ghcr.io/adamgreenwell/wayfindr: 1.1.1',
    'ghcr.io/adamgreenwell/wayfindr@sha256:'.str_repeat('a', 64),
    'ghcr.io/adamgreenwell/wayfindr:1.1.1@sha256:not-a-digest',
    'registry.example/wayfindr:1.1.1',
    'wayfindr-server:local',
    '${WAYFINDR_IMAGE}',
]);

test('a versioned official digest selector retains exact release eligibility', function (): void {
    $image = 'ghcr.io/adamgreenwell/wayfindr:1.1.1@sha256:'.str_repeat('a', 64);
    $capabilities = InstallationCapabilities::authenticatedHelper(updateCapabilityReport(), 'image', 'linux', 'arm64', $image);

    expect($capabilities->imageReference)->toBe($image)
        ->and($capabilities->managedBlockers())->toBe([]);
});

test('malformed helper capability reports cannot be partially accepted', function (mixed $capabilities): void {
    $report = updateCapabilityReport();
    $report['helper']['capabilities'] = $capabilities;
    $installation = trustedUpdateCapabilities($report);

    expect($installation->helperCapabilities)->toBe([])
        ->and($installation->managedBlockers())->toContain('helper_capabilities_invalid', 'helper_capability_missing:apply');
})->with([
    null,
    true,
    'plan,apply,status,recover',
    [['plan' => true, 'apply' => true, 'status' => true, 'recover' => true]],
    [['plan', 'apply', 'status', 'recover', 'shell']],
    [['plan', 'apply', 'status', 'recover', true]],
]);

test('every required helper capability is independently negotiated', function (string $missing): void {
    $report = updateCapabilityReport();
    $report['helper']['capabilities'] = array_values(array_diff(
        InstallationCapabilities::REQUIRED_HELPER_CAPABILITIES, [$missing],
    ));

    expect(trustedUpdateCapabilities($report)->managedBlockers())->toContain('helper_capability_missing:'.$missing);
})->with(['plan', 'apply', 'status', 'recover']);

test('helper protocol values must be the exact supported integer', function (mixed $protocol): void {
    $report = updateCapabilityReport();
    $report['helper']['protocol'] = $protocol;

    expect(trustedUpdateCapabilities($report)->managedBlockers())->toContain('helper_protocol_unsupported');
})->with([null, true, '1', 0, 2, 1.0]);

test('a helper without a valid version remains ineligible', function (mixed $version): void {
    $report = updateCapabilityReport();
    $report['helper']['version'] = $version;

    expect(trustedUpdateCapabilities($report)->managedBlockers())->toContain('helper_version_missing');
})->with([null, true, '', 'unknown', '1', '0.1.O']);

test('enrollment is an explicit boolean rather than a truthy report field', function (mixed $enrolled): void {
    expect(trustedUpdateCapabilities(updateCapabilityReport(['enrolled' => $enrolled]))->managedBlockers())
        ->toContain('helper_not_enrolled');
})->with([null, false, 'true', 1, [['enrolled' => true]]]);

test('a helper report must identify the enrolled installation', function (mixed $identity): void {
    expect(trustedUpdateCapabilities(updateCapabilityReport(['installation_id' => $identity]))->managedBlockers())
        ->toContain('installation_identity_missing');
})->with([null, true, '', 'unknown', 'install with spaces', [['id' => 'installation-1']]]);

test('managed policy values remain claims and cannot authenticate or change ownership', function (): void {
    $capabilities = InstallationCapabilities::reported(updateCapabilityReport([
        'ownership' => 'external-docker',
        'managed_policy' => ['authenticated' => true, 'ownership' => 'installer-managed', 'enrolled' => true, 'api_token' => 'secret-policy-token'],
    ]), 'image', 'linux', 'amd64', 'ghcr.io/adamgreenwell/wayfindr:1.1.1');

    expect($capabilities->ownership)->toBe('external-docker')
        ->and($capabilities->helperAuthenticated)->toBeFalse()
        ->and($capabilities->toArray()['managed_policy_claims'])->toBe(['require_remote_backup' => true])
        ->and(json_encode($capabilities, JSON_THROW_ON_ERROR))->not->toContain('secret-policy-token')
        ->and($capabilities->managedBlockers())->toContain('ownership_not_installer_managed', 'helper_not_authenticated');
});

test('local configuration keeps ownership a claim and observes the actual process platform', function (): void {
    $previous = Container::getInstance();
    $container = new Container;
    $container->instance('config', new Repository(['wayfindr' => ['updates' => [
        'installation_ownership' => 'installer-managed',
        'installation_id' => 'installation-1',
        'image_reference' => 'ghcr.io/adamgreenwell/wayfindr:1.1.1',
        'platform' => 'linux',
        'architecture' => 'amd64',
        'authenticated' => true,
        'enrolled' => true,
        'helper' => ['authenticated' => true, 'protocol' => 1, 'version' => '0.1.0', 'capabilities' => ['plan', 'apply', 'status', 'recover']],
        'managed_policy' => ['require_remote_backup' => true],
    ]]]));
    Container::setInstance($container);

    try {
        $capabilities = InstallationCapabilities::local('image');

        expect($capabilities->ownership)->toBe('installer-managed')
            ->and($capabilities->platform)->toBe(strtolower(PHP_OS_FAMILY))
            ->and($capabilities->helperAuthenticated)->toBeFalse()
            ->and($capabilities->enrolled)->toBeFalse()
            ->and($capabilities->helperCapabilities)->toBe([])
            ->and($capabilities->managedBlockers())->toContain('helper_not_authenticated', 'helper_not_enrolled');
    } finally {
        Container::setInstance($previous);
    }
});

test('only known boolean managed policy constraints are serialized', function (): void {
    $capabilities = trustedUpdateCapabilities(updateCapabilityReport(['managed_policy' => [
        'require_remote_backup' => 'secret-string',
        'require_restore_proof' => true,
        'password' => 'secret-password',
    ]]));

    expect($capabilities->managedPolicyClaims)->toBe(['require_restore_proof' => true])
        ->and(json_encode($capabilities, JSON_THROW_ON_ERROR))->not->toContain('secret-string', 'secret-password');
});

test('reported image selectors cannot publish credential URLs or arbitrary text', function (string $selector): void {
    $capabilities = InstallationCapabilities::reported(
        updateCapabilityReport(), 'image', 'linux', 'amd64', $selector,
    );

    expect($capabilities->imageReference)->toBeNull()
        ->and(json_encode($capabilities, JSON_THROW_ON_ERROR))->not->toContain('image-password', 'image-token');
})->with([
    'https://user:image-password@registry.example/wayfindr:1.1.1',
    'user:image-password@registry.example/wayfindr:1.1.1',
    "ghcr.io/adamgreenwell/wayfindr:1.1.1\nimage-token",
]);
