<?php

namespace App\Http\Controllers;

use App\Enums\AccountRole;
use App\Mail\AgentWelcomeMessage;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class AgentAccountAgentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();

        Gate::forUser($actor)->authorize('createAccountAgent', User::class);

        if (is_string($request->input('email'))) {
            $request->merge([
                'email' => Str::lower(trim($request->input('email'))),
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'send_welcome_email' => ['sometimes', 'boolean'],
        ]);

        $password = Str::password(24);
        $welcomeEmailRequested = $request->boolean('send_welcome_email');
        $welcomeEmailSent = false;

        [$actor, $agent] = DB::transaction(function () use ($actor, $validated, $password): array {
            $accountId = (int) $actor->account_id;
            Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
            $actor = User::query()
                ->whereKey($actor->id)
                ->where('account_id', $accountId)
                ->lockForUpdate()
                ->firstOrFail();

            // Role edits and assignments take the same account-first lock.
            // Reauthorize after it so a request already in flight cannot copy
            // a role the creator lost while waiting.
            Gate::forUser($actor)->authorize('createAccountAgent', User::class);

            $agent = User::query()->create([
                'account_id' => $actor->account_id,
                'account_role' => AccountRole::Agent,
                // A custom-role manager can grow their team without minting a
                // login that outranks them. Built-in owners/admins keep the legacy
                // Agent default; custom-role issuers reproduce their own boundary.
                'custom_role_id' => $actor->custom_role_id,
                'name' => trim($validated['name']),
                'email' => $validated['email'],
                'password' => Hash::make($password),
            ]);

            return [$actor, $agent];
        });

        if ($welcomeEmailRequested) {
            try {
                Mail::to($agent->email)->send(new AgentWelcomeMessage(
                    accountName: (string) $actor->account->name,
                    agentName: $agent->name,
                    agentEmail: $agent->email,
                    temporaryPassword: $password,
                    loginUrl: url('/login'),
                ));

                $welcomeEmailSent = true;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        AuditEvent::query()->create([
            'account_id' => $actor->account_id,
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->id,
            'subject_type' => $agent->getMorphClass(),
            'subject_id' => $agent->id,
            'action' => 'agent.created',
            'metadata' => [
                'role' => AccountRole::Agent->value,
                'custom_role_id' => $agent->custom_role_id,
                'custom_role_name' => $agent->customRole?->name,
                'welcome_email_requested' => $welcomeEmailRequested,
                'welcome_email_sent' => $welcomeEmailSent,
            ],
            'occurred_at' => now(),
        ]);

        $status = match (true) {
            $welcomeEmailSent => 'account.flash.created_and_welcome_sent',
            $welcomeEmailRequested => 'account.flash.created_welcome_failed',
            default => 'account.flash.created',
        };

        return redirect()
            ->route('dashboard.account.show')
            ->with('status', $status)
            ->with('created_agent_email', $agent->email)
            ->with('created_agent_password', $password);
    }
}
