<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Support\Mail\InboundMailRouter;
use App\Support\Mail\InboundMessage;
use App\Support\Mail\Signatures\InboundMailVerifiers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where a mail provider delivers what it parsed.
 *
 * Signed rather than authenticated, exactly as the GitHub, GitLab and Jira
 * webhooks already are: the caller is a provider, not a person, and what it
 * proves about itself is the only thing separating it from anybody who learned
 * the URL. One install-wide secret, because the mail provider is install-wide
 * -- sites are told apart by the address written to, not by their own endpoint.
 *
 * WHICH proof depends on the provider, and they do not agree: see
 * `InboundMailVerifiers`. This endpoint once accepted only Wayfindr's own
 * scheme, which no provider emits, so a native webhook got 401 on every
 * delivery.
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

        // The scheme the operator's provider actually speaks. Unknown names
        // verify nothing and therefore accept nothing.
        $verifier = InboundMailVerifiers::for((string) config('wayfindr.mail.inbound_provider', 'wayfindr'));

        if ($verifier === null || ! $verifier->verify($request, $secret)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $message = InboundMessage::fromPayload($this->payloadWithUploadedFiles($request));

        if ($message === null) {
            Log::info('Inbound mail rejected: no usable sender.');

            return response()->json(['message' => 'Ignored.'], 200);
        }

        return response()->json([
            'message' => $router->route($message) === null ? 'Ignored.' : 'Accepted.',
        ], 200);
    }

    /**
     * Fold multipart uploads into the shape `InboundMessage` reads.
     *
     * A Mailgun route posts attachments as FILES -- `attachment-1`,
     * `attachment-2` -- while `InboundMessage` reads a base64 `attachments`
     * array, which is how Postmark and the JSON providers send them. Left
     * unadapted the endpoint answered `Accepted.`, which also stops the
     * provider retrying, while every attached file was silently discarded.
     * Losing somebody's screenshot and reporting success is worse than
     * refusing the delivery outright.
     *
     * A payload that already carries `attachments` is left alone: only one of
     * the two shapes is ever present, and preferring the one the provider
     * chose avoids inventing a merge nobody sends.
     *
     * @return array<string, mixed>
     */
    private function payloadWithUploadedFiles(Request $request): array
    {
        $payload = $request->all();

        if (! empty($payload['attachments']) || ! empty($payload['Attachments'])) {
            return $payload;
        }

        $attachments = [];

        foreach ($request->allFiles() as $field => $file) {
            // Only the fields a mail route names. Anything else on a webhook
            // body is not a mail attachment and has no business becoming one.
            if (! str_starts_with((string) $field, 'attachment-')) {
                continue;
            }

            foreach (is_array($file) ? $file : [$file] as $uploaded) {
                if (! $uploaded || ! $uploaded->isValid()) {
                    continue;
                }

                $contents = @file_get_contents($uploaded->getRealPath());

                if ($contents === false) {
                    continue;
                }

                $attachments[] = [
                    'name' => $uploaded->getClientOriginalName(),
                    // A display hint only, and one the SENDER wrote -- the
                    // upload pipeline sniffs the real type from the bytes.
                    'content_type' => $uploaded->getClientMimeType() ?: 'application/octet-stream',
                    'content' => base64_encode($contents),
                ];
            }
        }

        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }

        return $payload;
    }
}
