<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Keep private contact context attached to the person, not one ticket. */
final class AgentVisitorNoteController extends Controller
{
    public function store(Request $request, Visitor $visitor): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeManagement($actor, $visitor);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);
        $body = trim($validated['body']);

        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => __('visitor_notes.errors.required'),
            ]);
        }

        DB::transaction(function () use ($actor, $visitor, $body): void {
            [$actor, $visitor] = $this->lockedWriter($actor, $visitor);

            // A note is intentional person-level contact. Leaving this row
            // marked as heartbeat-only lets the presence pruner (or turning
            // presence reporting off) cascade-delete the note as if nobody
            // had ever used the visitor as a contact record.
            if ($visitor->presence_only) {
                $visitor->forceFill(['presence_only' => false])->save();
            }

            $note = VisitorNote::query()->create([
                'account_id' => $actor->account_id,
                'visitor_id' => $visitor->id,
                'author_id' => $actor->id,
                'body' => $body,
            ]);
            $this->audit($actor, $visitor, $note, 'visitor.note_added');
        });

        return redirect()
            ->route('dashboard.visitors.show', $visitor)
            ->withFragment('visitor-notes-heading')
            ->with('status', 'visitor_notes.flash.added');
    }

    public function destroy(Request $request, Visitor $visitor, string $visitorNote): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeManagement($actor, $visitor);

        DB::transaction(function () use ($actor, $visitor, $visitorNote): void {
            [$actor, $visitor] = $this->lockedWriter($actor, $visitor);
            abort_unless(ctype_digit($visitorNote), 404);
            $note = VisitorNote::query()
                ->whereKey((int) $visitorNote)
                ->where('account_id', $actor->account_id)
                ->where('visitor_id', $visitor->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->audit($actor, $visitor, $note, 'visitor.note_deleted');
            $note->delete();
        });

        return redirect()
            ->route('dashboard.visitors.show', $visitor)
            ->withFragment('visitor-notes-heading')
            ->with('status', 'visitor_notes.flash.deleted');
    }

    /** @return array{User, Visitor} */
    private function lockedWriter(User $actor, Visitor $visitor): array
    {
        $accountId = (int) $actor->account_id;
        Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
        $lockedActor = User::query()
            ->whereKey($actor->id)
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->first();
        $lockedVisitor = Visitor::query()
            ->whereKey($visitor->id)
            ->whereHas('site', fn ($query) => $query->where('account_id', $accountId))
            ->lockForUpdate()
            ->first();

        abort_unless($lockedActor instanceof User && $lockedVisitor instanceof Visitor, 404);
        $this->authorizeManagement($lockedActor, $lockedVisitor);

        return [$lockedActor, $lockedVisitor];
    }

    private function authorizeManagement(User $actor, Visitor $visitor): void
    {
        abort_unless(Gate::forUser($actor)->allows('view', $visitor), 404);
        abort_unless($actor->hasAccountPermission(AccountPermission::ManageContacts), 403);
    }

    private function audit(User $actor, Visitor $visitor, VisitorNote $note, string $action): void
    {
        AuditEvent::query()->create([
            'account_id' => $actor->account_id,
            'site_id' => $visitor->site_id,
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->id,
            'subject_type' => $visitor->getMorphClass(),
            'subject_id' => $visitor->id,
            'action' => $action,
            // The audit trail proves the lifecycle action without becoming a
            // second, harder-to-delete copy of the private note body.
            'metadata' => ['note_id' => $note->id],
            'occurred_at' => now(),
        ]);
    }
}
