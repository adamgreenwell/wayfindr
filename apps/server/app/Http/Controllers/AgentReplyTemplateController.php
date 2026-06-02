<?php

namespace App\Http\Controllers;

use App\Models\ReplyTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AgentReplyTemplateController extends Controller
{
    public function index(Request $request): View
    {
        $agent = $request->user();

        abort_unless($agent?->isAdmin(), 403);

        $account = $agent->account()->firstOrFail();

        return view('agent.reply-templates.index', [
            'account' => $account,
            'agent' => $agent,
            'replyTemplates' => $account->replyTemplates()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $agent = $request->user();

        abort_unless($agent?->isAdmin(), 403);

        $account = $agent->account()->firstOrFail();

        $account->replyTemplates()->create($this->validatedTemplateInput($request));

        return redirect()
            ->route('dashboard.account.reply-templates.index')
            ->with('status', 'Reply template created.');
    }

    public function update(Request $request, ReplyTemplate $replyTemplate): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeManageReplyTemplate($agent, $replyTemplate);

        $replyTemplate->forceFill($this->validatedTemplateInput($request))->save();

        return redirect()
            ->route('dashboard.account.reply-templates.index')
            ->with('status', 'Reply template updated.');
    }

    public function archive(Request $request, ReplyTemplate $replyTemplate): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeManageReplyTemplate($agent, $replyTemplate);

        $replyTemplate->forceFill([
            'is_active' => false,
        ])->save();

        return redirect()
            ->route('dashboard.account.reply-templates.index')
            ->with('status', 'Reply template archived.');
    }

    private function authorizeManageReplyTemplate(mixed $agent, ReplyTemplate $replyTemplate): void
    {
        abort_unless(
            $agent?->isAdmin()
            && $agent->account_id !== null
            && (int) $agent->account_id === (int) $replyTemplate->account_id,
            404,
        );
    }

    /**
     * @return array{name: string, body: string}
     */
    private function validatedTemplateInput(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $templateInput = [
            'name' => trim((string) $validated['name']),
            'body' => trim((string) $validated['body']),
        ];

        if ($templateInput['name'] === '') {
            throw ValidationException::withMessages([
                'name' => 'Please name this reply template.',
            ]);
        }

        if ($templateInput['body'] === '') {
            throw ValidationException::withMessages([
                'body' => 'Please add a reply body.',
            ]);
        }

        return $templateInput;
    }
}
