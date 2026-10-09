<?php

declare(strict_types=1);

namespace App\Support\Updates;

use App\Support\Version\SemanticVersion;
use Illuminate\Container\Container;
use JsonSerializable;

/**
 * Installation claims and the separately trusted helper capability boundary.
 *
 * An image runtime does not establish who owns its deployment. Configuration,
 * JSON reports, and serialization cannot authenticate a helper. Only the future
 * host-helper transport adapter may call authenticatedHelper(), after verifying
 * the peer and binding its response to the installation being inspected.
 * This object describes eligibility; it does not execute or enroll an update.
 */
final readonly class InstallationCapabilities implements JsonSerializable
{
    public const OWNERSHIPS = [
        'installer-managed',
        'external-docker',
        'source-build',
        'host-php',
        'deployment-platform',
        'hosting-managed',
        'unknown',
    ];

    public const HELPER_PROTOCOL = 1;

    public const REQUIRED_HELPER_CAPABILITIES = ['plan', 'apply', 'status', 'recover'];

    /**
     * @param  list<string>  $helperCapabilities
     * @param  array<string, bool>  $managedPolicyClaims
     */
    private function __construct(
        public string $ownership,
        public string $runtimeProfile,
        public string $platform,
        public string $architecture,
        public ?string $imageReference,
        public ?string $installationId,
        public bool $helperAuthenticated,
        public bool $enrolled,
        public ?int $helperProtocol,
        public ?string $helperVersion,
        public array $helperCapabilities,
        public array $managedPolicyClaims,
        private bool $validHelperCapabilities,
    ) {}

    /**
     * Read claims without granting authority. Any report authentication field
     * is deliberately ignored, including a serialized trusted object's field.
     *
     * @param  array<string, mixed>  $report
     */
    public static function reported(
        array $report,
        string $runtimeProfile,
        string $platform,
        string $architecture,
        ?string $imageReference = null,
    ): self {
        return self::fromReport($report, $runtimeProfile, $platform, $architecture, $imageReference, false);
    }

    /**
     * Trusted in-process adapter boundary for U3, not a report/config option.
     * The caller must have authenticated the helper and its installation before
     * passing the helper's response here. Browser or operator input must always
     * enter through reported(), even when it claims authentication or enrollment.
     *
     * @param  array<string, mixed>  $report
     */
    public static function authenticatedHelper(
        array $report,
        string $runtimeProfile,
        string $platform,
        string $architecture,
        ?string $imageReference = null,
    ): self {
        return self::fromReport($report, $runtimeProfile, $platform, $architecture, $imageReference, true);
    }

    /**
     * Observe the actual process platform while retaining explicit configuration
     * as claims only. A future helper connection must supply authentication.
     */
    public static function local(string $profile): self
    {
        $configuration = Container::getInstance()->bound('config')
            ? config('wayfindr.updates', [])
            : [];
        $configuration = is_array($configuration) ? $configuration : [];

        return self::reported([
            'ownership' => $configuration['installation_ownership'] ?? 'unknown',
            'installation_id' => $configuration['installation_id'] ?? null,
            'managed_policy' => $configuration['managed_policy'] ?? [],
        ], $profile, PHP_OS_FAMILY, php_uname('m'), self::text($configuration['image_reference'] ?? null));
    }

    /** @return list<string> */
    public function managedBlockers(): array
    {
        $blockers = [];

        if ($this->ownership === 'unknown') {
            $blockers[] = 'ownership_unknown';
        } elseif ($this->ownership !== 'installer-managed') {
            $blockers[] = 'ownership_not_installer_managed';
        }

        if (! $this->helperAuthenticated) {
            $blockers[] = 'ownership_untrusted';
            $blockers[] = 'helper_not_authenticated';
        }

        if ($this->runtimeProfile !== 'image') {
            $blockers[] = 'runtime_profile_not_image';
        }

        if ($this->platform !== 'linux') {
            $blockers[] = 'platform_not_linux';
        }

        if (! in_array($this->architecture, ['amd64', 'arm64'], true)) {
            $blockers[] = 'architecture_unsupported';
        }

        if (! $this->isExactOfficialImage()) {
            $blockers[] = 'image_not_exact_official_release';
        }

        if ($this->installationId === null) {
            $blockers[] = 'installation_identity_missing';
        }

        if (! $this->enrolled) {
            $blockers[] = 'helper_not_enrolled';
        }

        if ($this->helperProtocol !== self::HELPER_PROTOCOL) {
            $blockers[] = 'helper_protocol_unsupported';
        }

        if ($this->helperVersion === null) {
            $blockers[] = 'helper_version_missing';
        }

        if (! $this->validHelperCapabilities) {
            $blockers[] = 'helper_capabilities_invalid';
        }

        foreach (self::REQUIRED_HELPER_CAPABILITIES as $capability) {
            if (! in_array($capability, $this->helperCapabilities, true)) {
                $blockers[] = 'helper_capability_missing:'.$capability;
            }
        }

        return $blockers;
    }

    public function guidance(): string
    {
        return match ($this->ownership) {
            'installer-managed' => 'Use install.sh --upgrade for terminal updates. Managed updates require an authenticated, compatible enrolled helper.',
            'external-docker' => 'Pull the selected image and recreate the containers through your Docker or Compose deployment.',
            'source-build' => 'Update and rebuild your source image, then redeploy it through the system that owns the deployment.',
            'host-php' => 'Use your host deployment command to update source, dependencies, assets, migrations, caches, and services.',
            'deployment-platform' => 'Update Wayfindr through the deployment platform that manages this installation.',
            'hosting-managed' => 'Your hosting service manages Wayfindr updates; use its update controls or contact its operator.',
            default => 'Confirm how this installation is deployed and who manages it before selecting an update method.',
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $blockers = $this->managedBlockers();

        return [
            'ownership' => $this->ownership,
            'runtime_profile' => $this->runtimeProfile,
            'platform' => $this->platform,
            'architecture' => $this->architecture,
            'image_reference' => $this->imageReference,
            'installation_id' => $this->installationId,
            'enrolled' => $this->enrolled,
            'helper' => [
                'authenticated' => $this->helperAuthenticated,
                'protocol' => $this->helperProtocol,
                'version' => $this->helperVersion,
                'capabilities' => $this->helperCapabilities,
            ],
            'managed_policy_claims' => $this->managedPolicyClaims,
            'managed_update_eligible' => $blockers === [],
            'managed_blockers' => $blockers,
            'manual_guidance' => $this->guidance(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param array<string, mixed> $report */
    private static function fromReport(
        array $report,
        string $runtimeProfile,
        string $platform,
        string $architecture,
        ?string $imageReference,
        bool $authenticated,
    ): self {
        $ownership = $report['ownership'] ?? null;
        $ownership = is_string($ownership) && in_array($ownership, self::OWNERSHIPS, true)
            ? $ownership
            : 'unknown';
        $installationId = self::text($report['installation_id'] ?? null);

        if ($installationId !== null && (in_array(strtolower($installationId), ['unknown', 'unset'], true)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $installationId) !== 1)) {
            $installationId = null;
        }

        $helper = $report['helper'] ?? null;
        $helper = is_array($helper) ? $helper : [];
        $protocol = $helper['protocol'] ?? null;
        $version = self::text($helper['version'] ?? null);
        $version = $version === null ? null : SemanticVersion::parse($version)?->canonical();
        $capabilities = $helper['capabilities'] ?? null;
        $validCapabilities = is_array($capabilities) && array_is_list($capabilities);

        if ($validCapabilities) {
            foreach ($capabilities as $capability) {
                if (! is_string($capability) || ! in_array($capability, self::REQUIRED_HELPER_CAPABILITIES, true)) {
                    $validCapabilities = false;
                    break;
                }
            }
        }

        $capabilities = $validCapabilities ? array_values(array_unique($capabilities)) : [];
        sort($capabilities);

        return new self(
            $ownership,
            in_array($runtimeProfile, ['image', 'host'], true) ? $runtimeProfile : 'unknown',
            strtolower(trim($platform)),
            self::normalizedArchitecture($architecture),
            self::imageSelector($imageReference),
            $installationId,
            $authenticated,
            ($report['enrolled'] ?? false) === true,
            is_int($protocol) ? $protocol : null,
            $version,
            $capabilities,
            self::policyClaims($report['managed_policy'] ?? null),
            $validCapabilities,
        );
    }

    private function isExactOfficialImage(): bool
    {
        if ($this->imageReference === null || preg_match(
            '/\Aghcr\.io\/adamgreenwell\/wayfindr:([^@]+)(?:@sha256:[0-9a-f]{64})?\z/',
            $this->imageReference,
            $matches,
        ) !== 1) {
            return false;
        }

        $version = SemanticVersion::parse($matches[1]);

        return $version !== null && ! $version->isDevelopment() && $version->build === null
            && in_array($matches[1], [$version->canonical(), 'v'.$version->canonical()], true);
    }

    private static function normalizedArchitecture(string $architecture): string
    {
        return match (strtolower(trim($architecture))) {
            'amd64', 'x86_64', 'x64' => 'amd64',
            'arm64', 'aarch64' => 'arm64',
            default => 'unknown',
        };
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function imageSelector(?string $value): ?string
    {
        $value = self::text($value);

        // Only display a Docker selector, never a URL, credential-bearing
        // transport address, interpolation expression, or arbitrary report text.
        if ($value === null || strlen($value) > 512 || preg_match(
            '/\A[a-z0-9]+(?:[._-]+[a-z0-9]+)*(?::[0-9]+)?'
            .'(?:\/[a-z0-9]+(?:[._-]+[a-z0-9]+)*)*'
            .'(?::[A-Za-z0-9_][A-Za-z0-9_.-]{0,127})?'
            .'(?:@sha256:[0-9a-f]{64})?\z/',
            $value,
        ) !== 1) {
            return null;
        }

        return $value;
    }

    /** @return array<string, bool> */
    private static function policyClaims(mixed $policy): array
    {
        if (! is_array($policy)) {
            return [];
        }

        $claims = [];

        // These optional managed-operation constraints are separate from
        // release-required actions. Never publish arbitrary helper/config data.
        foreach (['require_remote_backup', 'require_restore_proof'] as $key) {
            if (is_bool($policy[$key] ?? null)) {
                $claims[$key] = $policy[$key];
            }
        }

        ksort($claims);

        return $claims;
    }
}
