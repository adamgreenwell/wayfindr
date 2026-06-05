<?php

namespace App\Http\Controllers;

use App\Enums\AccountRole;
use App\Mail\AgentWelcomeMessage;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $agent = User::query()->create([
            'account_id' => $actor->account_id,
            'account_role' => AccountRole::Agent,
            'name' => trim($validated['name']),
            'email' => $validated['email'],
            'password' => Hash::make($password),
        ]);

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
                'welcome_email_requested' => $welcomeEmailRequested,
                'welcome_email_sent' => $welcomeEmailSent,
            ],
            'occurred_at' => now(),
        ]);

        $status = match (true) {
            $welcomeEmailSent => 'Agent created and welcome email sent.',
            $welcomeEmailRequested => 'Agent created, but the welcome email could not be sent. Share the temporary password securely.',
            default => 'Agent created. Share the temporary password securely.',
        };

        return redirect()
            ->route('dashboard.account.show')
            ->with('status', $status)
            ->with('created_agent_email', $agent->email)
            ->with('created_agent_password', $password);
    }
}
