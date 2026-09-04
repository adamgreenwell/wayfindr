<?php

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Models\Article;
use App\Models\User;
use App\Support\Knowledge\ArticleDocument;
use App\Support\LiteralLike;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Authoring the answers a visitor can find without asking.
 *
 * Restricted to manage-knowledge permission, matching reply templates: both are account-wide copy that every
 * agent then speaks with, and one agent editing what the desk says to everybody
 * is a different act from answering one conversation.
 */
class AgentArticleController extends Controller
{
    public function __construct(private readonly SiteManagerCoverage $siteManagerCoverage) {}

    public function index(Request $request): View
    {
        $agent = $request->user();

        abort_unless($agent?->hasAccountPermission(AccountPermission::ManageKnowledge), 403);

        $account = $agent->account()->firstOrFail();

        $search = $request->query('article_search', '');
        $search = is_string($search) ? mb_substr(trim($search), 0, 120) : '';

        $articles = $account->articles()
            ->when($search !== '', fn ($query) => LiteralLike::where($query, 'title', LiteralLike::pattern($search)))
            // Drafts first: the list exists to be worked in, and the thing that
            // needs attention is the thing that is not finished.
            //
            // Spelled out rather than left to `orderBy('published_at')`, because
            // where NULLs sort is a driver difference: SQLite treats NULL as the
            // smallest value, PostgreSQL sorts it last on ASC. The suite runs on
            // SQLite and every documented install runs PostgreSQL, so relying on
            // the default is the exact trap docs/development/testing.md names.
            ->orderByRaw('CASE WHEN published_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('updated_at')
            ->get();

        return view('agent.articles.index', [
            'account' => $account,
            'agent' => $agent,
            'articles' => $articles,
            'articleSearch' => $search,
        ]);
    }

    public function show(Request $request, Article $article): View
    {
        $agent = $request->user();

        $this->authorizeManageArticle($agent, $article);

        return view('agent.articles.show', [
            'account' => $agent->account()->firstOrFail(),
            'agent' => $agent,
            'article' => $article,
            // Rendered from the same method the widget will use, so the preview
            // is the article rather than an impression of it.
            'blocks' => $article->blocks(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $agent = $request->user();

        abort_unless($agent?->hasAccountPermission(AccountPermission::ManageKnowledge), 403);

        $account = $agent->account()->firstOrFail();
        $input = $this->validatedArticleInput($request);

        $article = DB::transaction(function () use ($agent, $account, $input): Article {
            $this->lockedKnowledgeManager($agent, (int) $account->id, 403);

            return $account->articles()->create([
                ...$input,
                'slug' => $this->uniqueSlug($account->id, $input['title']),
            ]);
        });

        return redirect()
            ->route('dashboard.account.articles.show', $article)
            ->with('status', 'articles.flash.created');
    }

    public function update(Request $request, Article $article): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeManageArticle($agent, $article);

        $input = $this->validatedArticleInput($request);

        $article = DB::transaction(function () use ($agent, $article, $input): Article {
            $lockedAgent = $this->lockedKnowledgeManager($agent, (int) $article->account_id);
            $article = $this->lockedArticle($article);
            $this->authorizeManageArticle($lockedAgent, $article);

            // The slug is the article's stable name. Renaming the title of a
            // published article must not break a link an agent already sent to
            // a visitor, so the slug is settled once, at creation.
            $article->forceFill($input)->save();

            return $article;
        });

        return redirect()
            ->route('dashboard.account.articles.show', $article)
            ->with('status', 'articles.flash.saved');
    }

    /**
     * Publish it, or take it back.
     *
     * Its own action rather than a field on the form: publishing is what makes
     * an article visible to strangers, and it should not be something that
     * happens because somebody was fixing a typo.
     */
    public function publish(Request $request, Article $article): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeManageArticle($agent, $article);

        $publishing = DB::transaction(function () use ($agent, $article): bool {
            $lockedAgent = $this->lockedKnowledgeManager($agent, (int) $article->account_id);
            $article = $this->lockedArticle($article);
            $this->authorizeManageArticle($lockedAgent, $article);
            $publishing = ! $article->isPublished();

            $article->forceFill(['published_at' => $publishing ? now() : null])->save();

            return $publishing;
        });

        return redirect()
            ->route('dashboard.account.articles.show', $article)
            ->with('status', $publishing
                ? 'articles.flash.published'
                : 'articles.flash.unpublished');
    }

    public function destroy(Request $request, Article $article): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeManageArticle($agent, $article);

        DB::transaction(function () use ($agent, $article): void {
            $lockedAgent = $this->lockedKnowledgeManager($agent, (int) $article->account_id);
            $article = $this->lockedArticle($article);
            $this->authorizeManageArticle($lockedAgent, $article);
            $article->delete();
        });

        return redirect()
            ->route('dashboard.account.articles.index')
            ->with('status', 'articles.flash.deleted');
    }

    private function authorizeManageArticle(mixed $agent, Article $article): void
    {
        abort_unless(
            $agent?->hasAccountPermission(AccountPermission::ManageKnowledge)
            && $agent->account_id !== null
            && (int) $agent->account_id === (int) $article->account_id,
            404,
        );
    }

    private function lockedKnowledgeManager(User $agent, int $accountId, int $failureStatus = 404): User
    {
        $this->siteManagerCoverage->lockAccount($accountId);
        $lockedAgent = User::query()
            ->whereKey($agent->id)
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->first();

        abort_unless(
            $lockedAgent?->hasAccountPermission(AccountPermission::ManageKnowledge),
            $failureStatus,
        );

        return $lockedAgent;
    }

    private function lockedArticle(Article $article): Article
    {
        return Article::query()
            ->whereKey($article->id)
            ->where('account_id', $article->account_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @return array{title: string, body: string}
     */
    private function validatedArticleInput(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        $input = [
            'title' => trim((string) $validated['title']),
            'body' => trim((string) $validated['body']),
        ];

        if ($input['title'] === '') {
            throw ValidationException::withMessages(['title' => __('articles.validation.title')]);
        }

        // A body of nothing but syntax produces no blocks, so a visitor would
        // open an article and find an empty panel. Checked against what the
        // reader gets rather than against what was typed.
        if (ArticleDocument::text($input['body']) === '') {
            throw ValidationException::withMessages(['body' => __('articles.validation.body')]);
        }

        return $input;
    }

    private function uniqueSlug(int $accountId, string $title): string
    {
        $base = Str::slug($title) ?: 'article';
        $slug = $base;
        $suffix = 2;

        while (Article::query()->where('account_id', $accountId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
