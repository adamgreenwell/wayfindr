<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Models\Visitor;
use App\Support\DatabaseKey;
use App\Support\Visitors\VisitorIdentityMerger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class AgentVisitorMergeController extends Controller
{
    public function __invoke(Request $request, Visitor $visitor, VisitorIdentityMerger $merger): RedirectResponse
    {
        $actor = $request->user();
        abort_unless(Gate::forUser($actor)->allows('view', $visitor), 404);
        abort_unless($actor->hasAccountPermission(AccountPermission::ManageContacts), 403);

        $validated = $request->validate([
            'target_id' => ['required', 'string'],
            'confirmed' => ['accepted'],
        ]);
        $targetId = (string) $validated['target_id'];

        if (! DatabaseKey::isValid($targetId)) {
            throw ValidationException::withMessages([
                'target_id' => __('visitor_merge.errors.target_required'),
            ]);
        }

        $target = $merger->merge($actor, $visitor, (int) $targetId);

        return redirect()
            ->route('dashboard.visitors.show', $target)
            ->withFragment('visitor-merge-heading')
            ->with('status', 'visitor_merge.flash.merged');
    }
}
