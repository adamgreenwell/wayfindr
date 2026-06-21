<?php

namespace App\Http\Controllers;

use App\Models\SiteExternalIssueProject;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ExternalIssues\GitHubIssueCreationFailed;
use App\Support\ExternalIssues\GitHubIssueCreator;
use App\Support\ExternalIssues\GitLabIssueCreationFailed;
use App\Support\ExternalIssues\GitLabIssueCreator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class AgentTicketExternalIssueController extends Controller
{
    public function storeGithub(Request $request, Ticket $ticket, GitHubIssueCreator $githubIssueCreator): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketUpdate($agent, $ticket);

        $validated = $request->validate([
            'site_external_issue_project_id' => ['required', 'integer', 'exists:site_external_issue_projects,id'],
        ]);

        $ticket->loadMissing(['conversation', 'site']);
        $project = $this->providerProjectForTicket($ticket, (int) $validated['site_external_issue_project_id'], 'github');

        if (! $project->hasCapability('create_issue')) {
            return $this->externalIssueError($ticket, 'This GitHub connection cannot create issues.');
        }

        try {
            $createdIssue = $githubIssueCreator->create($project, $ticket);
        } catch (GitHubIssueCreationFailed $exception) {
            $this->recordActivity($ticket, $agent, 'ticket.external_sync_failed', [
                'provider' => 'github',
                'project_key' => $project->project_key,
                'site_external_issue_project_id' => $project->id,
                'status' => $exception->status(),
                'message' => Str::limit($exception->getMessage(), 300),
            ]);

            return $this->externalIssueError($ticket, 'GitHub issue could not be created.');
        }

        $externalLink = $ticket->externalLinks()->create([
            'account_id' => $ticket->account_id,
            'site_id' => $ticket->site_id,
            'provider' => 'github',
            'project_key' => $project->project_key,
            'external_id' => $createdIssue['id'],
            'external_key' => $createdIssue['number'] ? '#'.$createdIssue['number'] : null,
            'url' => $createdIssue['url'],
            'sync_status' => 'linked',
            'metadata' => [
                'site_external_issue_project_id' => $project->id,
                'external_issue_provider_connection_id' => $project->external_issue_provider_connection_id,
                'created_via' => 'github_adapter',
            ],
        ]);

        $this->recordActivity($ticket, $agent, 'ticket.external_issue_created', [
            'external_link_id' => $externalLink->id,
            'provider' => $externalLink->provider,
            'project_key' => $externalLink->project_key,
            'external_id' => $externalLink->external_id,
            'external_key' => $externalLink->external_key,
            'url' => $externalLink->url,
            'sync_status' => $externalLink->sync_status,
            'site_external_issue_project_id' => $project->id,
        ]);

        return redirect()
            ->back(302, [], route('dashboard'))
            ->with('status', 'GitHub issue created.');
    }

    public function storeGitlab(Request $request, Ticket $ticket, GitLabIssueCreator $gitlabIssueCreator): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeTicketUpdate($agent, $ticket);

        $validated = $request->validate([
            'site_external_issue_project_id' => ['required', 'integer', 'exists:site_external_issue_projects,id'],
        ]);

        $ticket->loadMissing(['conversation', 'site']);
        $project = $this->providerProjectForTicket($ticket, (int) $validated['site_external_issue_project_id'], 'gitlab');

        if (! $project->hasCapability('create_issue')) {
            return $this->externalIssueError($ticket, 'This GitLab connection cannot create issues.');
        }

        try {
            $createdIssue = $gitlabIssueCreator->create($project, $ticket);
        } catch (GitLabIssueCreationFailed $exception) {
            $this->recordActivity($ticket, $agent, 'ticket.external_sync_failed', [
                'provider' => 'gitlab',
                'project_key' => $project->project_key,
                'site_external_issue_project_id' => $project->id,
                'status' => $exception->status(),
                'message' => Str::limit($exception->getMessage(), 300),
            ]);

            return $this->externalIssueError($ticket, 'GitLab issue could not be created.');
        }

        $externalLink = $ticket->externalLinks()->create([
            'account_id' => $ticket->account_id,
            'site_id' => $ticket->site_id,
            'provider' => 'gitlab',
            'project_key' => $project->project_key,
            'external_id' => $createdIssue['id'],
            'external_key' => $createdIssue['iid'] ? '#'.$createdIssue['iid'] : null,
            'url' => $createdIssue['url'],
            'sync_status' => 'linked',
            'metadata' => [
                'site_external_issue_project_id' => $project->id,
                'external_issue_provider_connection_id' => $project->external_issue_provider_connection_id,
                'created_via' => 'gitlab_adapter',
                'gitlab_issue_iid' => $createdIssue['iid'],
            ],
        ]);

        $this->recordActivity($ticket, $agent, 'ticket.external_issue_created', [
            'external_link_id' => $externalLink->id,
            'provider' => $externalLink->provider,
            'project_key' => $externalLink->project_key,
            'external_id' => $externalLink->external_id,
            'external_key' => $externalLink->external_key,
            'url' => $externalLink->url,
            'sync_status' => $externalLink->sync_status,
            'site_external_issue_project_id' => $project->id,
        ]);

        return redirect()
            ->back(302, [], route('dashboard'))
            ->with('status', 'GitLab issue created.');
    }

    private function providerProjectForTicket(Ticket $ticket, int $projectId, string $provider): SiteExternalIssueProject
    {
        return SiteExternalIssueProject::query()
            ->with('providerConnection')
            ->whereKey($projectId)
            ->where('account_id', $ticket->account_id)
            ->where('site_id', $ticket->site_id)
            ->whereHas('providerConnection', fn ($query) => $query
                ->where('provider', $provider)
                ->where('is_enabled', true))
            ->firstOrFail();
    }

    private function externalIssueError(Ticket $ticket, string $message): RedirectResponse
    {
        return redirect()
            ->back(302, [], route('dashboard'))
            ->withErrors(['external_issue' => $message]);
    }

    private function authorizeTicketUpdate(User $agent, Ticket $ticket): void
    {
        abort_unless(Gate::forUser($agent)->allows('update', $ticket), 404);
    }

    private function recordActivity(Ticket $ticket, User $agent, string $action, array $metadata = []): void
    {
        $ticket->auditEvents()->create([
            'account_id' => $ticket->account_id,
            'site_id' => $ticket->site_id,
            'actor_type' => User::class,
            'actor_id' => $agent->id,
            'action' => $action,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
