<?php

namespace App\Http\Controllers;

use App\Support\Ai\Observability\AssistReport;
use Illuminate\Http\Request;

final class AgentAssistReportController extends Controller
{
    public function show(Request $request, AssistReport $reports)
    {
        $input = $request->validate(['days' => ['sometimes', 'integer', 'min:1', 'max:31'], 'task' => ['sometimes', 'in:all,summary,reply,knowledge,ticket,refinement']]);
        $report = $reports->read($request->user(), (int) ($input['days'] ?? 7), $input['task'] ?? 'all');

        return $request->expectsJson() ? response()->json($report)->header('Cache-Control', 'no-store, private') : response()->view('agent.account.assist-report', ['report' => $report])->header('Cache-Control', 'no-store, private');
    }
}
