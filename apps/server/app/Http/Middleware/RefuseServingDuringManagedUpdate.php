<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Updates\ManagedUpdateGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Stop new PHP intake before sessions, maintenance bypasses, or route writers. */
final readonly class RefuseServingDuringManagedUpdate
{
    public function __construct(private ManagedUpdateGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $held = $this->gate->active();
        } catch (Throwable) {
            $held = true;
        }

        if ($held) {
            $response = response('Wayfindr is temporarily unavailable during a managed update.', 503)
                ->header('Content-Type', 'text/plain; charset=utf-8')
                ->header('Retry-After', '60')
                ->header('Cache-Control', 'no-store');
        } else {
            $response = $next($request);
        }

        // The host verifies that the configured origin reaches this release.
        // This is a read-only response header, never an intake/maintenance bypass.
        $challenge = $request->header('X-Wayfindr-Update-Challenge');
        if (config('wayfindr.updates.helper_enabled') === true && $request->isMethod('GET')
            && $request->getPathInfo() === '/up' && is_string($challenge)
            && preg_match('/^[a-f0-9]{64}$/D', $challenge) === 1) {
            try {
                $state = $this->gate->status();
                $version = config('wayfindr.release.version');
                $commit = config('wayfindr.release.commit');
                $key = config('app.key');
                if (! is_string($version) || preg_match('/^v?(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $version) !== 1
                    || ! is_string($commit) || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/Di', $commit) !== 1
                    || ! is_string($key) || $key === '') {
                    return $response;
                }
                $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
                if (! is_string($raw) || ! in_array(strlen($raw), [16, 32], true)) {
                    return $response;
                }
                $payload = json_encode([1, $challenge, $state['operation_id'], ltrim($version, 'v'), strtolower($commit)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $derived = hash_hmac('sha256', 'wayfindr-managed-origin-v1', $raw, true);
                $response->headers->set('X-Wayfindr-Update-Proof', hash_hmac('sha256', $payload, $derived));
                $response->headers->set('Cache-Control', 'no-store');
            } catch (Throwable) {
                // Unreadable ownership/identity must not manufacture a proof.
            }
        }

        return $response;
    }
}
