<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Models\Visitor;
use App\Support\Visitors\VisitorEraser;
use App\Support\Visitors\VisitorLabel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Erase one contact and everything held about them (ADR 0026). */
final class AgentVisitorErasureController extends Controller
{
    /**
     * Typed, not translated: the word is a deliberate act, the same in every
     * dashboard language, and the sentences around it are translated.
     */
    public const CONFIRMATION_WORD = 'ERASE';

    public function show(Request $request, Visitor $visitor, VisitorEraser $eraser): View
    {
        $this->authorizeErasure($request, $visitor);

        $agent = $request->user();

        return view('agent.visitors.erase', [
            'agent' => $agent,
            'account' => $agent->account,
            'visitor' => $visitor->loadMissing('site'),
            'identity' => VisitorLabel::forVisitor($visitor, __('visitors.common.not_reported')),
            'summary' => $eraser->summarize($visitor),
            'confirmationWord' => self::CONFIRMATION_WORD,
        ]);
    }

    public function store(Request $request, Visitor $visitor, VisitorEraser $eraser): RedirectResponse
    {
        $this->authorizeErasure($request, $visitor);

        $validated = $request->validate([
            'confirmation' => ['required', 'string'],
            'current_password' => ['required', 'current_password'],
        ]);

        if ($validated['confirmation'] !== self::CONFIRMATION_WORD) {
            throw ValidationException::withMessages([
                'confirmation' => __('visitor_erasure.errors.confirm', ['word' => self::CONFIRMATION_WORD]),
            ]);
        }

        $receipt = $eraser->erase($request->user(), $visitor);

        return redirect()
            ->route('dashboard.visitors.index')
            ->with('status', 'visitor_erasure.flash.erased')
            ->with('erasure_receipt', $receipt->public_id);
    }

    private function authorizeErasure(Request $request, Visitor $visitor): void
    {
        $actor = $request->user();

        // Not visible is not found, as everywhere a visitor is addressed.
        abort_unless(Gate::forUser($actor)->allows('view', $visitor), 404);
        abort_unless($actor->hasAccountPermission(AccountPermission::HandleDataRequests), 403);
    }
}
