<?php

declare(strict_types=1);

namespace App\Support\Auth\Oidc;

final readonly class OidcUser
{
    public function __construct(
        public string $subject,
        public ?string $email,
        public bool $emailVerified,
    ) {}
}
