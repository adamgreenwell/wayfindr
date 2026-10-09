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
            return response('Wayfindr is temporarily unavailable during a managed update.', 503)
                ->header('Content-Type', 'text/plain; charset=utf-8')
                ->header('Retry-After', '60')
                ->header('Cache-Control', 'no-store');
        }

        return $next($request);
    }
}
