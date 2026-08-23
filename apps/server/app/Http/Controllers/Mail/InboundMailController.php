<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Support\Mail\InboundMailRouter;
use App\Support\Mail\InboundMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where a mail provider delivers what it parsed.
 *
 * Signed rather than authenticated, exactly as the GitHub, GitLab and Jira
 * webhooks already are: the caller is a provider, not a person, and an HMAC
 * over the raw body is the only thing separating it from anybody who learned
 * the URL. One install-wide secret, because the mail provider is install-wide
 * -- sites are told apart by the address written to, not by their own endpoint.
 *
 * Answers 200 to anything accepted OR deliberately ignored. A provider retries
 * on a failure code, and retrying a message addressed to a site that does not
 * exist would retry forever.
 */
class InboundMailController extends Controller
{
    public function __invoke(Request $request, InboundMailRouter $router): JsonResponse
    {
        $secret = trim((string) config('wayfindr.mail.inbound_secret'));

        // No secret means the channel is off. Refusing is the safe reading: an
        // open endpoint that writes conversations is worse than one an operator
        // has to switch on.
        if ($secret === '') {
            return response()->json(['message' => 'Inbound mail is not configured.'], 404);
        }

        if (! $this->signatureIsValid($request, $secret)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $message = InboundMessage::fromPayload($request->all());

        if ($message === null) {
            Log::info('Inbound mail rejected: no usable sender.');

            return response()->json(['message' => 'Ignored.'], 200);
        }

        return response()->json([
            'message' => $router->route($message) === null ? 'Ignored.' : 'Accepted.',
        ], 200);
    }

    /**
     * Compared with hash_equals, which takes the same time whatever the input.
     * A plain === leaks how much of a guess was right, one byte at a time.
     */
    private function signatureIsValid(Request $request, string $secret): bool
    {
        $signature = (string) $request->header('X-Wayfindr-Signature', '');

        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }
}
