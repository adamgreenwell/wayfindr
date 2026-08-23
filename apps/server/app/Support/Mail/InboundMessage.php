<?php

namespace App\Support\Mail;

/**
 * One arriving email, in the shape Wayfindr works with.
 *
 * Providers post their own JSON and each names the same things differently, so
 * the alternates below are field names rather than adapters: Postmark says
 * `TextBody`, Mailgun says `body-plain`, and the difference is a lookup, not a
 * class hierarchy. An operator whose provider does neither can post the
 * documented shape directly.
 *
 * Deliberately no MIME parsing. Every inbound-parse product has already done
 * that, and doing it again in PHP means either a PECL extension a self-hoster
 * has to install or a dependency for the hardest part of the format.
 */
final class InboundMessage
{
    /**
     * @param  list<array{name: string, content_type: string, content: string}>  $attachments
     * @param  list<string>  $recipients
     */
    private function __construct(
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly array $recipients,
        public readonly string $subject,
        public readonly string $body,
        public readonly ?string $messageId,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly array $attachments,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        $from = self::first($payload, ['from', 'From', 'sender', 'FromFull.Email']);
        $fromEmail = self::address($from);

        // Without a sender there is nobody to answer, and nothing to attribute
        // the message to. Refused rather than guessed at.
        if ($fromEmail === null) {
            return null;
        }

        $body = (string) (self::first($payload, ['text', 'TextBody', 'body-plain', 'body', 'stripped-text']) ?? '');

        return new self(
            $fromEmail,
            self::displayName($from) ?? self::text(self::first($payload, ['from_name', 'FromName', 'FromFull.Name'])),
            self::recipients($payload),
            self::text(self::first($payload, ['subject', 'Subject'])) ?? '(no subject)',
            // Cut here rather than at render time: the transcript stores what
            // the sender wrote, and every later reader gets the same thing.
            QuotedText::strip($body),
            self::text(self::first($payload, ['message_id', 'MessageID', 'Message-Id', 'message-id'])),
            self::text(self::first($payload, ['in_reply_to', 'InReplyTo', 'In-Reply-To'])),
            self::references($payload),
            self::attachments($payload),
        );
    }

    /**
     * Every Message-ID this reply claims kinship with, newest intent first.
     *
     * `In-Reply-To` is the direct parent and `References` is the chain. Both are
     * consulted because clients disagree about which they populate, and a
     * thread that loses one is a conversation split in two.
     *
     * @return list<string>
     */
    public function threadCandidates(): array
    {
        $candidates = $this->inReplyTo === null ? [] : [$this->inReplyTo];

        // Reversed: the nearest ancestor is the best match, and References runs
        // oldest to newest.
        foreach (array_reverse($this->references) as $reference) {
            $candidates[] = $reference;
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private static function recipients(array $payload): array
    {
        $found = [];

        foreach (['to', 'To', 'recipient', 'cc', 'Cc', 'original_recipient', 'OriginalRecipient'] as $key) {
            $value = self::first($payload, [$key]);

            if (is_string($value)) {
                // A header may carry several, comma-separated.
                foreach (explode(',', $value) as $part) {
                    $address = self::address($part);

                    if ($address !== null) {
                        $found[] = $address;
                    }
                }

                continue;
            }

            if (is_array($value)) {
                foreach ($value as $entry) {
                    $address = self::address(is_array($entry) ? ($entry['Email'] ?? $entry['email'] ?? null) : $entry);

                    if ($address !== null) {
                        $found[] = $address;
                    }
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private static function references(array $payload): array
    {
        $raw = self::first($payload, ['references', 'References']);

        if (is_array($raw)) {
            return array_values(array_filter(array_map(self::text(...), $raw)));
        }

        if (! is_string($raw)) {
            return [];
        }

        // Whitespace-separated in the header, and clients differ on how much.
        return array_values(array_filter(preg_split('/\s+/', trim($raw)) ?: []));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{name: string, content_type: string, content: string}>
     */
    private static function attachments(array $payload): array
    {
        $raw = self::first($payload, ['attachments', 'Attachments']);
        $parsed = [];

        foreach (is_array($raw) ? $raw : [] as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $content = $attachment['content'] ?? $attachment['Content'] ?? $attachment['data'] ?? null;
            $name = self::text($attachment['name'] ?? $attachment['Name'] ?? $attachment['filename'] ?? null);

            if (! is_string($content) || $name === null) {
                continue;
            }

            $parsed[] = [
                'name' => $name,
                // A display hint only. The upload pipeline sniffs the real type
                // from the bytes, which matters more here than anywhere else:
                // this header is written by the sender.
                'content_type' => self::text($attachment['content_type'] ?? $attachment['ContentType'] ?? null) ?? 'application/octet-stream',
                'content' => $content,
            ];
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private static function first(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function address(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        // "Ada Lovelace <ada@example.test>" or a bare address.
        if (preg_match('/<([^>]+)>/', $value, $match) === 1) {
            $value = $match[1];
        }

        $address = strtolower(trim($value));

        return filter_var($address, FILTER_VALIDATE_EMAIL) === false ? null : $address;
    }

    private static function displayName(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\s*"?([^"<]+?)"?\s*</', $value, $match) !== 1) {
            return null;
        }

        return self::text($match[1]);
    }

    private static function text(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : mb_substr($text, 0, 998);
    }
}
