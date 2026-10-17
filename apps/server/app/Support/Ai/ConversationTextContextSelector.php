<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\ApiToken;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ProactiveMessageRule;
use App\Models\User;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use JsonException;

/** Select the shared minimum, text-only conversation context for copilot features. */
final readonly class ConversationTextContextSelector
{
    private const MAX_MESSAGE_CHARACTERS = 4_000;

    // Fetch enough extra text for the scrubber to see a sensitive pattern that
    // crosses the output boundary, without materializing an unbounded body.
    private const MAX_RAW_MESSAGE_CHARACTERS = 8_000;

    private const MAX_SUBJECT_CHARACTERS = 255;

    private const MESSAGE_CHUNK_SIZE = 25;

    private const PREFERRED_CONTEXT_CHARACTERS = 20_000;

    public function __construct(private AiContextSanitizer $sanitizer) {}

    /** @throws JsonException */
    public function select(Conversation $conversation, ?int $maximumCharacters = null): ?ConversationTextContext
    {
        $messageQuery = $conversation->messages()
            ->whereNotNull('body')
            ->whereRaw("TRIM(body) <> ''");

        $limit = min(
            self::PREFERRED_CONTEXT_CHARACTERS,
            app(AiAssistPolicyResolver::class)->forConversation($conversation)->maxContextCharacters,
            $maximumCharacters === null ? PHP_INT_MAX : max(300, $maximumCharacters),
        );
        $subject = filled($conversation->subject)
            ? mb_substr($this->sanitizer->sanitize(trim((string) $conversation->subject)), 0, self::MAX_SUBJECT_CHARACTERS)
            : null;
        $subject = $this->fitSubject($subject, $limit);
        $selected = [];
        $selectedRevisions = [];
        $selectedSources = [];
        $lastMessageId = null;
        $truncated = false;

        $messages = $messageQuery
            ->select(['id', 'sender_type'])
            ->selectRaw('SUBSTR(body, 1, ?) AS body', [self::MAX_RAW_MESSAGE_CHARACTERS])
            ->lazyByIdDesc(self::MESSAGE_CHUNK_SIZE);

        foreach ($messages as $message) {
            $rawBody = trim((string) $message->body);

            if ($rawBody === '') {
                continue;
            }

            $lastMessageId ??= (int) $message->id;
            $sanitizedBody = $this->sanitizer->sanitize($rawBody);
            $entryWasTruncated = mb_strlen($rawBody) >= self::MAX_RAW_MESSAGE_CHARACTERS
                || mb_strlen($sanitizedBody) > self::MAX_MESSAGE_CHARACTERS;
            $entry = [
                'role' => $this->role($message),
                'body' => mb_substr($sanitizedBody, 0, self::MAX_MESSAGE_CHARACTERS),
            ];
            // Server-only source identity also detects changes hidden by redaction.
            // It never joins the provider input or retains a message body.
            $revision = $this->sourceProof($message);
            $source = ['id' => (int) $message->id, 'proof' => $revision];
            $candidate = [$entry, ...$selected];
            $candidateWasTruncated = $truncated || $entryWasTruncated;

            if (mb_strlen($this->encode($subject, $candidate, $candidateWasTruncated)) <= $limit) {
                $selected = $candidate;
                $selectedRevisions = [$revision, ...$selectedRevisions];
                $selectedSources = [$source, ...$selectedSources];
                $truncated = $candidateWasTruncated;

                continue;
            }

            $truncated = true;

            if ($selected === []) {
                $entry['body'] = $this->fitNewestBody($subject, $entry, $limit);
                $selected = [$entry];
                $selectedRevisions = [$revision];
                $selectedSources = [$source];
            }

            break;
        }

        if ($selected === [] || $lastMessageId === null) {
            return null;
        }

        return new ConversationTextContext(
            input: $this->encode($subject, $selected, $truncated),
            messageCount: count($selected),
            lastMessageId: $lastMessageId,
            sourceSnapshot: ['conversation_id' => (int) $conversation->id,
                'subject' => hash_hmac('sha256', (string) $conversation->subject, (string) config('app.key')),
                'messages' => $selectedSources],
            sourceRevision: hash_hmac('sha256', json_encode([
                'conversation-text-v1', (int) $conversation->id,
                hash_hmac('sha256', (string) $conversation->subject, (string) config('app.key')), $selectedRevisions,
            ], JSON_THROW_ON_ERROR), (string) config('app.key')),
        );
    }

    /** Re-authorize exactly the old selected sources; append-only activity may remain readable as stale. */
    public function sourceStillCurrent(Conversation $conversation, array $snapshot): bool
    {
        $sources = $snapshot['messages'] ?? null;
        if (($snapshot['conversation_id'] ?? null) !== (int) $conversation->id
            || ! is_string($snapshot['subject'] ?? null)
            || ! hash_equals($snapshot['subject'], hash_hmac('sha256', (string) $conversation->subject, (string) config('app.key')))
            || ! is_array($sources) || $sources === [] || count($sources) > 1000) {
            return false;
        }
        foreach ($sources as $source) {
            if (! is_array($source) || ! is_int($source['id'] ?? null) || ! is_string($source['proof'] ?? null)) {
                return false;
            }
        }
        $messages = $conversation->messages()->whereIn('id', array_column($sources, 'id'))
            ->select(['id', 'sender_type'])->selectRaw('SUBSTR(body, 1, ?) AS body', [self::MAX_RAW_MESSAGE_CHARACTERS])
            ->get()->keyBy('id');
        foreach ($sources as $source) {
            $message = $messages->get($source['id']);
            if ($message === null || ! hash_equals($source['proof'], $this->sourceProof($message))) {
                return false;
            }
        }

        return true;
    }

    private function sourceProof(ConversationMessage $message): string
    {
        return hash_hmac('sha256', json_encode([
            (int) $message->id, $this->role($message),
            hash_hmac('sha256', trim((string) $message->body), (string) config('app.key')),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /**
     * @param  list<array{role: string, body: string}>  $messages
     *
     * @throws JsonException
     */
    private function encode(?string $subject, array $messages, bool $truncated): string
    {
        return json_encode([
            'subject' => $subject,
            'context_truncated' => $truncated,
            'messages' => $messages,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array{role: string, body: string}  $entry
     *
     * @throws JsonException
     */
    private function fitNewestBody(?string $subject, array $entry, int $limit): string
    {
        $low = 0;
        $high = mb_strlen($entry['body']);

        while ($low < $high) {
            $length = intdiv($low + $high + 1, 2);
            $candidate = $entry;
            $candidate['body'] = mb_substr($entry['body'], 0, $length);

            if (mb_strlen($this->encode($subject, [$candidate], true)) <= $limit) {
                $low = $length;
            } else {
                $high = $length - 1;
            }
        }

        return mb_substr($entry['body'], 0, $low);
    }

    /** @throws JsonException */
    private function fitSubject(?string $subject, int $limit): ?string
    {
        if ($subject === null) {
            return null;
        }

        $low = 0;
        $high = mb_strlen($subject);
        $budget = max(200, intdiv($limit, 3));

        while ($low < $high) {
            $length = intdiv($low + $high + 1, 2);

            if (mb_strlen($this->encode(mb_substr($subject, 0, $length), [], false)) <= $budget) {
                $low = $length;
            } else {
                $high = $length - 1;
            }
        }

        return mb_substr($subject, 0, $low);
    }

    private function role(ConversationMessage $message): string
    {
        return match ($message->sender_type) {
            User::class => 'agent',
            ApiToken::class => 'integration',
            ProactiveMessageRule::class => 'support_automation',
            default => 'visitor',
        };
    }
}
