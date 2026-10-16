<?php

declare(strict_types=1);

namespace App\Support\Ai\Network;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/** Convert bounded headers to numeric advice; never retain provider text. */
final class AiRetryAdvice
{
    public static function parse(#[SensitiveParameter] ResponseInterface $response): array
    {
        $values = $response->getHeader('Retry-After');
        if (count($values) !== 1) {
            return ['seconds' => null, 'at' => null];
        }
        $value = trim($values[0]);
        if (preg_match('/\A[0-9]{1,8}\z/', $value) === 1 && (int) $value <= 31_536_000) {
            return ['seconds' => (int) $value, 'at' => null];
        }
        if (strlen($value) !== 29) {
            return ['seconds' => null, 'at' => null];
        }
        $date = DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \\G\\M\\T', $value, new DateTimeZone('UTC'));
        if ($date !== false && $date->format('D, d M Y H:i:s \\G\\M\\T') === $value) {
            return ['seconds' => null, 'at' => $date->getTimestamp()];
        }

        return ['seconds' => null, 'at' => null];
    }
}
