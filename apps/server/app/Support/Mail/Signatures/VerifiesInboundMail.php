<?php

declare(strict_types=1);

namespace App\Support\Mail\Signatures;

use Illuminate\Http\Request;

/**
 * Whether this delivery really came from the provider the operator configured.
 *
 * One interface because the endpoint is one endpoint, and several
 * implementations because the providers do not agree on anything: Mailgun
 * signs a timestamp and a token with its own key, Postmark computes no HMAC
 * for the customer at all, and Wayfindr's own scheme signs the raw body.
 *
 * Naming that variation here is the point. The controller used to hold a
 * single scheme that no provider emits, so an operator pointing a native
 * webhook at it got 401 on every delivery and had to run a re-signing proxy
 * Wayfindr does not ship.
 */
interface VerifiesInboundMail
{
    /**
     * The secret is whatever the operator was given by the provider: a signing
     * key, a webhook password, or the shared secret their proxy signs with.
     */
    public function verify(Request $request, string $secret): bool;
}
