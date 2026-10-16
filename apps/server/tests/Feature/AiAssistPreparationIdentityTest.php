<?php

declare(strict_types=1);

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AccountAiAssistPolicy;
use App\Models\AccountAiConnection;
use App\Models\Article;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\SiteAiAssistPolicy;
use App\Models\Ticket;
use App\Models\TicketLabel;
use App\Models\User;
use App\Support\Ai\AccountAiConnectionProbe;
use App\Support\Ai\AgentCopilotPrompt;
use App\Support\Ai\ConversationSummaryContext;
use App\Support\Ai\ConversationSummaryPromptBuilder;
use App\Support\Ai\ConversationTicketSuggestionPromptBuilder;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Preparation\AiAssistPreparationIdentity;
use App\Support\Ai\Preparation\AiAssistPreparationIdentityResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function preparationIdentityWorld(): array
{
    Http::preventStrayRequests();
    Queue::fake();
    config(['wayfindr.ai.max_context_characters' => 30000]);
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create(['subject' => 'Synthetic checkout help']);
    $message = ConversationMessage::factory()->for($conversation)->create(['body' => 'Synthetic checkout request.']);
    $article = Article::factory()->for($account)->published()->create(['title' => 'Checkout help', 'body' => 'CATALOGUE_ONLY_MARKER']);
    $label = TicketLabel::factory()->for($account)->create(['name' => 'Checkout', 'slug' => 'checkout']);
    $connection = AccountAiConnection::query()->forceCreate([
        'account_id' => $account->id, 'enabled' => true, 'uses_installation_default' => false,
        'active_revision' => (string) Str::uuid(),
        'active_configuration' => ['source' => 'account', 'provider' => 'openai-compatible', 'model' => 'synthetic-A',
            'endpoint' => 'https://models.example.test/v1', 'api_key' => 'synthetic-not-shared-secret',
            'processing_policy_revision' => app(AccountAiConnectionProbe::class)->policyRevision()],
    ]);
    $values = ['mode' => 'prepare', 'summary' => true, 'reply_draft' => true, 'relevant_articles' => true,
        'ticket_details' => true, 'article_context_consent' => true, 'max_context_characters' => null];
    $accountRevision = (string) Str::uuid();
    $siteRevision = (string) Str::uuid();
    $policy = AccountAiAssistPolicy::query()->forceCreate(['account_id' => $account->id, 'revision' => $accountRevision, 'preparation_consent_revision' => $accountRevision, ...$values]);
    SiteAiAssistPolicy::query()->forceCreate(['site_id' => $site->id, 'account_id' => $account->id, 'revision' => $siteRevision, 'preparation_consent_revision' => $siteRevision, ...$values]);

    return compact('account', 'owner', 'agent', 'site', 'conversation', 'message', 'article', 'label', 'connection', 'policy');
}

function preparationIdentity(array $world, string $purpose = 'conversation_summary', ?User $actor = null): AiAssistPreparationIdentity
{
    return app(AiAssistPreparationIdentityResolver::class)->forTask($actor ?? $world['owner'], $world['conversation'], $purpose);
}

test('equivalent viewers share the same identity without exposing input or credentials', function (): void {
    $world = preparationIdentityWorld();
    $first = preparationIdentity($world);
    $other = preparationIdentity($world, actor: $world['agent']);
    expect($first->matches($other))->toBeTrue('Equivalent authorized viewers must share preparation identity; requester ID belongs to audit, not its key.')
        ->and($first->fingerprint)->toMatch('/\A[a-f0-9]{64}\z/')
        ->and(json_encode($first, JSON_THROW_ON_ERROR))->not->toContain('Synthetic checkout request', 'synthetic-not-shared-secret', 'CATALOGUE_ONLY_MARKER');
    $context = app(ConversationSummaryPromptBuilder::class)->build($world['conversation']);
    expect(array_keys(json_decode($context->prompt->input, true)))->toBe(['subject', 'context_truncated', 'messages'])
        ->and($context->prompt->input)->not->toContain($context->sourceRevision, 'CATALOGUE_ONLY_MARKER');
});

test('automatic eligibility distinguishes actual workspace type and leaves Manual explicit', function (string $purpose, string $workspace): void {
    $world = preparationIdentityWorld();
    $identity = preparationIdentity($world, $purpose);
    expect($identity->canPrepareOnOpen($workspace))->toBeTrue()
        ->and($identity->canPrepareOnOpen($workspace === 'conversion' ? 'conversation' : 'conversion'))->toBeFalse()
        ->and($identity->canPrepareOnOpen('prefetch'))->toBeFalse();
    $world['policy']->forceFill(['mode' => 'manual', 'revision' => (string) Str::uuid()])->save();
    $manual = preparationIdentity($world, $purpose);
    expect($manual->mode)->toBe('manual')->and($manual->canPrepareOnOpen($workspace))->toBeFalse()
        ->and($manual->matches($identity))->toBeFalse();
})->with([
    ['conversation_summary', 'conversation'], ['conversation_reply_draft', 'conversation'],
    ['conversation_knowledge_suggestion', 'conversation'], ['conversation_ticket_suggestion', 'conversion'],
]);

test('capability and local scope partition shared keys', function (): void {
    $world = preparationIdentityWorld();
    $keys = [];
    foreach (['conversation_summary', 'conversation_reply_draft', 'conversation_knowledge_suggestion', 'conversation_ticket_suggestion'] as $purpose) {
        $keys[] = preparationIdentity($world, $purpose)->fingerprint;
    }
    expect(array_unique($keys))->toHaveCount(4);
    $other = Conversation::factory()->for($world['site'])->create(['subject' => $world['conversation']->subject]);
    ConversationMessage::factory()->for($other)->create(['body' => $world['message']->body]);
    expect(preparationIdentity([...$world, 'conversation' => $other])->fingerprint)->not->toBe($keys[0]);
});

test('fresh authority rejects stale cross-account site and role access', function (string $change): void {
    $world = preparationIdentityWorld();
    $actor = $world['owner'];
    preparationIdentity($world);
    match ($change) {
        'cross-account' => $actor = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Owner]),
        'deactivated' => User::query()->whereKey($actor->id)->update(['deactivated_at' => now()]),
        'site-revoked' => $world['site']->supportAgents()->attach($world['agent']),
        'actor-moved' => User::query()->whereKey($actor->id)->update(['account_id' => Account::factory()->create()->id]),
        'conversation-deleted' => $world['conversation']->delete(),
        'reply-revoked' => (function () use ($world, $actor): void {
            $role = CustomRole::factory()->for($world['account'])->create(['permissions' => [AccountPermission::ViewConversations->value]]);
            User::query()->whereKey($actor->id)->update(['custom_role_id' => $role->id]);
        })(),
    };
    expect(fn () => preparationIdentity($world, 'conversation_reply_draft', $actor))->toThrow(AuthorizationException::class);
})->with(['cross-account', 'deactivated', 'site-revoked', 'actor-moved', 'conversation-deleted', 'reply-revoked']);

test('Off prevents knowledge queries and personalized refinement never joins shared tasks', function (): void {
    $world = preparationIdentityWorld();
    $world['policy']->forceFill(['mode' => 'off', 'revision' => (string) Str::uuid()])->save();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    expect(fn () => preparationIdentity($world, 'conversation_knowledge_suggestion'))->toThrow(AiNetworkException::class);
    expect(array_filter($queries, fn ($sql) => str_contains($sql, 'articles')))->toBe([]);
    Article::query()->count();
    expect(array_filter($queries, fn ($sql) => str_contains($sql, 'articles')))->not->toBe([], 'The query detector must catch a known article read.');
    expect(fn () => preparationIdentity($world, 'conversation_draft_refinement'))->toThrow(AiNetworkException::class);
});

test('same-ID source changes hidden by scrubbing invalidate a work key', function (): void {
    $world = preparationIdentityWorld();
    $world['message']->update(['body' => 'Contact alpha@example.test about checkout.']);
    $first = preparationIdentity($world);
    $input = app(ConversationSummaryPromptBuilder::class)->build($world['conversation'])->prompt->input;
    DB::table('conversation_messages')->where('id', $world['message']->id)->update(['body' => 'Contact bravo@example.test about checkout.']);
    expect(app(ConversationSummaryPromptBuilder::class)->build($world['conversation'])->prompt->input)->toBe($input);
    expect(preparationIdentity($world)->fingerprint)->not->toBe($first->fingerprint, 'A source edit hidden by redaction must still invalidate its preparation receipt.');
});

test('selected deletion and attachment-only follow-up invalidate without sending attachments', function (string $change): void {
    $world = preparationIdentityWorld();
    $newest = ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Another checkout detail.']);
    $first = preparationIdentity($world);
    $oldInput = app(ConversationSummaryPromptBuilder::class)->build($world['conversation'])->prompt->input;
    if ($change === 'delete') {
        $world['message']->delete();
        expect(preparationIdentity($world)->lastMessageId)->toBe($newest->id);
    } else {
        ConversationMessage::factory()->for($world['conversation'])->create(['type' => 'attachment', 'body' => null, 'metadata' => ['private' => 'ATTACHMENT_ONLY_MARKER']]);
        expect(app(ConversationSummaryPromptBuilder::class)->build($world['conversation'])->prompt->input)->toBe($oldInput);
    }
    expect(preparationIdentity($world)->matches($first))->toBeFalse();
})->with(['delete', 'attachment']);

test('read receipts profiles and private metadata do not break unchanged-open reuse', function (): void {
    $world = preparationIdentityWorld();
    $first = preparationIdentity($world);
    $world['message']->update(['seen_at' => now(), 'metadata' => ['private' => 'METADATA_ONLY_MARKER'], 'updated_at' => now()->addMinute()]);
    $world['conversation']->update(['metadata' => ['private' => 'CONTACT_ONLY_MARKER']]);
    $world['owner']->update(['name' => 'Changed display name', 'email' => 'changed@example.test']);
    expect(preparationIdentity($world)->matches($first))->toBeTrue('Incidental seen/metadata timestamp writes on workspace opens must not create new paid work.');
});

test('unselected older text does not expand the permitted context partition', function (): void {
    $world = preparationIdentityWorld();
    $world['message']->update(['body' => 'OLDER_UNSELECTED_MARKER']);
    ConversationMessage::factory()->count(8)->for($world['conversation'])->create(['body' => str_repeat('Recent checkout detail. ', 200)]);
    expect(app(ConversationSummaryPromptBuilder::class)->build($world['conversation'])->prompt->input)->not->toContain('OLDER_UNSELECTED_MARKER');
    $first = preparationIdentity($world);
    $world['message']->update(['body' => 'Changed irrelevant old text.']);
    expect(preparationIdentity($world)->matches($first))->toBeTrue();
});

test('policy ABA and exact active processor changes invalidate while drafts remain independent', function (): void {
    $world = preparationIdentityWorld();
    $first = preparationIdentity($world);
    $world['connection']->forceFill(['proposed_revision' => (string) Str::uuid(), 'proposed_configuration' => ['source' => 'account', 'model' => 'draft-B'], 'test_status' => 'unavailable'])->save();
    expect(preparationIdentity($world)->matches($first))->toBeTrue();
    $world['connection']->forceFill(['active_revision' => (string) Str::uuid(), 'active_configuration' => [...$world['connection']->activeConfiguration(), 'model' => 'synthetic-B']])->save();
    expect(preparationIdentity($world)->matches($first))->toBeFalse();
    $second = preparationIdentity($world);
    $world['policy']->forceFill(['mode' => 'off', 'revision' => (string) Str::uuid()])->save();
    $world['policy']->forceFill(['mode' => 'prepare', 'revision' => (string) Str::uuid()])->save();
    expect(preparationIdentity($world)->matches($second))->toBeFalse();
    $world['connection']->forceFill(['enabled' => false])->save();
    expect(fn () => preparationIdentity($world))->toThrow(AiNetworkException::class);
});

test('context ceilings and prompt recipes are part of the identity', function (): void {
    $world = preparationIdentityWorld();
    $first = preparationIdentity($world);
    $world['policy']->forceFill(['max_context_characters' => 2000, 'revision' => (string) Str::uuid()])->save();
    expect(preparationIdentity($world)->matches($first))->toBeFalse();
    $second = preparationIdentity($world);
    $builder = app(ConversationSummaryPromptBuilder::class);
    app()->instance(ConversationSummaryPromptBuilder::class, new class($builder)
    {
        public function __construct(private ConversationSummaryPromptBuilder $original) {}

        public function build(Conversation $conversation): ConversationSummaryContext
        {
            $context = $this->original->build($conversation);

            return new ConversationSummaryContext(new AgentCopilotPrompt('conversation_summary', 'Changed validated prompt recipe.', $context->prompt->input),
                $context->messageCount, $context->lastMessageId, $context->sourceRevision);
        }
    });
    expect(preparationIdentity($world)->matches($second))->toBeFalse();
});

test('knowledge catalogue changes bind published same-account content only', function (string $change): void {
    $world = preparationIdentityWorld();
    $other = Article::factory()->for(Account::factory())->published()->create();
    $draft = Article::factory()->for($world['account'])->create(['published_at' => null]);
    $first = preparationIdentity($world, 'conversation_knowledge_suggestion');
    $reply = preparationIdentity($world, 'conversation_reply_draft');
    $other->update(['body' => 'Changed other account.']);
    $draft->update(['body' => 'Changed unpublished content.']);
    expect(preparationIdentity($world, 'conversation_knowledge_suggestion')->matches($first))->toBeTrue('Foreign and unpublished articles must not partition this account’s preparation cache.');
    match ($change) {
        'edit' => DB::table('articles')->where('id', $world['article']->id)->update(['body' => 'Changed raw published body without timestamp update.']),
        'publish' => $draft->update(['published_at' => now()->subMinute()]),
        'unpublish' => $world['article']->update(['published_at' => null]),
        'delete' => $world['article']->delete(),
    };
    expect(preparationIdentity($world, 'conversation_knowledge_suggestion')->matches($first))->toBeFalse()
        ->and(preparationIdentity($world, 'conversation_reply_draft')->matches($reply))->toBeTrue('Current transcript-only reply preparation must not retrieve or depend on a knowledge catalogue.');
})->with(['edit', 'publish', 'unpublish', 'delete']);

test('ticket labels and current conversion eligibility have their own boundaries', function (): void {
    $world = preparationIdentityWorld();
    $first = preparationIdentity($world, 'conversation_ticket_suggestion');
    $foreign = TicketLabel::factory()->for(Account::factory())->create();
    expect(preparationIdentity($world, 'conversation_ticket_suggestion')->matches($first))->toBeTrue();
    $world['label']->update(['name' => 'Checkout help']);
    expect(preparationIdentity($world, 'conversation_ticket_suggestion')->matches($first))->toBeFalse();
    Ticket::factory()->for($world['account'])->for($world['site'])->for($world['conversation'])->create();
    expect(fn () => preparationIdentity($world, 'conversation_ticket_suggestion'))->toThrow(AiNetworkException::class);
});

test('empty context and empty knowledge have cacheable insufficient outcomes', function (string $empty): void {
    $world = preparationIdentityWorld();
    if ($empty === 'text') {
        $world['message']->delete();
    } else {
        $world['article']->delete();
    }
    $identity = preparationIdentity($world, 'conversation_knowledge_suggestion');
    expect($identity->state)->toBe('insufficient_context')->and($identity->canPrepareOnOpen('conversation'))->toBeFalse()
        ->and($identity->reuseState($identity->fingerprint, 'insufficient_context'))->toBe('insufficient_context')
        ->and(preparationIdentity($world, 'conversation_knowledge_suggestion')->matches($identity))->toBeTrue();
})->with(['text', 'knowledge']);

test('matching receipts reuse outcomes and never turn expiry or failure into retry-on-open', function (string $status, bool $fresh, string $state): void {
    $identity = preparationIdentity(preparationIdentityWorld());
    expect($identity->reuseState($identity->fingerprint, $status, $fresh))->toBe($state,
        'Unchanged opens must reuse terminal failure/insufficient context and treat expired pending work as unavailable, not free retry.')
        ->and($identity->reuseState(str_repeat('0', 64), $status, $fresh))->toBeNull();
})->with([
    ['pending', true, 'preparing'], ['running', true, 'preparing'], ['pending', false, 'unavailable'],
    ['running', false, 'unavailable'], ['ready', false, 'ready'], ['failed', false, 'unavailable'],
    ['insufficient_context', false, 'insufficient_context'], ['unknown', false, 'unavailable'],
]);

test('snapshot and cache classification perform no writes reservations queueing or provider calls', function (): void {
    $world = preparationIdentityWorld();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $identity = preparationIdentity($world);
    $identity->reuseState($identity->fingerprint, 'ready');
    $isWrite = fn ($sql) => preg_match('/\A(?:insert|update|delete|replace|create|alter|drop)\b/i', $sql) === 1;
    expect(array_filter($queries, $isWrite))->toBe([]);
    $world['owner']->update(['name' => 'Known write control']);
    expect(array_filter($queries, $isWrite))->not->toBe([], 'The write detector must catch its known positive control.');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect(DB::table('ai_usage_attempts')->count())->toBe(0);
});

test('catalogue identity reads stay bounded as knowledge and labels grow', function (): void {
    $world = preparationIdentityWorld();
    Article::factory()->count(120)->for($world['account'])->published()->create(['body' => str_repeat('Large catalogue content. ', 500)]);
    TicketLabel::factory()->count(120)->for($world['account'])->create();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    preparationIdentity($world, 'conversation_knowledge_suggestion');
    $articles = array_values(array_filter($queries, fn ($sql) => str_contains($sql, '"articles"')));
    expect($articles)->toHaveCount(1, 'Catalogue lookup must read one bounded publication watermark only.');
    expect($articles[0])->toContain('"published_at"', 'limit 1')->not->toContain('"body"', '"search_text"');
    $queries = [];
    preparationIdentity($world, 'conversation_ticket_suggestion');
    expect(array_filter($queries, fn ($sql) => str_contains($sql, '"ticket_labels"')))->toBe([], 'Ticket identity must avoid scanning label rows.');
    $context = app(ConversationTicketSuggestionPromptBuilder::class)->build($world['conversation']);
    expect($context->labelIds)->toContain($world['label']->id)
        ->and(array_filter($queries, fn ($sql) => str_contains($sql, '"ticket_labels"')))->not->toBe([]);
    $indexes = Schema::getIndexes('articles');
    expect(array_filter($indexes, fn ($index) => $index['columns'] === ['account_id', 'published_at']))->not->toBe([]);
});

test('scheduled publication changes only the owning account identity without a source write', function (): void {
    $world = preparationIdentityWorld();
    $this->freezeTime();
    $foreign = Account::factory()->create();
    Article::factory()->for($foreign)->create(['published_at' => now()->addMinute()]);
    Article::factory()->for($world['account'])->create(['published_at' => now()->addMinutes(3)]);
    $revision = DB::table('accounts')->where('id', $world['account']->id)->value('ai_article_catalogue_revision');
    $first = preparationIdentity($world, 'conversation_knowledge_suggestion');
    $this->travel(2)->minutes();
    expect(preparationIdentity($world, 'conversation_knowledge_suggestion')->matches($first))->toBeTrue('Foreign scheduled publication must not alter this account identity.');
    $this->travel(2)->minutes();
    expect(preparationIdentity($world, 'conversation_knowledge_suggestion')->matches($first))->toBeFalse()
        ->and(DB::table('accounts')->where('id', $world['account']->id)->value('ai_article_catalogue_revision'))->toBe($revision);
});

test('catalogue revisions join bulk writes and roll back with their source', function (string $table, string $field, string $column, string $purpose): void {
    $world = preparationIdentityWorld();
    $before = preparationIdentity($world, $purpose);
    $revision = DB::table('accounts')->where('id', $world['account']->id)->value($column);
    DB::beginTransaction();
    try {
        DB::table($table)->where('account_id', $world['account']->id)->update([$field => 'Changed through bulk SQL']);
        expect(DB::table('accounts')->where('id', $world['account']->id)->value($column))->not->toBe($revision)
            ->and(preparationIdentity($world, $purpose)->matches($before))->toBeFalse();
    } finally {
        DB::rollBack();
    }
    expect(DB::table('accounts')->where('id', $world['account']->id)->value($column))->toBe($revision)
        ->and(preparationIdentity($world, $purpose)->matches($before))->toBeTrue();
    DB::table($table)->where('account_id', $world['account']->id)->update([$field => 'Committed through bulk SQL']);
    expect(preparationIdentity($world, $purpose)->matches($before))->toBeFalse('Committed bulk SQL must invalidate catalogue identity.');
})->with([
    ['articles', 'body', 'ai_article_catalogue_revision', 'conversation_knowledge_suggestion'],
    ['ticket_labels', 'name', 'ai_ticket_label_catalogue_revision', 'conversation_ticket_suggestion'],
]);

test('catalogue moves invalidate both owners and account cascade remains deletable', function (string $table, string $source, string $column): void {
    $world = preparationIdentityWorld();
    $other = Account::factory()->create();
    $oldRevision = DB::table('accounts')->where('id', $world['account']->id)->value($column);
    DB::table($table)->where('id', $world[$source]->id)->update(['account_id' => $other->id]);
    expect(DB::table('accounts')->where('id', $world['account']->id)->value($column))->not->toBe($oldRevision)
        ->and(DB::table('accounts')->where('id', $other->id)->value($column))->not->toBeNull();
    $other->delete();
    expect(DB::table($table)->where('id', $world[$source]->id)->exists())->toBeFalse();
})->with([
    ['articles', 'article', 'ai_article_catalogue_revision'],
    ['ticket_labels', 'label', 'ai_ticket_label_catalogue_revision'],
]);

test('catalogue revision migration round trips without seeding or changing content', function (): void {
    $world = preparationIdentityWorld();
    $article = DB::table('articles')->where('id', $world['article']->id)->first();
    $label = DB::table('ticket_labels')->where('id', $world['label']->id)->first();
    $migration = require database_path('migrations/2026_10_16_170000_add_ai_catalogue_revisions_to_accounts.php');
    $migration->down();
    expect(Schema::hasColumn('accounts', 'ai_article_catalogue_revision'))->toBeFalse();
    if (DB::connection()->getDriverName() === 'pgsql') {
        // migrate:fresh drops tables/triggers but leaves PostgreSQL functions.
        // Recreate that residue with stale bodies; up must replace it, not fail
        // or silently retain a no-op invalidation function.
        foreach (['articles', 'ticket_labels'] as $table) {
            DB::unprepared("CREATE FUNCTION wf_ai_{$table}_revision() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RETURN NULL; END; \$\$");
        }
    }
    $failure = null;
    try {
        DB::transaction(fn () => $migration->up());
    } catch (Throwable $exception) {
        $failure = $exception;
    }
    expect($failure)->toBeNull('Fresh migration must replace retained PostgreSQL catalogue functions.');
    expect(DB::table('articles')->where('id', $world['article']->id)->first())->toEqual($article)
        ->and(DB::table('ticket_labels')->where('id', $world['label']->id)->first())->toEqual($label)
        ->and(DB::table('accounts')->where('id', $world['account']->id)->value('ai_article_catalogue_revision'))->toBeNull()
        ->and(DB::table('accounts')->where('id', $world['account']->id)->value('ai_ticket_label_catalogue_revision'))->toBeNull();
    DB::table('articles')->where('id', $article->id)->update(['body' => 'Post migration SQL edit']);
    DB::table('ticket_labels')->where('id', $label->id)->delete();
    expect(DB::table('accounts')->where('id', $world['account']->id)->value('ai_article_catalogue_revision'))->toBeUuid()
        ->and(DB::table('accounts')->where('id', $world['account']->id)->value('ai_ticket_label_catalogue_revision'))->toBeUuid();
});
