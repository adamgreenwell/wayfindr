<?php

declare(strict_types=1);

namespace App\Support\Ai\Grounding;

use App\Models\Article;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Ai\AiContextSanitizer;
use App\Support\Ai\ConversationTextContext;
use App\Support\Ai\ConversationTextContextSelector;
use App\Support\Ai\KnowledgeArticleSuggestionMatcher;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use App\Support\Ai\Policy\ResolvedAiAssistPolicy;
use App\Support\LiteralLike;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Throwable;

/** Bounded local selection and exact payload proof for private grounded replies. */
final readonly class ConversationGroundingSelector
{
    private const VERSION = 'conversation-grounding-selection-v2';

    private const PURPOSE = 'conversation_reply_draft';

    private const MAX_PROVIDER_CHARACTERS = 3000;

    private const MAX_CANDIDATES = 50;

    private const MAX_ARTICLE_CHARACTERS = 200000;

    private const MAX_PASSAGE_CHARACTERS = 900;

    private const MAX_TITLE_CHARACTERS = 160;

    private const MAX_TOKENS = 24;

    private const STOP_WORDS = ['the', 'and', 'for', 'that', 'this', 'with', 'from', 'have', 'has', 'are', 'was', 'were',
        'can', 'could', 'would', 'should', 'how', 'what', 'when', 'where', 'which', 'who', 'why', 'please', 'help',
        'you', 'your', 'our', 'its', 'not', 'all', 'any', 'now', 'here', 'there', 'about', 'need', 'want', 'hello', 'thanks', 'thank',
        'support', 'conversation', 'question', 'request',
        'der', 'die', 'das', 'den', 'dem', 'des', 'und', 'ein', 'eine', 'einer', 'einen', 'mit', 'von', 'für',
        'ist', 'sind', 'wie', 'was', 'wann', 'kann', 'bitte', 'ich', 'nicht', 'che', 'con', 'per', 'una', 'uno',
        'del', 'della', 'delle', 'gli', 'dei', 'sono', 'come', 'quando', 'dove', 'posso', 'aiuto',
        'email', 'redacted', 'credential', 'identifier', 'address', 'phone', 'token', 'private', 'key', 'payment', 'card'];

    public function __construct(private ConversationTextContextSelector $transcripts, private AiContextSanitizer $sanitizer) {}

    /** Select only from fresh, permitted conversation text; callers cannot supply an arbitrary prompt/draft. */
    public function select(User $actor, Conversation $conversation, int $maximumCharacters = 3000, ?int $transcriptMaximumCharacters = null): ConversationGroundingSelection
    {
        if ($maximumCharacters < 2) {
            throw new InvalidArgumentException('Grounding source budget must allow an empty JSON list.');
        }
        [$actor, $conversation, $policy] = $this->authorized($actor, $conversation);
        // This gate precedes every article/catalogue query, including legacy policy paths.
        if (! $policy->allows(self::PURPOSE) || ! $policy->articleContextAllowed) {
            return new ConversationGroundingSelection('disabled', [], []);
        }
        $lastMessageId = $conversation->messages()->max('id');
        $transcript = $this->transcripts->select($conversation, $transcriptMaximumCharacters);
        if ($transcript === null || ! is_string($transcript->sourceRevision)
            || ! $this->transcripts->sourceStillCurrent($conversation, $transcript->sourceSnapshot)) {
            return new ConversationGroundingSelection('no_sources', [], []);
        }
        $maximum = min(self::MAX_PROVIDER_CHARACTERS, $maximumCharacters, $policy->maxContextCharacters);
        $snapshot = ['version' => self::VERSION, 'account_id' => (int) $actor->account_id, 'conversation_id' => (int) $conversation->id,
            'purpose' => self::PURPOSE, 'policy_revision' => $policy->revisionFor(self::PURPOSE),
            'transcript_revision' => $transcript->sourceRevision, 'transcript_snapshot' => $transcript->sourceSnapshot,
            'last_message_id' => $lastMessageId === null ? null : (int) $lastMessageId,
            'maximum_characters' => $maximum, 'catalogue_revision' => $this->catalogueRevision((int) $actor->account_id), 'sources' => []];
        $tokens = $this->tokens($transcript);
        if ($tokens === []) {
            return $this->selection([], $snapshot);
        }

        $ranked = [];
        $articles = Article::query()->where('account_id', $actor->account_id)->published()
            ->where(function ($query) use ($tokens): void {
                foreach ($tokens as $token) {
                    LiteralLike::where($query, 'search_text', LiteralLike::pattern($token), 'or');
                }
            })->select(['id', 'account_id', 'title', 'updated_at', 'published_at'])
            ->selectRaw('SUBSTR(body, 1, ?) AS body', [self::MAX_ARTICLE_CHARACTERS + 1])
            ->orderBy('id')->limit(self::MAX_CANDIDATES)->get();
        foreach ($articles as $article) {
            // Reject out-of-contract originals instead of hashing or sending a truncated source as complete.
            if (mb_strlen((string) $article->body) > self::MAX_ARTICLE_CHARACTERS) {
                continue;
            }
            $title = mb_substr($this->sanitizer->sanitize((string) $article->title), 0, self::MAX_TITLE_CHARACTERS);
            // Scrub the entire bounded source BEFORE choosing/truncating a window, including split credentials.
            $text = $this->sanitizer->sanitize($article->text());
            [$passage, $score] = $this->passage($text, $tokens);
            if ($passage === null) {
                continue;
            }
            $ranked[] = ['article' => $article, 'title' => $title, 'passage' => $passage,
                'score' => $score + 3 * $this->score($title, $tokens)];
        }
        usort($ranked, fn ($left, $right): int => ($right['score'] <=> $left['score']) ?: ($left['article']->id <=> $right['article']->id));
        $providerSources = [];
        foreach ($ranked as $candidate) {
            if (count($providerSources) === 3) {
                break;
            }
            $passage = $this->fitPassage($providerSources, $candidate['title'], $candidate['passage'], $maximum);
            if ($passage === '') {
                continue;
            }
            // A budget cut must not turn a matched window into an unrelated introduction.
            if ($this->score($passage, $tokens) === 0) {
                continue;
            }
            $revision = KnowledgeArticleSuggestionMatcher::revision($candidate['article'], '');
            $passageProof = $this->hash(['passage', $passage]);
            $titleProof = $this->hash(['title', $candidate['title']]);
            $reference = app(GroundingSourceReference::class)->forDigest($this->hash(['reference', (int) $actor->account_id,
                (int) $candidate['article']->id, $revision, $passageProof, $titleProof]));
            $providerSources[] = ['ref' => $reference, 'title' => $candidate['title'], 'passage' => $passage];
            $snapshot['sources'][] = ['ref' => $reference, 'article_id' => (int) $candidate['article']->id,
                'full_revision' => $revision, 'passage_hash' => $passageProof, 'title_hash' => $titleProof];
        }

        return $this->selection($providerSources, $snapshot);
    }

    /** Original sources may remain readable after append-only activity; outer preparation identity determines staleness. */
    public function sourceStillCurrent(User $actor, Conversation $conversation, array $snapshot): bool
    {
        try {
            [$actor, $conversation, $policy] = $this->authorized($actor, $conversation);
            // Do not even inspect catalogue/article rows under narrowed policy or revoked task authority.
            if (! $policy->allows(self::PURPOSE) || ! $policy->articleContextAllowed
                || ($snapshot['version'] ?? null) !== self::VERSION || ($snapshot['purpose'] ?? null) !== self::PURPOSE
                || ($snapshot['account_id'] ?? null) !== (int) $actor->account_id
                || ($snapshot['conversation_id'] ?? null) !== (int) $conversation->id
                || ($snapshot['policy_revision'] ?? null) !== $policy->revisionFor(self::PURPOSE)
                || ! is_string($snapshot['proof'] ?? null) || ! is_array($snapshot['transcript_snapshot'] ?? null)
                || ! is_string($snapshot['transcript_revision'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/', $snapshot['transcript_revision']) !== 1
                || ! is_array($snapshot['sources'] ?? null) || ! array_is_list($snapshot['sources']) || count($snapshot['sources']) > 3) {
                return false;
            }
            $proof = $snapshot['proof'];
            unset($snapshot['proof']);
            if (! hash_equals($this->hash(['snapshot', $snapshot]), $proof)
                || ! $this->transcripts->sourceStillCurrent($conversation, $snapshot['transcript_snapshot'])) {
                return false;
            }
            // Transcript authorization/content is checked before catalogue or article queries.
            if (($snapshot['catalogue_revision'] ?? null) !== $this->catalogueRevision((int) $actor->account_id)) {
                return false;
            }
            $seen = [];
            foreach ($snapshot['sources'] as $source) {
                if (! is_array($source) || array_keys($source) !== ['ref', 'article_id', 'full_revision', 'passage_hash', 'title_hash']
                    || ! is_string($source['ref']) || preg_match('/\Asrc-[a-f0-9]{24}\z/', $source['ref']) !== 1
                    || ! is_int($source['article_id']) || $source['article_id'] < 1
                    || ! is_string($source['full_revision']) || preg_match('/\A[a-f0-9]{64}\z/', $source['full_revision']) !== 1
                    || ! is_string($source['passage_hash']) || preg_match('/\A[a-f0-9]{64}\z/', $source['passage_hash']) !== 1
                    || ! is_string($source['title_hash']) || preg_match('/\A[a-f0-9]{64}\z/', $source['title_hash']) !== 1
                    || isset($seen[$source['ref']])) {
                    return false;
                }
                $seen[$source['ref']] = true;
                $article = Article::query()->where('account_id', $actor->account_id)->published()->whereKey($source['article_id'])
                    ->select(['id', 'account_id', 'title', 'updated_at', 'published_at'])
                    ->selectRaw('SUBSTR(body, 1, ?) AS body', [self::MAX_ARTICLE_CHARACTERS + 1])->first();
                if ($article === null || mb_strlen((string) $article->body) > self::MAX_ARTICLE_CHARACTERS
                    || ! hash_equals(KnowledgeArticleSuggestionMatcher::revision($article, ''), $source['full_revision'])) {
                    return false;
                }
                $reference = app(GroundingSourceReference::class)->forDigest($this->hash(['reference', (int) $actor->account_id, $source['article_id'],
                    $source['full_revision'], $source['passage_hash'], $source['title_hash']]));
                if (! hash_equals($reference, $source['ref'])) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Bind exactly the scrubbed fields supplied to the processor, not just article membership. */
    public function payloadStillCurrent(User $actor, Conversation $conversation, array $snapshot, array $sources): bool
    {
        try {
            if (! $this->sourceStillCurrent($actor, $conversation, $snapshot)
                || ! array_is_list($sources) || count($sources) !== count($snapshot['sources'])
                || mb_strlen(json_encode($sources, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) > $snapshot['maximum_characters']) {
                return false;
            }
            foreach ($sources as $index => $source) {
                $proof = $snapshot['sources'][$index];
                if (! is_array($source) || array_keys($source) !== ['ref', 'title', 'passage']
                    || ! is_string($source['title']) || ! is_string($source['passage'])
                    || $source['ref'] !== $proof['ref']
                    || ! hash_equals($proof['passage_hash'], $this->hash(['passage', $source['passage']]))
                    || ! hash_equals($proof['title_hash'], $this->hash(['title', $source['title']]))) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{User, Conversation, ResolvedAiAssistPolicy} */
    private function authorized(User $actor, Conversation $conversation): array
    {
        $actor = User::query()->find($actor->id);
        if ($actor === null || ! $actor->account_id || $actor->isDeactivated()) {
            throw new AuthorizationException;
        }
        $conversation = Conversation::query()->with('site')->whereKey($conversation->id)
            ->whereHas('site', fn ($query) => $query->where('account_id', $actor->account_id))->first();
        if ($conversation === null || ! Gate::forUser($actor)->allows('reply', $conversation)) {
            throw new AuthorizationException;
        }

        return [$actor, $conversation, app(AiAssistPolicyResolver::class)->forConversation($conversation)];
    }

    /** @return list<string> */
    private function tokens(ConversationTextContext $transcript): array
    {
        $data = json_decode($transcript->input, true, 8, JSON_THROW_ON_ERROR);
        $parts = [];
        foreach (array_reverse(array_slice($data['messages'] ?? [], -3)) as $message) {
            if (is_string($message['body'] ?? null)) {
                $parts[] = $message['body'];
            }
        }
        if (is_string($data['subject'] ?? null)) {
            $parts[] = $data['subject'];
        }
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(implode(' ', $parts)), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];
        foreach ($words as $word) {
            if (mb_strlen($word) >= 3 && mb_strlen($word) <= 64 && ! in_array($word, self::STOP_WORDS, true) && ! isset($tokens[$word])) {
                $tokens[$word] = true;
                if (count($tokens) === self::MAX_TOKENS) {
                    break;
                }
            }
        }

        return array_keys($tokens);
    }

    /** @param list<string> $tokens
     * @return array{?string, int}
     */
    private function passage(string $text, array $tokens): array
    {
        $best = null;
        $bestScore = 0;
        $window = [];
        foreach (preg_split('/(?<=[.!?])\s+|\R+/u', $text) ?: [] as $sentence) {
            $sentence = trim((string) preg_replace('/\s+/u', ' ', $sentence));
            if ($sentence === '' || mb_strlen($sentence) > self::MAX_PASSAGE_CHARACTERS) {
                $window = [];

                continue;
            }
            $window[] = $sentence;
            while (mb_strlen(implode(' ', $window)) > self::MAX_PASSAGE_CHARACTERS) {
                array_shift($window);
            }
            $candidate = implode(' ', $window);
            $score = $this->score($candidate, $tokens);
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return [$best, $bestScore];
    }

    /** @param list<string> $tokens */
    private function score(string $text, array $tokens): int
    {
        $text = mb_strtolower($text);
        $score = 0;
        foreach ($tokens as $token) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($token, '/').'(?![\p{L}\p{N}])/u', $text) === 1) {
                $score++;
            }
        }

        return $score;
    }

    /** @param list<array{ref: string, title: string, passage: string}> $selected */
    private function fitPassage(array $selected, string $title, string $passage, int $maximum): string
    {
        $low = 0;
        $high = mb_strlen($passage);
        while ($low < $high) {
            $length = intdiv($low + $high + 1, 2);
            $candidate = [...$selected, ['ref' => 'src-'.str_repeat('0', 24), 'title' => $title, 'passage' => mb_substr($passage, 0, $length)]];
            if (mb_strlen(json_encode($candidate, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) <= $maximum) {
                $low = $length;
            } else {
                $high = $length - 1;
            }
        }

        if ($low >= mb_strlen($passage)) {
            return $passage;
        }
        $fitted = '';
        foreach (preg_split('/(?<=[.!?])\s+|\R+/u', $passage) ?: [] as $sentence) {
            $candidate = ltrim($fitted.' '.$sentence);
            if (mb_strlen($candidate) > $low) {
                break;
            }
            $fitted = $candidate;
        }

        return $fitted;
    }

    /** @param list<array{ref: string, title: string, passage: string}> $sources */
    private function selection(array $sources, array $snapshot): ConversationGroundingSelection
    {
        $snapshot['proof'] = $this->hash(['snapshot', $snapshot]);

        return new ConversationGroundingSelection($sources === [] ? 'no_sources' : 'selected', $sources, $snapshot);
    }

    /** Same conservative account catalogue identity, including scheduled publication becoming visible. */
    private function catalogueRevision(int $accountId): string
    {
        $revision = DB::table('accounts')->where('id', $accountId)->value('ai_article_catalogue_revision');
        $published = DB::table('articles')->where('account_id', $accountId)->whereNotNull('published_at')->where('published_at', '<=', now())
            ->orderByDesc('published_at')->value('published_at');

        return $this->hash(['catalogue', $accountId, $revision, $published]);
    }

    private function hash(array $value): string
    {
        return hash_hmac('sha256', json_encode([self::VERSION, $value], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (string) config('app.key'));
    }
}
