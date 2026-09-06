<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\Account;
use App\Models\Article;
use Illuminate\Support\Collection;

/** Rank published account articles locally and derive safe plain-text excerpts. */
final class KnowledgeArticleSuggestionMatcher
{
    private const MAX_SUGGESTIONS = 3;

    private const ARTICLE_CHUNK_SIZE = 50;

    private const MAX_SNIPPET_CHARACTERS = 360;

    /** @param list<string> $queries
     * @return list<int>
     */
    public function match(Account $account, array $queries): array
    {
        $queries = $this->normalizedQueries($queries);

        if ($queries === []) {
            return [];
        }

        $ranked = [];

        Article::query()
            ->where('account_id', $account->id)
            ->published()
            ->select(['id', 'title', 'search_text'])
            ->lazyById(self::ARTICLE_CHUNK_SIZE)
            ->each(function (Article $article) use (&$ranked, $queries): void {
                $score = $this->score($article, $queries);

                if ($score > 0) {
                    $ranked[] = ['id' => (int) $article->id, 'score' => $score];
                }
            });

        usort($ranked, static fn (array $left, array $right): int => [$right['score'], $right['id']] <=> [$left['score'], $left['id']]);

        return array_column(array_slice($ranked, 0, self::MAX_SUGGESTIONS), 'id');
    }

    /** @param list<int> $articleIds
     * @return Collection<int, array{article: Article, snippet: string}>
     */
    public function present(Account $account, array $articleIds): Collection
    {
        $ids = collect($articleIds)->map(fn (int|string $id): int => (int) $id)->unique()->take(self::MAX_SUGGESTIONS)->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $articles = Article::query()
            ->where('account_id', $account->id)
            ->published()
            ->whereKey($ids->all())
            ->get()
            ->keyBy('id');

        return $ids
            ->map(function (int $id) use ($articles): ?array {
                $article = $articles->get($id);

                return $article instanceof Article
                    ? ['article' => $article, 'snippet' => $this->snippet($article)]
                    : null;
            })
            ->filter()
            ->values();
    }

    /** @param list<string> $queries */
    private function score(Article $article, array $queries): int
    {
        $title = $this->normalize((string) $article->title);
        $text = $this->normalize((string) $article->search_text);
        $score = 0;

        foreach ($queries as $query) {
            if (str_contains($title, $query)) {
                $score += 80;
            }

            if (str_contains($text, $query)) {
                $score += 50;
            }

            foreach ($this->tokens($query) as $token) {
                if ($this->containsToken($title, $token)) {
                    $score += 12;
                }

                if ($this->containsToken($text, $token)) {
                    $score += 4;
                }
            }
        }

        return $score;
    }

    private function snippet(Article $article): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $article->text()));

        if (mb_strlen($text) <= self::MAX_SNIPPET_CHARACTERS) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::MAX_SNIPPET_CHARACTERS - 1)).'…';
    }

    /** @param list<string> $queries
     * @return list<string>
     */
    private function normalizedQueries(array $queries): array
    {
        return collect($queries)
            ->filter(fn (mixed $query): bool => is_string($query))
            ->map(fn (string $query): string => $this->normalize($query))
            ->filter(fn (string $query): bool => mb_strlen($query) >= 3)
            ->unique()
            ->take(5)
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function tokens(string $query): array
    {
        return collect(explode(' ', $query))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3)
            ->unique()
            ->values()
            ->all();
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value)));
    }

    private function containsToken(string $haystack, string $token): bool
    {
        return preg_match('/(?:^| )'.preg_quote($token, '/').'(?: |$)/u', $haystack) === 1;
    }
}
