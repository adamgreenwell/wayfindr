<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * Deterministic minimum-context scrubber applied immediately before a prompt.
 *
 * It is defense in depth, not a promise that arbitrary prose can be perfectly
 * classified. Callers must still omit known identity fields and attachments.
 */
final class AiContextSanitizer
{
    public function sanitize(string $input): string
    {
        $sanitized = $this->stripPrivateKeyBlocks($input);
        $sanitized = $this->stripUrlSecrets($sanitized);
        $sanitized = $this->replace(
            '/\b(password|passwd|api[ _-]?key|secret|token|access[ _-]?token|refresh[ _-]?token|authorization)\s*[:=]\s*(?<quote>\x22|\x27)(?:\\\\.|(?!\k<quote>)[^\r\n]){4,}\k<quote>/i',
            '$1=[REDACTED]',
            $sanitized,
        );
        $sanitized = $this->replace(
            '/\b(password|passwd|api[ _-]?key|secret|token|access[ _-]?token|refresh[ _-]?token|authorization)\s*[:=]\s*(?!\[REDACTED\])((?:Bearer\s+)?[^\s,;"\']{4,})/i',
            '$1=[REDACTED]',
            $sanitized,
        );
        $sanitized = $this->replace(
            '/\bBearer\s+[A-Za-z0-9._~+\/=\-]{8,}(?![A-Za-z0-9._~+\/=\-])/i',
            'Bearer [REDACTED]',
            $sanitized,
        );
        $sanitized = $this->replace(
            '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/',
            '[TOKEN REDACTED]',
            $sanitized,
        );
        $sanitized = $this->replace(
            '/\b(?:sk-[A-Za-z0-9_-]{12,}|gh[pousr]_[A-Za-z0-9]{12,}|AKIA[A-Z0-9]{16})\b/',
            '[CREDENTIAL REDACTED]',
            $sanitized,
        );
        $sanitized = $this->replace(
            '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i',
            '[EMAIL REDACTED]',
            $sanitized,
        );
        $sanitized = $this->replace(
            '/\b(phone|mobile|telephone|tel)\s*[:=]\s*(\+?[0-9][0-9\s().-]{6,}[0-9])/i',
            '$1=[PHONE REDACTED]',
            $sanitized,
        );
        $sanitized = $this->stripIpAddresses($sanitized);
        $sanitized = $this->replace(
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i',
            '[IDENTIFIER REDACTED]',
            $sanitized,
        );

        return preg_replace_callback(
            '/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/',
            fn (array $match): string => $this->looksLikePaymentCard($match[0])
                ? '[PAYMENT CARD REDACTED]'
                : $match[0],
            $sanitized,
        ) ?? $sanitized;
    }

    private function stripPrivateKeyBlocks(string $input): string
    {
        return $this->replace(
            '/-----BEGIN [^-\r\n]*PRIVATE KEY-----.*?(?:-----END [^-\r\n]*PRIVATE KEY-----|\z)/si',
            '[PRIVATE KEY REDACTED]',
            $input,
        );
    }

    private function stripUrlSecrets(string $input): string
    {
        return preg_replace_callback(
            '~https?://[^\s<>"\']+~i',
            function (array $match): string {
                $url = rtrim($match[0], '.,;:!?)]}');
                $suffix = substr($match[0], strlen($url));
                $secretOffset = strcspn($url, '?#');
                $urlWithoutSecrets = $this->stripUrlUserinfo(substr($url, 0, $secretOffset));
                $parts = parse_url($urlWithoutSecrets);

                if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
                    // Even malformed URL-shaped text must not retain query or
                    // fragment data: parser failure cannot bypass the boundary.
                    return $urlWithoutSecrets.$suffix;
                }

                $authority = $parts['scheme'].'://'.$parts['host'];

                if (isset($parts['port'])) {
                    $authority .= ':'.$parts['port'];
                }

                return $authority.($parts['path'] ?? '').$suffix;
            },
            $input,
        ) ?? $input;
    }

    private function stripUrlUserinfo(string $url): string
    {
        $schemeEnd = strpos($url, '://');

        if ($schemeEnd === false) {
            return $url;
        }

        $authorityStart = $schemeEnd + 3;
        $pathStart = strpos($url, '/', $authorityStart);
        $authorityEnd = $pathStart === false ? strlen($url) : $pathStart;
        $authority = substr($url, $authorityStart, $authorityEnd - $authorityStart);
        $userinfoEnd = strrpos($authority, '@');

        if ($userinfoEnd === false) {
            return $url;
        }

        return substr($url, 0, $authorityStart)
            .substr($authority, $userinfoEnd + 1)
            .substr($url, $authorityEnd);
    }

    private function stripIpAddresses(string $input): string
    {
        $sanitized = $this->replace(
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
            '[IP ADDRESS REDACTED]',
            $input,
        );

        $sanitized = preg_replace_callback(
            '/\[([0-9a-f:.]+)\]/i',
            fn (array $match): string => filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? '[IP ADDRESS REDACTED]'
                : $match[0],
            $sanitized,
        ) ?? $sanitized;

        return preg_replace_callback(
            '/(?<![0-9a-z:.])([0-9a-f:.]*:[0-9a-f:.]+)(?![0-9a-z:.])/i',
            function (array $match): string {
                $candidate = $match[1];
                $suffix = '';

                while ($candidate !== '') {
                    if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                        return '[IP ADDRESS REDACTED]'.$suffix;
                    }

                    $punctuation = substr($candidate, -1);

                    if (! in_array($punctuation, ['.', ':'], true)) {
                        break;
                    }

                    $candidate = substr($candidate, 0, -1);
                    $suffix = $punctuation.$suffix;
                }

                return $match[0];
            },
            $sanitized,
        ) ?? $sanitized;
    }

    private function looksLikePaymentCard(string $candidate): bool
    {
        $digits = preg_replace('/\D/', '', $candidate) ?? '';

        if (strlen($digits) < 13 || strlen($digits) > 19) {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            $digit = (int) $digits[$index];

            if ($double) {
                $digit *= 2;
                $digit = $digit > 9 ? $digit - 9 : $digit;
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }

    private function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? $subject;
    }
}
