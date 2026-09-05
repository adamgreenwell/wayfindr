<?php

declare(strict_types=1);

namespace App\Support;

use Base64Url\Base64Url;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Utils;
use Minishlink\WebPush\VAPID;
use Throwable;

/** Assess the optional VAPID configuration without ever exposing its secret. */
final class AgentWebPushConfig
{
    /** @return array{status: 'unset'|'incomplete'|'invalid'|'ready', has_subject: bool, has_public_key: bool, has_private_key: bool} */
    public function assessment(): array
    {
        $subject = trim((string) config('webpush.vapid.subject'));
        $publicKey = trim((string) config('webpush.vapid.public_key'));
        $privateKey = trim((string) config('webpush.vapid.private_key'));

        return $this->assessValues($subject, $publicKey, $privateKey);
    }

    /** @return array{status: 'unset'|'incomplete'|'invalid'|'ready', has_subject: bool, has_public_key: bool, has_private_key: bool} */
    public function assessValues(string $subject, string $publicKey, #[\SensitiveParameter] string $privateKey): array
    {
        $present = [
            'has_subject' => $subject !== '',
            'has_public_key' => $publicKey !== '',
            'has_private_key' => $privateKey !== '',
        ];

        if (! in_array(true, $present, true)) {
            return ['status' => 'unset', ...$present];
        }

        if (in_array(false, $present, true)) {
            return ['status' => 'incomplete', ...$present];
        }

        if (! $this->validSubject($subject)) {
            return ['status' => 'invalid', ...$present];
        }

        try {
            $validated = VAPID::validate([
                'subject' => $subject,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ]);

            if (! $this->keysMatch($subject, $validated)) {
                return ['status' => 'invalid', ...$present];
            }
        } catch (Throwable) {
            return ['status' => 'invalid', ...$present];
        }

        return ['status' => 'ready', ...$present];
    }

    public function isReady(): bool
    {
        return $this->assessment()['status'] === 'ready';
    }

    public function publicKeyForBrowser(): ?string
    {
        return $this->isReady()
            ? trim((string) config('webpush.vapid.public_key'))
            : null;
    }

    private function validSubject(string $subject): bool
    {
        if (str_starts_with($subject, 'mailto:')) {
            return filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL) !== false;
        }

        return filter_var($subject, FILTER_VALIDATE_URL) !== false
            && parse_url($subject, PHP_URL_SCHEME) === 'https';
    }

    /** @param array{subject: string, publicKey: string, privateKey: string} $validated */
    private function keysMatch(string $subject, #[\SensitiveParameter] array $validated): bool
    {
        $headers = VAPID::getVapidHeaders(
            'https://push.example.test',
            $subject,
            $validated['publicKey'],
            $validated['privateKey'],
            ContentEncoding::aes128gcm,
        );

        if (preg_match('/\Avapid t=([^,]+),/', $headers['Authorization'] ?? '', $match) !== 1) {
            return false;
        }

        [$x, $y] = Utils::unserializePublicKey($validated['publicKey']);
        $publicKey = new JWK([
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => Base64Url::encode($x),
            'y' => Base64Url::encode($y),
        ]);
        $jws = (new CompactSerializer)->unserialize($match[1]);
        $verifier = new JWSVerifier(new AlgorithmManager([new ES256]));

        return $verifier->verifyWithKey($jws, $publicKey, 0);
    }
}
