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

        // No release, no unwinding, nothing to hand back. A retry carries the
        // same message and therefore the same fingerprint, so the claim
        // accepts it -- which is why the three rounds of release machinery
        // that used to sit here could go. Whether a retry should be
        // re-PROCESSED is a different question, answered by
        // `InboundMailRouter::alreadyAccepted()` against a unique index.
        return $this->handle($request, $router);
    }

    /**
     * The work a verified delivery actually does.
     *
     * Separate from `__invoke` so that "did this reach a terminal outcome?" is
     * a question about ONE return value rather than a promise every future
     * branch has to remember to keep.
     */
    private function handle(Request $request, InboundMailRouter $router): JsonResponse
    {
        $adapted = $this->payloadWithUploadedFiles($request);

        // A file the sender sent that could not be turned into an attachment
        // -- the upload never completed, or completed and will not read.
        // Answering 200 would stop the provider retrying while the file is
        // gone, and 422 asks for the delivery again, which is the only way it
        // survives. One decision, taken where the read happens.
        if ($adapted === null) {
            return response()->json(['message' => 'An attachment could not be read.'], 422);
        }

        $message = InboundMessage::fromPayload($this->payloadWithProviderHeaders($adapted));

        // Deliberately 200, and deliberately terminal: there is no sender to
        // route to, and no retry will invent one.
        if ($message === null) {
            Log::info('Inbound mail rejected: no usable sender.');

            return response()->json(['message' => 'Ignored.'], 200);
        }

        return response()->json([
            'message' => $router->route($message) === null ? 'Ignored.' : 'Accepted.',
        ], 200);
    }

    /**
     * Lift threading headers out of wherever the provider hid them.
     *
     * `InboundMessage` reads `Message-Id`, `In-Reply-To` and `References` at
     * the top level, which is where the JSON providers put them. Mailgun also
     * puts them inside `message-headers`, a JSON-encoded array of
     * [name, value] pairs -- a parsed route forwards both, so this lift is
     * belt-and-braces rather than the only path. Postmark keeps its own array
     * of {Name, Value}, and there it IS the only path.
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
    /**
     * Null when a file the sender sent cannot be turned into an attachment.
     *
     * The read happens here exactly once, so the decision belongs here too --
     * asking a separate `hasUnusableUpload()` would read every file a second
     * time and leave a gap in which the check passes and the build fails.
     *
     * @return array<string, mixed>|null
     */
    private function payloadWithUploadedFiles(Request $request): ?array
    {
        $payload = $request->all();

        // An ARRAY, not merely something truthy. `attachments=1` passed an
        // emptiness check, so a caller holding a valid tuple could add that one
        // scalar and have every multipart file skipped -- the message commits
        // without its attachments, and message-id deduplication then stops the
        // provider's own retry from restoring them. The scalar is not in the
        // fingerprint either, which is why it cost nothing to add.
        if (is_array($payload['attachments'] ?? null) || is_array($payload['Attachments'] ?? null)) {
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
                // An upload PHP itself rejected -- over `upload_max_filesize`,
                // truncated, interrupted. Reported rather than skipped, for
                // the same reason as the read failure below.
                if (! $uploaded || ! $uploaded->isValid()) {
                    return null;
                }

                $path = $uploaded->getRealPath();
                $contents = $path === false ? false : @file_get_contents($path);

                // PHP said the upload completed and the bytes still will not
                // read -- a transient permissions or filesystem fault. Skipping
                // it silently answered `200 Accepted.`, which stops the
                // provider retrying and loses the file permanently, and worse,
                // if it was the only attachment the message is indistinguishable
                // from one that never had it.
                if ($contents === false) {
                    Log::warning('Inbound mail: an attachment could not be read.', [
                        'name' => $uploaded->getClientOriginalName(),
                    ]);

                    return null;
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
