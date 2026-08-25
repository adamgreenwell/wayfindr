<?php

namespace App\Support\Attachments;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A rejected attachment, with no words in it.
 *
 * The upload service and the binder are shared by three surfaces that do not
 * speak the same language. The dashboard speaks the agent's. The widget speaks
 * the visitor's, which the site pins independently of the install. Inbound mail
 * runs in a queue worker with no request, no locale and no reader at all.
 *
 * So a rejection cannot carry a sentence: the service does not know who is
 * going to read it. It carries the key and the numbers, and whichever surface
 * is answering resolves it in the language that surface owes its reader.
 *
 * This exists because the service did resolve copy, briefly, and a German
 * install started rejecting an English widget's uploads in German.
 */
final class AttachmentRejected extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public readonly string $field,
        public readonly string $key,
        public readonly array $parameters = [],
    ) {
        // The key is the message so an uncaught one is still legible in a log.
        parent::__construct($key);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function file(string $key, array $parameters = []): self
    {
        return new self('file', $key, $parameters);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function attachments(string $key, array $parameters = []): self
    {
        return new self('attachment_ids', $key, $parameters);
    }

    /**
     * Say it, in the language the calling surface names.
     *
     * A null locale means "whatever this request is already speaking", which is
     * what the dashboard wants: `SetDashboardLocale` has already put the
     * agent's language in place by the time a controller runs.
     */
    public function toValidationException(?string $locale = null): ValidationException
    {
        return ValidationException::withMessages([
            $this->field => __($this->key, $this->parameters, $locale),
        ]);
    }
}
