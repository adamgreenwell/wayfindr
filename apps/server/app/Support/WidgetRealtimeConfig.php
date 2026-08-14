<?php

namespace App\Support;

/**
 * The browser-facing Reverb settings, or null when realtime is not configured.
 *
 * Extracted because two callers must agree about it and disagreeing is silent:
 * the site controller decides whether to hand a page realtime settings, and the
 * widget script controller decides whether to bundle the realtime library into
 * widget.js. A page given settings but served a script without the library gets
 * no realtime; a script carrying 61KB of library for a page that never uses it
 * is waste. One answer, asked in one place.
 */
class WidgetRealtimeConfig
{
    /**
     * @return array{app_key: string, host: string, port: string, scheme: string}|null
     */
    public static function public(): ?array
    {
        if ((string) config('broadcasting.default') !== 'reverb') {
            return null;
        }

        $appKey = config('broadcasting.connections.reverb.key');

        // Browser-facing values: in containerized installs the server-side host
        // is an internal service address the browser cannot reach.
        // Single-endpoint deployments set no client_* values and fall back.
        $host = config('broadcasting.connections.reverb.options.client_host')
            ?? config('broadcasting.connections.reverb.options.host');
        $port = config('broadcasting.connections.reverb.options.client_port')
            ?? config('broadcasting.connections.reverb.options.port');
        $scheme = config('broadcasting.connections.reverb.options.client_scheme')
            ?? config('broadcasting.connections.reverb.options.scheme');

        if (! self::hasValue($appKey) || ! self::hasValue($host) || ! self::hasValue($port) || ! self::hasValue($scheme)) {
            return null;
        }

        return [
            'app_key' => (string) $appKey,
            'host' => (string) $host,
            'port' => (string) $port,
            'scheme' => (string) $scheme,
        ];
    }

    public static function isConfigured(): bool
    {
        return self::public() !== null;
    }

    private static function hasValue(mixed $value): bool
    {
        return is_string($value) ? trim($value) !== '' : $value !== null;
    }
}
