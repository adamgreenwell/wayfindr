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

        $payload = $this->payloadWithProviderHeaders($this->payloadWithUploadedFiles($request));

        // A file the upload never completed. Skipping it and answering 200
        // would stop the provider retrying while the attachment is gone --
        // the same trade this endpoint already refuses elsewhere. 422 tells
        // the provider to try again, which is the only way the file survives.
        if ($this->hasUnusableUpload($request)) {
            Log::warning('Inbound mail refused: an attachment did not upload completely.');

            // The retry carries the same signature, so anything spent to
            // authenticate this attempt has to go back before we ask for one.
            // Without this the 422 that exists to SAVE the attachment is what
            // loses the whole message: the retry is refused as a replay.
            $verifier->release($request);

            return response()->json(['message' => 'An attachment could not be read.'], 422);
        }

        $message = InboundMessage::fromPayload($payload);

        if ($message === null) {
            Log::info('Inbound mail rejected: no usable sender.');

            return response()->json(['message' => 'Ignored.'], 200);
        }

        return response()->json([
            'message' => $router->route($message) === null ? 'Ignored.' : 'Accepted.',
        ], 200);
    }

    /**
     * Did a file arrive that PHP could not finish receiving?
     *
     * The likeliest cause is an attachment larger than `upload_max_filesize`,
     * which commonly still sits at PHP's 2M default while Wayfindr accepts
     * ten. The rest of the multipart body parses fine, so nothing else
     * notices.
     */
    private function hasUnusableUpload(Request $request): bool
    {
        foreach ($request->allFiles() as $field => $file) {
            if (! str_starts_with((string) $field, 'attachment-')) {
                continue;
            }

            foreach (is_array($file) ? $file : [$file] as $uploaded) {
                if ($uploaded && ! $uploaded->isValid()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Lift threading headers out of wherever the provider hid them.
     *
     * `InboundMessage` reads `Message-Id`, `In-Reply-To` and `References` at
     * the top level, which is where the JSON providers put them. Mailgun does
     * not: they are inside `message-headers`, a JSON-encoded array of
     * [name, value] pairs. Postmark keeps its own array of {Name, Value}.
     *
     * Left unlifted, `threadCandidates()` finds nothing and every customer
     * REPLY opens a new conversation -- which is the single thing the email
     * channel exists to prevent, and it would look like the feature working.
     *
     * Existing top-level values win. A provider that sends both means them.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function payloadWithProviderHeaders(array $payload): array
    {
        $headers = [];

        // Mailgun: a JSON string, or already decoded by the framework.
        $mailgun = $payload['message-headers'] ?? null;

        if (is_string($mailgun)) {
            $mailgun = json_decode($mailgun, true);
        }

        foreach (is_array($mailgun) ? $mailgun : [] as $pair) {
            if (is_array($pair) && count($pair) >= 2 && is_string($pair[0] ?? null)) {
                $headers[strtolower($pair[0])] = $pair[1];
            }
        }

        // Postmark: [{Name, Value}, ...].
        foreach (is_array($payload['Headers'] ?? null) ? $payload['Headers'] : [] as $header) {
            if (is_array($header) && is_string($header['Name'] ?? null)) {
                $headers[strtolower($header['Name'])] = $header['Value'] ?? null;
            }
        }

        foreach ([
            'message-id' => 'Message-Id',
            'in-reply-to' => 'In-Reply-To',
            'references' => 'References',
        ] as $wire => $field) {
            $value = $headers[$wire] ?? null;

            if (is_string($value) && $value !== '' && ($payload[$field] ?? null) === null) {
                $payload[$field] = $value;
            }
        }

        return $payload;
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
