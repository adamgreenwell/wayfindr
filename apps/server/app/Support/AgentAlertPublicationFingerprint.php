<?php

declare(strict_types=1);

namespace App\Support;

/** Identify the alert-bearing portion of a database notification payload. */
final class AgentAlertPublicationFingerprint
{
    /** @var list<string> */
    private const BOOKKEEPING_KEYS = [
        AlertDigestCandidateCollector::DIGEST_DELIVERY_CLAIM_KEY,
        AlertDigestCandidateCollector::DIGEST_QUEUED_AT_KEY,
        UnattendedConversationAlertCollector::UNATTENDED_DELIVERY_CLAIM_KEY,
        UnattendedConversationAlertCollector::UNATTENDED_EMAILED_AT_KEY,
    ];

    /** @param array<string, mixed> $data */
    public static function for(array $data): string
    {
        foreach (self::BOOKKEEPING_KEYS as $key) {
            unset($data[$key]);
        }

        return hash('sha256', serialize(self::normalise($data)));
    }

    private static function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::normalise(...), $value);
    }
}
