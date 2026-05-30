<?php

namespace App\Support;

class VisitorContextSanitizer
{
    private const MAX_ITEMS = 10;

    private const MAX_KEY_LENGTH = 64;

    private const MAX_VALUE_LENGTH = 160;

    private const SENSITIVE_KEY_PATTERN = '/(password|passwd|pwd|passcode|secret|token|api[\s_-]?key|auth|authorization|cookie|session|credit|card|(?:^|[\s_-])cc(?:$|[\s_-])|cvc|cvv|ssn|social|tax|ein|sin|national[\s_-]?id|bank|routing|iban|sort[\s_-]?code|username|user[\s_-]?name|login|email|phone|telephone|address|postal|zip|birth|dob)/i';

    /**
     * @return array<string, string>
     */
    public function sanitize(mixed $context): array
    {
        if (! is_array($context)) {
            return [];
        }

        $safeContext = [];

        foreach ($context as $key => $value) {
            if (count($safeContext) >= self::MAX_ITEMS) {
                break;
            }

            $label = $this->safeLabel($key);

            if ($label === null || $this->looksSensitiveKey($label)) {
                continue;
            }

            $displayValue = $this->safeValue($value);

            if ($displayValue === null || $this->looksSensitiveValue($displayValue)) {
                continue;
            }

            $safeContext[$label] = $displayValue;
        }

        return $safeContext;
    }

    public function sanitizeIdentifier(mixed $value): ?string
    {
        $displayValue = $this->safeValue($value);

        if ($displayValue === null || $this->looksSensitiveValue($displayValue)) {
            return null;
        }

        return $displayValue;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    public function mergeMetadata(?array $metadata, ?string $pageUrl, bool $contextWasProvided, mixed $context): array
    {
        $metadata = $metadata ?? [];

        if ($pageUrl !== null) {
            $metadata['last_page_url'] = $pageUrl;
        } elseif (! array_key_exists('last_page_url', $metadata)) {
            $metadata['last_page_url'] = null;
        }

        if ($contextWasProvided) {
            $metadata['context'] = $this->sanitize($context);
        }

        return $metadata;
    }

    private function safeLabel(mixed $key): ?string
    {
        if (! is_string($key) && ! is_int($key)) {
            return null;
        }

        $label = trim((string) $key);

        if ($label === '') {
            return null;
        }

        return mb_substr($label, 0, self::MAX_KEY_LENGTH);
    }

    private function safeValue(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null || (! is_string($value) && ! is_int($value) && ! is_float($value))) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, self::MAX_VALUE_LENGTH);
    }

    private function looksSensitiveKey(string $key): bool
    {
        return preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1;
    }

    private function looksSensitiveValue(string $value): bool
    {
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $value) === 1) {
            return true;
        }

        if (preg_match('/(?:\d[ -]?){13,19}/', $value) === 1) {
            return true;
        }

        return preg_match('/\b(?:sk|rk|ghp|gho|ghu|ghs|glpat|xox[baprs])_[A-Za-z0-9_-]{12,}\b/', $value) === 1;
    }
}
