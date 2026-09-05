<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Enums\VisitorAttributeType;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\VisitorAttributeDefinition;
use App\Support\VisitorContextSanitizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Define how safe host context becomes useful contact data. */
final class AgentAccountVisitorAttributeController extends Controller
{
    private const MAX_DEFINITIONS = 20;

    public function __construct(private readonly VisitorContextSanitizer $visitorContextSanitizer) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->authorizeManagement($actor);

        return view('agent.account.visitor-attributes', [
            'account' => $actor->account()->firstOrFail(),
            'agent' => $actor,
            'definitions' => VisitorAttributeDefinition::query()
                ->where('account_id', $actor->account_id)
                ->orderBy('label')
                ->orderBy('id')
                ->get(),
            'maximumDefinitions' => self::MAX_DEFINITIONS,
            'types' => VisitorAttributeType::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeManagement($actor);
        $attributes = $this->validatedAttributes($request, true);

        try {
            $definition = DB::transaction(function () use ($actor, $attributes): VisitorAttributeDefinition {
                $actor = $this->lockedManager($actor);

                if (VisitorAttributeDefinition::query()
                    ->where('account_id', $actor->account_id)
                    ->count() >= self::MAX_DEFINITIONS) {
                    throw ValidationException::withMessages([
                        'key' => __('visitor_attributes.errors.limit', ['count' => self::MAX_DEFINITIONS]),
                    ]);
                }

                $definition = VisitorAttributeDefinition::query()->create([
                    'account_id' => $actor->account_id,
                    ...$attributes,
                ]);
                $this->audit($actor, $definition, 'visitor_attribute.created');

                return $definition;
            });
        } catch (UniqueConstraintViolationException) {
            $this->throwDuplicateKeyValidation();
        }

        return redirect()
            ->route('dashboard.account.visitor-attributes.index', ['attribute' => $definition->id])
            ->with('status', 'visitor_attributes.flash.created');
    }

    public function update(Request $request, string $visitorAttribute): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeManagement($actor);
        $attributes = $this->validatedAttributes($request, false, $visitorAttribute);

        DB::transaction(function () use ($actor, $attributes, $visitorAttribute): void {
            $actor = $this->lockedManager($actor);
            $definition = $this->definitionFor($actor, $visitorAttribute, true);
            $definition->fill($attributes)->save();
            $this->audit($actor, $definition, 'visitor_attribute.updated');
        });

        return redirect()
            ->route('dashboard.account.visitor-attributes.index', ['attribute' => $visitorAttribute])
            ->with('status', 'visitor_attributes.flash.updated');
    }

    public function destroy(Request $request, string $visitorAttribute): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeManagement($actor);

        DB::transaction(function () use ($actor, $visitorAttribute): void {
            $actor = $this->lockedManager($actor);
            $definition = $this->definitionFor($actor, $visitorAttribute, true);
            $this->audit($actor, $definition, 'visitor_attribute.deleted');
            $definition->delete();
        });

        return redirect()
            ->route('dashboard.account.visitor-attributes.index')
            ->with('status', 'visitor_attributes.flash.deleted');
    }

    /** @return array{key?: string, label: string, type: string} */
    private function validatedAttributes(Request $request, bool $creating, ?string $definitionId = null): array
    {
        $rules = [
            'label' => ['required', 'string', 'max:80'],
            'type' => ['required', 'string', Rule::in(VisitorAttributeType::values())],
        ];

        if ($creating) {
            $rules['key'] = ['required', 'string', 'max:64', 'regex:/\A[a-z][a-z0-9_]*\z/'];
        } else {
            $rules['editing_definition'] = ['required', 'string', Rule::in([$definitionId])];
        }

        $validated = $request->validate($rules);

        if ($creating && ! $this->visitorContextSanitizer->isSafeContextKey($validated['key'])) {
            throw ValidationException::withMessages([
                'key' => __('visitor_attributes.errors.unsafe_key'),
            ]);
        }

        $label = Str::of($validated['label'])->squish()->toString();

        if ($label === '') {
            throw ValidationException::withMessages([
                'label' => __('validation.required', ['attribute' => __('visitor_attributes.fields.label')]),
            ]);
        }

        return array_filter([
            'key' => $creating ? $validated['key'] : null,
            'label' => $label,
            'type' => $validated['type'],
        ], fn (mixed $value): bool => $value !== null);
    }

    private function definitionFor(User $actor, string $definitionId, bool $lock = false): VisitorAttributeDefinition
    {
        abort_unless(ctype_digit($definitionId), 404);
        $query = VisitorAttributeDefinition::query()
            ->whereKey((int) $definitionId)
            ->where('account_id', $actor->account_id);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function lockedManager(User $actor): User
    {
        $accountId = (int) $actor->account_id;
        Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
        $lockedActor = User::query()
            ->whereKey($actor->id)
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->first();

        abort_unless($lockedActor instanceof User, 403);
        $this->authorizeManagement($lockedActor);

        return $lockedActor;
    }

    private function authorizeManagement(User $actor): void
    {
        abort_unless($actor->hasAccountPermission(AccountPermission::ManageContacts), 403);
    }

    private function throwDuplicateKeyValidation(): never
    {
        throw ValidationException::withMessages([
            'key' => __('visitor_attributes.errors.duplicate'),
        ]);
    }

    private function audit(User $actor, VisitorAttributeDefinition $definition, string $action): void
    {
        AuditEvent::query()->create([
            'account_id' => $actor->account_id,
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->id,
            'subject_type' => $definition->getMorphClass(),
            'subject_id' => $definition->id,
            'action' => $action,
            // The definition is operational metadata. Visitor values are
            // deliberately absent from the account audit trail.
            'metadata' => [
                'attribute_key' => $definition->key,
                'attribute_label' => $definition->label,
                'attribute_type' => $definition->type->value,
            ],
            'occurred_at' => now(),
        ]);
    }
}
