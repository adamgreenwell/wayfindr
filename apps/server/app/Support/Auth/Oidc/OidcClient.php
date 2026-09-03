<?php

declare(strict_types=1);

namespace App\Support\Auth\Oidc;

use App\Models\OidcConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

interface OidcClient
{
    public function redirect(Request $request, OidcConnection $connection): RedirectResponse;

    public function user(Request $request, OidcConnection $connection): OidcUser;
}
