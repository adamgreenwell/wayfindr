<?php

namespace App\Http\Controllers;

use App\Models\AutomationMacro;
use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Automation\AutomationMacroAuthorization;
use App\Support\Automation\AutomationMacroRunFailed;
use App\Support\Automation\AutomationMacroRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final readonly class AgentAutomationMacroRunController
{
    public function __construct(
        private AutomationMacroAuthorization $authorization,
        private AutomationMacroRunner $runner,
    ) {}

    public function ticket(Request $request, Ticket $ticket, AutomationMacro $automationMacro): RedirectResponse
    {
        $agent = $request->user();
        $ticket->loadMissing('site');
        $this->authorizeInitialRequest($agent, $automationMacro, $ticket);

        return $this->run(
            $agent,
            $automationMacro,
            $ticket,
            route('dashboard.tickets.show', $ticket),
        );
    }

    public function conversation(
        Request $request,
        string $supportCode,
        AutomationMacro $automationMacro,
    ): RedirectResponse {
        $agent = $request->user();
        $conversation = Conversation::query()
            ->with('site')
            ->where('support_code', $supportCode)
            ->firstOrFail();
        $this->authorizeInitialRequest($agent, $automationMacro, $conversation);

        return $this->run(
            $agent,
            $automationMacro,
            $conversation,
            route('dashboard.conversations.show', $conversation->support_code),
        );
    }

    private function authorizeInitialRequest(
        mixed $agent,
        AutomationMacro $macro,
        Ticket|Conversation $subject,
    ): void {
        abort_unless(
            $agent instanceof User && $this->authorization->allows($agent, $macro, $subject),
            404,
        );
    }

    private function run(
        User $agent,
        AutomationMacro $macro,
        Ticket|Conversation $subject,
        string $fallback,
    ): RedirectResponse {
        try {
            $this->runner->run($agent, $macro, $subject);
        } catch (AutomationMacroRunFailed) {
            return redirect()
                ->back(302, [], $fallback)
                ->with('status', 'automation_macros.flash.failed');
        }

        return redirect()
            ->back(302, [], $fallback)
            ->with('status', 'automation_macros.flash.applied');
    }
}
