<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Support\LiteralLike;
use App\Support\WidgetSiteResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a visitor can find for themselves.
 *
 * Unauthenticated, like the rest of the widget surface, and scoped by the site
 * key the page was installed with -- so an account's articles reach that
 * account's visitors and nobody else's. WidgetSiteResolver decides what a key
 * may still serve, which is also what stops an archived site answering (#720).
 *
 * Published only, and `published()` means published ALREADY: a future date is a
 * schedule, and a visitor is not an audience for a draft.
 */
class ArticleController extends Controller
{
    /**
     * The search is the whole deflection surface. A portal nobody is pointed at
     * deflects nothing; this is where the visitor already is.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $site = WidgetSiteResolver::resolveOrFail($validated['site_public_key']);
        $search = trim((string) ($validated['q'] ?? ''));

        $articles = Article::query()
            ->where('account_id', $site->account_id)
            ->published()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $pattern = LiteralLike::pattern($search);

                $query->where(function (Builder $query) use ($pattern): void {
                    LiteralLike::where($query, 'title', $pattern);
                    // The rendered text, not the Markdown source. "within 14
                    // days" is written "within **14 days**", and a visitor who
                    // searches the phrase they can see on the page should find
                    // it. Derived once on save rather than per query.
                    LiteralLike::where($query, 'search_text', $pattern, 'or');
                });
            })
            ->orderByDesc('published_at')
            ->limit(8)
            ->get();

        return response()->json([
            'data' => [
                'articles' => $articles->map(fn (Article $article): array => [
                    'slug' => $article->slug,
                    'title' => $article->title,
                ])->all(),
            ],
        ]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
        ]);

        $site = WidgetSiteResolver::resolveOrFail($validated['site_public_key']);

        $article = Article::query()
            ->where('account_id', $site->account_id)
            ->where('slug', $slug)
            ->published()
            ->first();

        abort_unless($article !== null, 404, 'Article not found.');

        return response()->json([
            'data' => [
                'article' => [
                    'slug' => $article->slug,
                    'title' => $article->title,
                    // Blocks, never markup. The widget builds elements from
                    // these; a type it does not know renders as nothing.
                    'blocks' => $article->blocks(),
                ],
            ],
        ]);
    }
}
