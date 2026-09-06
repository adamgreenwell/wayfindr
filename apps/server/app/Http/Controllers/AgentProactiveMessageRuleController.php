<?php

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Models\AuditEvent;
use App\Models\ProactiveMessageRule;
use App\Models\Site;
use App\Models\User;
use App\Support\Sites\SiteManagerCoverage;
use App\Support\Sites\SitePresenceReporting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AgentProactiveMessageRuleController extends Controller
{
    private const MAX_POSITION = 10000;

    public function __construct(private readonly SiteManagerCoverage $siteManagerCoverage) {}

    public function index(Request $request, Site $site): View
    {
        $agent = $this->manager($request, $site);

        return view('agent.proactive-messages.index', [
            'account' => $agent->account()->firstOrFail(),
            'agent' => $agent,
            'presenceEnabled' => SitePresenceReporting::for($site)->enabled,
            'rules' => $site->proactiveMessageRules()->inEvaluationOrder()->get(),
            'site' => $site,
        ]);
    }

    public function create(Request $request, Site $site): View
    {
        $agent = $this->manager($request, $site);

        return view('agent.proactive-messages.form', [
            'account' => $agent->account()->firstOrFail(),
            'agent' => $agent,
            'defaultPosition' => min(
                self::MAX_POSITION,
                ((int) $site->proactiveMessageRules()->max('position')) + 10,
            ),
            'proactiveMessageRule' => null,
            'site' => $site,
        ]);
    }

    public function store(Request $request, Site $site): RedirectResponse
    {
        $agent = $this->manager($request, $site);

        $rule = DB::transaction(function () use ($agent, $request, $site): ProactiveMessageRule {
            [$agent, $site] = $this->lockedManagerAndSite($agent, $site, 403);
            $attributes = $this->validatedAttributes($request);
            $this->ensureUniqueName($site, $attributes['name']);
            $rule = $site->proactiveMessageRules()->create($attributes);

            $this->audit($agent, $site, $rule, 'proactive_message_rule.created', [
                'name' => $rule->name,
                'is_enabled' => $rule->is_enabled,
                'position' => $rule->position,
            ]);

            return $rule;
        });

        return redirect()
            ->route('dashboard.sites.proactive-messages.edit', [$site, $rule])
            ->with('status', 'proactive_messages.flash.created');
    }

    public function edit(Request $request, Site $site, ProactiveMessageRule $proactiveMessageRule): View
    {
        $agent = $this->manager($request, $site);
        $this->authorizeRule($site, $proactiveMessageRule);

        return view('agent.proactive-messages.form', [
            'account' => $agent->account()->firstOrFail(),
            'agent' => $agent,
            'defaultPosition' => $proactiveMessageRule->position,
            'proactiveMessageRule' => $proactiveMessageRule,
            'site' => $site,
        ]);
    }

    public function update(
        Request $request,
        Site $site,
        ProactiveMessageRule $proactiveMessageRule,
    ): RedirectResponse {
        $agent = $this->manager($request, $site);
        $this->authorizeRule($site, $proactiveMessageRule);

        DB::transaction(function () use ($agent, $proactiveMessageRule, $request, $site): void {
            [$agent, $site] = $this->lockedManagerAndSite($agent, $site);
            $rule = $this->lockedRule($site, $proactiveMessageRule);
            $attributes = $this->validatedAttributes($request);
            $this->ensureUniqueName($site, $attributes['name'], (int) $rule->id);
            $rule->fill($attributes);
            $changed = array_values(array_keys($rule->getDirty()));
            $rule->save();

            if ($changed !== []) {
                $this->audit($agent, $site, $rule, 'proactive_message_rule.updated', [
                    'name' => $rule->name,
                    'is_enabled' => $rule->is_enabled,
                    'changed' => $changed,
                ]);
            }
        });

        return redirect()
            ->route('dashboard.sites.proactive-messages.edit', [$site, $proactiveMessageRule])
            ->with('status', 'proactive_messages.flash.updated');
    }

    public function destroy(
        Request $request,
        Site $site,
        ProactiveMessageRule $proactiveMessageRule,
    ): RedirectResponse {
        $agent = $this->manager($request, $site);
        $this->authorizeRule($site, $proactiveMessageRule);

        DB::transaction(function () use ($agent, $proactiveMessageRule, $site): void {
            [$agent, $site] = $this->lockedManagerAndSite($agent, $site);
            $rule = $this->lockedRule($site, $proactiveMessageRule);

            $this->audit($agent, $site, $rule, 'proactive_message_rule.deleted', [
                'name' => $rule->name,
                'is_enabled' => $rule->is_enabled,
            ]);
            $rule->delete();
        });

        return redirect()
            ->route('dashboard.sites.proactive-messages.index', $site)
            ->with('status', 'proactive_messages.flash.deleted');
    }

    /** @return array<string, bool|int|string|null> */
    private function validatedAttributes(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'message' => ['required', 'string', 'max:500'],
            'url_contains' => ['nullable', 'string', 'max:255'],
            'referrer_contains' => ['nullable', 'string', 'max:255'],
            'delay_seconds' => ['required', 'integer', 'min:0', 'max:300'],
            'minimum_visit_count' => ['required', 'integer', 'min:1', 'max:50'],
            'requires_available_agent' => ['nullable', 'boolean'],
            'frequency_cap_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'dismissal_snooze_days' => ['required', 'integer', 'min:1', 'max:90'],
            'position' => ['required', 'integer', 'min:0', 'max:'.self::MAX_POSITION],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        return [
            'name' => trim($validated['name']),
            // Public visitor-facing copy. Stored exactly as written apart from
            // surrounding whitespace, and rendered later as text, never HTML.
            'message' => trim($validated['message']),
            // Matched in the visitor's browser. These values are configuration
            // that can be public; the page and referrer that matched them are
            // not sent back to Wayfindr.
            'url_contains' => $this->trimmedOrNull($validated['url_contains'] ?? null),
            'referrer_contains' => $this->trimmedOrNull($validated['referrer_contains'] ?? null),
            'delay_seconds' => (int) $validated['delay_seconds'],
            'minimum_visit_count' => (int) $validated['minimum_visit_count'],
            'requires_available_agent' => (bool) ($validated['requires_available_agent'] ?? false),
            'frequency_cap_minutes' => (int) $validated['frequency_cap_hours'] * 60,
            'dismissal_snooze_minutes' => (int) $validated['dismissal_snooze_days'] * 24 * 60,
            'position' => (int) $validated['position'],
            'is_enabled' => (bool) ($validated['is_enabled'] ?? false),
        ];
    }

    private function manager(Request $request, Site $site): User
    {
        $agent = $request->user();

        abort_unless(
            $agent instanceof User
            && $agent->account_id !== null
            && $agent->hasAccountPermission(AccountPermission::ManageAutomations),
            403,
        );
        abort_unless(
            (int) $site->account_id === (int) $agent->account_id
            && ! $site->isArchived()
            && $site->supportsAgent($agent),
            404,
        );

        return $agent;
    }

    /** @return array{User, Site} */
    private function lockedManagerAndSite(User $agent, Site $site, int $failureStatus = 404): array
    {
        $accountId = (int) $agent->account_id;
        $this->siteManagerCoverage->lockAccount($accountId);
        $agent = User::query()
            ->with('customRole')
            ->whereKey($agent->id)
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->first();

        abort_unless(
            $agent?->hasAccountPermission(AccountPermission::ManageAutomations),
            $failureStatus,
        );

        $site = Site::query()
            ->whereKey($site->id)
            ->where('account_id', $accountId)
            ->whereNull('archived_at')
            ->lockForUpdate()
            ->first();

        abort_unless($site?->supportsAgent($agent), $failureStatus);

        return [$agent, $site];
    }

    private function authorizeRule(Site $site, ProactiveMessageRule $rule): void
    {
        abort_unless((int) $rule->site_id === (int) $site->id, 404);
    }

    private function lockedRule(Site $site, ProactiveMessageRule $rule): ProactiveMessageRule
    {
        return $site->proactiveMessageRules()
            ->whereKey($rule->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ensureUniqueName(Site $site, string $name, ?int $exceptId = null): void
    {
        $query = $site->proactiveMessageRules()->where('name', $name);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => __('proactive_messages.validation.duplicate'),
            ]);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function audit(
        User $agent,
        Site $site,
        ProactiveMessageRule $rule,
        string $action,
        array $metadata,
    ): void {
        AuditEvent::query()->create([
            'account_id' => $agent->account_id,
            'site_id' => $site->id,
            'actor_type' => $agent->getMorphClass(),
            'actor_id' => $agent->id,
            'subject_type' => $rule->getMorphClass(),
            'subject_id' => $rule->id,
            'action' => $action,
            // Deliberately no message, URL pattern, or referrer pattern. The
            // audit proves the lifecycle without making a second content log.
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
