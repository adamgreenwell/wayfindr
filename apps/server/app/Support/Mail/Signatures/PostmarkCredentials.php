<?php

declare(strict_types=1);

namespace App\Support\Mail\Signatures;

use Illuminate\Http\Request;

/**
 * Postmark does not sign anything for the customer.
 *
 * There is no HMAC to verify -- not over the body, not over a timestamp. What
 * Postmark does offer is HTTP Basic credentials embedded in the webhook URL,
 * which it then sends on every delivery, so that is what this checks.
 *
 * Named for what it is rather than dressed up as a signature. It is a weaker
 * scheme than Mailgun's: there is no replay bound and no proof the body is
 * untampered, only proof the caller knew a secret. An operator choosing
 * Postmark should know that, and the documentation says so.
 *
 * The username is not checked. Postmark lets the operator write anything
 * before the colon and the password is the whole secret; comparing a username
 * we also chose would add no security and one more way to be misconfigured.
 */
final class PostmarkCredentials implements VerifiesInboundMail
{
    public function verify(Request $request, string $secret): bool
    {
        $supplied = (string) ($request->getPassword() ?? '');

        if ($supplied === '') {
            return false;
        }

        return hash_equals($secret, $supplied);
    }

    /**
     * Nothing to give back: this scheme spends no single-use state to
     * authenticate, so a retry of the same delivery verifies again on its own.
     */
    public function release(Request $request): void
    {
        //
    }
}
