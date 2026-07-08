<?php

namespace App\Http\Controllers;

use App\Support\OperatorReadiness;
use App\Support\RealtimeHealth;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AgentReadinessController extends Controller
{
    public function __invoke(Request $request, OperatorReadiness $readiness, RealtimeHealth $realtimeHealth): View
    {
        $agent = $request->user();

        abort_unless($agent?->account_id, 403);
        abort_unless($agent->isAdmin(), 403);

        return view('agent.readiness.show', [
            'account' => $agent->account()->firstOrFail(),
            'agent' => $agent,
            'readiness' => $readiness->summary(),
            'realtimeHealth' => $realtimeHealth->summary(),
        ]);
    }
}
