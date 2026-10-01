<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Visitors\ErasureLedger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses traffic while a restore's erasures are not re-applied yet (ADR 0026
 * §8). Until they are, contacts erased since the restored archive was taken
 * are back in the database, and serving would show them to every agent and
 * API consumer.
 *
 * The deploy cannot be trusted to hold maintenance mode for this: a standard
 * Forge deploy restores the site when `migrate` fails, and the container's
 * migration loop crash-loops on a failure that is not transient. So, like the
 * release gate beside it, the app starts, refuses traffic and says why, and
 * the health endpoint still answers so the container is not restarted on a
 * loop. The ledger records the outstanding work on the storage volume, so
 * every process sees the same answer; `wayfindr:finish-erasures`, which the
 * scheduler also runs, clears it once every erasure is re-applied.
 *
 * Deliberately generic: anyone can load this page, so it names no receipt,
 * count or cause. The command line has those.
 */
class RefuseServingWhileErasuresAreOutstanding
{
    public function __construct(private readonly ErasureLedger $ledger) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('up') || ! $this->ledger->reapplyOutstanding()) {
            return $next($request);
        }

        return response(implode("\n", [
            'Wayfindr is not serving traffic yet: a backup was restored, and contacts erased since it was taken have not been erased again.',
            '',
            'On the server, run `php artisan wayfindr:finish-erasures`. It re-applies the erasures, or says what is stopping it.',
            'If the restored database is older than this release, run `php artisan migrate --force` first; if it is newer, deploy its release.',
        ]), Response::HTTP_SERVICE_UNAVAILABLE)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Retry-After', '300');
    }
}
