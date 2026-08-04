<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Release\UpgradeGuard;
use App\Support\Release\UpgradeRequirements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Refuses traffic while an `after-start` requirement is outstanding (ADR 0013).
 *
 * These cannot block migration: the action needs the migrated schema and the
 * running code to be performed at all, so blocking migration would withhold the
 * very state it requires and the requirement could never be satisfied. They gate
 * serving instead — the release starts, refuses traffic, and says what is
 * outstanding.
 *
 * The health endpoint is deliberately still served. The ADR's wording is that
 * the app "starts, refuses traffic, and says what is outstanding": a container
 * failing its health check would be restarted on a loop by the orchestrator,
 * which replaces a legible message with a crash loop and makes the operator's
 * job harder rather than safer. The requirement is visible on every other route.
 */
class RefuseServingWithUnmetRequirements
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('up')) {
            return $next($request);
        }

        $outstanding = $this->outstanding();

        if ($outstanding === []) {
            return $next($request);
        }

        $lines = ['This release needs something done before it can serve traffic.', ''];

        foreach ($outstanding as $action) {
            $lines[] = sprintf('%s (from %s)', $action['id'] ?? '?', $action['release'] ?? '?');
            $lines[] = '  '.($action['summary'] ?? '');

            if (($action['detail'] ?? '') !== '') {
                $lines[] = '  '.$action['detail'];
            }

            $lines[] = sprintf('  Acknowledge with: %s/%s', $action['release'] ?? '?', $action['id'] ?? '?');
            $lines[] = '';
        }

        $lines[] = 'Set WAYFINDR_ACKNOWLEDGED_ACTIONS once the work is done, then restart.';

        return response(implode("\n", $lines), Response::HTTP_SERVICE_UNAVAILABLE)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Retry-After', '3600');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function outstanding(): array
    {
        $guard = app(UpgradeGuard::class);

        try {
            $assessment = $guard->assessAll();
        } catch (Throwable) {
            // Unassessable, which here means the database is unreachable. Serving
            // is not the place to enforce that: the request will fail on its own
            // if it needs the database, and turning a blip into a 500 from
            // middleware would take out `/up`-adjacent routes and any page that
            // does not. The migration gate is where this is actually enforced.
            return [];
        }

        // An empty list means "nothing outstanding" only when the release could
        // actually be assessed. An unreadable manifest or history produces the
        // same empty list from a very different situation — nothing is known
        // about what this release owes — and serving on it is the fail-open this
        // gate exists to prevent.
        if (! $guard->lastAssessable()) {
            return [[
                'id' => 'release-declaration-unreadable',
                'release' => 'unknown',
                'phase' => 'after-start',
                'summary' => 'This release cannot say what it requires.',
                'detail' => 'Its declaration or history is missing or unreadable, so whether '
                    .'anything is outstanding is unknown. Repull the image or the checkout.',
            ]];
        }

        return array_values(array_filter(
            $assessment,
            static fn (array $a): bool => in_array(
                $a['phase'] ?? '', UpgradeRequirements::BLOCKS_SERVING, true,
            ),
        ));
    }
}
