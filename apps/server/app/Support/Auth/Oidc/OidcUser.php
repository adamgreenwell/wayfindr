<?php

declare(strict_types=1);

namespace App\Support\Auth\Oidc;

final readonly class OidcUser
{
    /** @param list<string> $roleClaimValues */
    public function __construct(
        public string $subject,
        public ?string $email,
        public bool $emailVerified,
        public ?string $name = null,
        public array $roleClaimValues = [],
    ) {}
}
