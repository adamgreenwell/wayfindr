<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountPermission;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\TicketCreated;
use App\Events\TicketUpdated;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\TicketAssigned;
use App\Rules\DecodableCursor;
use App\Support\Api\ApiIdempotency;
use App\Support\Api\ApiScope;
use App\Support\Api\V1\Payload;
use App\Support\DatabaseKey;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Tickets in the deliberately narrow public contract (ADR 0018).
 */
class TicketController extends Controller
{
    public function __construct(private readonly SiteManagerCoverage $siteManagerCoverage) {}

    public function store(Request $request, ApiIdempotency $idempotency): JsonResponse
    {
        $scope = ApiScope::fromRequest($request);

        $validated = $request->validate([
            'site_id' => ['required', 'integer'],
            'requester_id' => ['nullable', 'integer'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'priority' => ['nullable', 'string', Rule::enum(TicketPriority::class)],
        ]);

        $result = $idempotency->run(
            $request,
            $validated,
            'ticket',
            function (ApiToken $token) use ($scope, $validated): Ticket {
                $site = Site::query()
                    ->whereIn('id', $scope->writableSiteIdsQuery())
                    ->whereKey($validated['site_id'])
                    ->sharedLock()
                    ->first();

                if ($site === null) {
                    $this->invalidReference('site_id');
                }

                $requesterId = $validated['requester_id'] ?? null;
                $requester = null;

                if ($requesterId !== null) {
                    $requester = Visitor::query()
                        ->where('site_id', $site->id)
                        ->whereKey($requesterId)
                        // Serializes with the presence pruner's final check so
                        // a valid requester cannot disappear between this
                        // lookup and the ticket's foreign-key insert.
                        ->lockForUpdate()
                        ->first();

                    if ($requester === null) {
                        $this->invalidReference('requester_id');
                    }

                    // A ticket is support contact just as a conversation is.
                    // Keep the live-board profile state and retention marker
                    // aligned with the relationship this transaction creates.
                    $requester->forceFill(['presence_only' => false])->save();
                }

                $ticket = Ticket::query()->create([
                    'account_id' => $scope->accountId(),
                    'site_id' => $site->id,
                    'requester_id' => $requesterId,
                    'status' => 'open',
                    'priority' => $validated['priority'] ?? 'normal',
                    'subject' => $validated['subject'],
                    'description' => $validated['description'] ?? null,
                    'metadata' => ['source' => 'api'],
                ]);

                $this->recordActivity($ticket, $token, 'ticket.created', ['source' => 'api']);
                event(new TicketCreated($ticket));

                return $ticket;
            },
            fn (int $id) => Ticket::query()
                ->where('account_id', $scope->accountId())
                ->whereIn('site_id', $scope->siteIdsQuery())
                ->whereKey($id)
                ->first(),
        );

        return response()
            // Creation receipts echo what the caller supplied plus generated
            // identity/defaults. A later retry must not become a read of edits
            // somebody made after creation.
            ->json(['data' => Payload::createdTicket($result->resource, $validated)], 201)
            ->header('Idempotent-Replayed', $result->replayed ? 'true' : 'false');
    }

    public function index(Request $request): JsonResponse
    {
        $scope = ApiScope::fromRequest($request);

        $validated = $request->validate([
            'site_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', Rule::enum(TicketStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', new DecodableCursor],
        ]);

        $tickets = Ticket::query()
            // Account AND site. The account filter is redundant while site ids
            // derive from it, and it stays because the day somebody changes how
            // sites are scoped is the day redundancy earns its keep.
            ->where('account_id', $scope->accountId())
            ->whereIn('site_id', $scope->siteIdsQuery())
            ->when(isset($validated['site_id']), fn ($query) => $query->whereIn(
                'site_id',
                // Narrows only, never widens.
                $scope->includesSite((int) $validated['site_id']) ? [(int) $validated['site_id']] : [],
            ))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($validated['per_page'] ?? 25, ['*'], 'cursor', $validated['cursor'] ?? null);

        return response()->json(Payload::page($tickets, Payload::ticket(...)));
    }

    /**
     * The id arrives RAW, not coerced to `int`.
     *
     * The route constrains shape and not range, so a thirty-digit id is
     * accepted -- and PHP cannot coerce that into an `int` parameter, so the
     * request dies with a TypeError before the method body runs. A 500 where
     * the contract documents 404.
     */
    public function show(Request $request, string $ticket): JsonResponse
    {
        $scope = ApiScope::fromRequest($request);

        // An id too large to be a key cannot match one, so it is treated exactly
        // like an id that is not there.
        abort_unless(DatabaseKey::isValid($ticket), 404);

        $found = Ticket::query()
            ->where('account_id', $scope->accountId())
            ->whereIn('site_id', $scope->siteIdsQuery())
            ->whereKey($ticket)
            ->firstOrFail();

        return response()->json(['data' => Payload::ticket($found)]);
    }

    /**
     * Status and assignment only. A token cannot silently inherit the wider
     * dashboard edit form just because both operate on a Ticket model.
     */
    public function update(Request $request, string $ticket): JsonResponse
    {
        $scope = ApiScope::fromRequest($request);

        abort_unless(DatabaseKey::isValid($ticket), 404);

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::enum(TicketStatus::class)],
            'assignee_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        if (! array_key_exists('status', $validated) && ! array_key_exists('assignee_id', $validated)) {
            throw ValidationException::withMessages([
                'status' => 'Provide a status or assignee_id to change.',
            ]);
        }

        $candidate = Ticket::query()
            ->where('account_id', $scope->accountId())
            ->whereIn('site_id', $scope->writableSiteIdsQuery())
            ->whereKey($ticket)
            ->firstOrFail();

        $newAssignee = null;
        $assigneeChanged = false;

        $ticket = DB::transaction(function () use (
            $scope,
            $candidate,
            $validated,
            &$newAssignee,
            &$assigneeChanged,
        ): Ticket {
            $this->siteManagerCoverage->lockAccount($scope->accountId());
            $token = ApiToken::query()
                ->whereKey($scope->token->getKey())
                ->lockForUpdate()
                ->first();

            // Authentication happened before the transaction, so revocation
            // can otherwise land in the small gap before this update. POSTs
            // take this same lock through ApiIdempotency; PATCH must keep the
            // same clean ordering even though it needs no idempotency receipt.
            if ($token === null || ! $token->isUsable()) {
                throw new HttpResponseException(response()->json([
                    'message' => 'That API token is not valid.',
                ], 401));
            }

            $requestedAssigneeId = array_key_exists('assignee_id', $validated)
                && $validated['assignee_id'] !== null
                    ? (int) $validated['assignee_id']
                    : null;

            if ($requestedAssigneeId !== null) {
                $newAssignee = User::query()
                    ->where('account_id', $scope->accountId())
                    ->whereKey($requestedAssigneeId)
                    ->lockForUpdate()
                    ->first();
            }

            $site = Site::query()->whereKey($candidate->site_id)->sharedLock()->first();

            if ($site === null || $site->isArchived() || ! $scope->includesWritableSite((int) $site->id)) {
                abort(404);
            }

            $locked = Ticket::query()
                ->where('account_id', $scope->accountId())
                ->where('site_id', $site->id)
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->firstOrFail();

            $attributes = [];
            $previousStatus = (string) $locked->status;
            $nextStatus = array_key_exists('status', $validated)
                ? (string) $validated['status']
                : $previousStatus;

            if ($nextStatus !== $previousStatus) {
                $attributes['status'] = $nextStatus;
                $attributes['closed_at'] = $nextStatus === 'closed' ? now() : null;
            }

            $oldAssigneeId = $locked->assignee_id === null ? null : (int) $locked->assignee_id;
            $nextAssigneeId = $oldAssigneeId;

            if (array_key_exists('assignee_id', $validated)) {
                $nextAssigneeId = $validated['assignee_id'] === null ? null : (int) $validated['assignee_id'];

                if ($nextAssigneeId !== null) {
                    if ($newAssignee === null
                        || ! $site->supportsAgent($newAssignee)
                        || ! $newAssignee->hasAccountPermission(AccountPermission::ManageTickets)) {
                        $this->invalidReference('assignee_id');
                    }
                }

                if ($nextAssigneeId !== $oldAssigneeId) {
                    $attributes['assignee_id'] = $nextAssigneeId;
                    $assigneeChanged = true;
                }
            }

            if ($attributes !== []) {
                $locked->forceFill($attributes)->save();
            }

            if ($nextStatus !== $previousStatus) {
                // Leaving closed is a reopen whichever target state follows.
                if ($previousStatus === 'closed') {
                    $this->recordActivity($locked, $token, 'ticket.reopened', ['source' => 'api']);
                }

                if ($nextStatus === 'pending') {
                    $this->recordActivity($locked, $token, 'ticket.pending', ['source' => 'api']);
                } elseif ($previousStatus === 'pending' && $nextStatus === 'open') {
                    $this->recordActivity($locked, $token, 'ticket.unheld', ['source' => 'api']);
                } elseif ($nextStatus === 'closed') {
                    $this->recordActivity($locked, $token, 'ticket.closed', ['source' => 'api']);
                }
            }

            if ($assigneeChanged) {
                $oldAssigneeName = $oldAssigneeId === null
                    ? null
                    : User::query()->whereKey($oldAssigneeId)->value('name');

                $this->recordActivity($locked, $token, 'ticket.assignee_updated', [
                    'source' => 'api',
                    'old_assignee_id' => $oldAssigneeId,
                    'old_assignee_name' => $oldAssigneeName,
                    'new_assignee_id' => $newAssignee?->id,
                    'new_assignee_name' => $newAssignee?->name,
                ]);
            }

            if ($attributes !== []) {
                event(new TicketUpdated($locked));
            }

            return $locked;
        });

        if ($assigneeChanged
            && $newAssignee !== null
            && (int) $ticket->assignee_id === (int) $newAssignee->id
            && $newAssignee->shouldReceiveTicketAssignmentAlert($ticket)) {
            try {
                $newAssignee->notify(new TicketAssigned($ticket, $scope->token));
            } catch (Throwable $exception) {
                // The ticket is already assigned. A mail or queue outage must
                // not turn that committed state into a retry that tells the
                // integration the change failed.
                Log::error('API ticket assignment stored, but its alert failed.', [
                    'ticket_id' => $ticket->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        // Only the fields this request asked to change. Returning the whole
        // Ticket payload here would make `write` an accidental read ability.
        return response()->json(['data' => Payload::updatedTicket($ticket, $validated)]);
    }

    private function invalidReference(string $field): never
    {
        throw ValidationException::withMessages([
            $field => 'The selected '.$field.' is invalid.',
        ]);
    }

    /**
     * API activity is attributable to the credential, never to its issuer.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function recordActivity(Ticket $ticket, ApiToken $token, string $action, array $metadata = []): void
    {
        $ticket->auditEvents()->create([
            'account_id' => $ticket->account_id,
            'site_id' => $ticket->site_id,
            'actor_type' => $token->getMorphClass(),
            'actor_id' => $token->id,
            'action' => $action,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
