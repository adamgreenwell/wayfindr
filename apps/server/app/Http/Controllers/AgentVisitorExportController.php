<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Models\Visitor;
use App\Support\Visitors\VisitorExporter;
use App\Support\Visitors\VisitorExportRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Download everything held about one contact (ADR 0026 §7).
 *
 * A POST: building the archive reads the whole history and writes an audit
 * event, which a link another site could embed must not be able to start.
 */
final class AgentVisitorExportController extends Controller
{
    public function __invoke(Request $request, Visitor $visitor, VisitorExporter $exporter): BinaryFileResponse|RedirectResponse
    {
        $actor = $request->user();

        // The same permission as erasure, and not visible is not found.
        abort_unless(Gate::forUser($actor)->allows('view', $visitor), 404);
        abort_unless($actor->hasAccountPermission(AccountPermission::HandleDataRequests), 403);

        try {
            $export = $exporter->export($actor, $visitor);
        } catch (VisitorExportRefused $refused) {
            $back = $refused->reason === VisitorExportRefused::GONE
                ? redirect()->route('dashboard.visitors.index')
                : redirect()->route('dashboard.visitors.show', $visitor);

            return $back->withErrors(['export' => __('visitor_export.errors.'.$refused->reason)]);
        }

        // Removed after sending, and at shutdown as well: a download the
        // browser abandons can end the request before the response does.
        $path = $export['path'];
        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) {
                @unlink($path);
            }
        });

        return response()
            ->download($path, $export['filename'], ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }
}
