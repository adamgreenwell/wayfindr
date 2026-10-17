<?php

declare(strict_types=1);

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Jobs\GenerateConversationCopilotKnowledgeSuggestion;
use App\Models\Account;
use App\Models\AccountAiConnection;
use App\Models\Article;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationCopilotKnowledgeSuggestion;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\AgentCopilotPrompt;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\Usage\AiUsageClock;
use App\Support\Settings\OperatorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../Fixtures/copilot-test-clock.php';

uses(RefreshDatabase::class);

/** @return array{account: Account, agent: User, site: Site, visitor: Visitor, conversation: Conversation} */
function conversationCopilotKnowledgeWorld(): array
{
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create([
        'name' => 'Private Visitor Name',
        'email' => 'private-visitor@example.test',
        'metadata' => ['context' => ['private_plan' => 'Do not export this field']],
    ]);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-AIKNOW',
        'subject' => 'Renewal checkout for ada@example.test',
        'metadata' => ['private_note' => 'Do not export this conversation metadata'],
    ]);

    return compact('account', 'agent', 'site', 'visitor', 'conversation');
}

function configureConversationCopilotKnowledge(): void
{
    app()->instance(AiUsageClock::class, new CopilotTestUsageClock);
    $settings = app(OperatorSettings::class);
    $settings->set('ai.provider', 'ollama');
    $settings->set('ai.model', 'qwen3.5:4b');
    $settings->set('ai.endpoint', 'http://localhost:11434');
    $settings->applyOverrides();
}

function queueConversationCopilotKnowledge(array $world): GenerateConversationCopilotKnowledgeSuggestion
{
    Queue::fake();

    test()->actingAs($world['agent'])
        ->post(route('dashboard.conversations.copilot-knowledge-suggestion.store', $world['conversation']->support_code))
        ->assertRedirect();

    $queued = null;
    Queue::assertPushed(GenerateConversationCopilotKnowledgeSuggestion::class, function (GenerateConversationCopilotKnowledgeSuggestion $job) use (&$queued): bool {
        $queued = $job;

        return true;
    });

    expect($queued)->toBeInstanceOf(GenerateConversationCopilotKnowledgeSuggestion::class);

    return $queued;
}

test('knowledge is absent without a provider and insufficient without a published article', function (): void {
    $world = conversationCopilotKnowledgeWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'How do renewals work?']);
    Queue::fake();

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertDontSee('data-task="knowledge"', false);

    configureConversationCopilotKnowledge();
    Article::factory()->for($world['account'])->create(['title' => 'Private draft']);

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertSee('data-task="knowledge"', false)
        ->assertSee('There is not enough eligible context to prepare a suggestion.');

    $this->actingAs($world['agent'])
        ->post(route('dashboard.conversations.copilot-knowledge-suggestion.store', $world['conversation']->support_code))
        ->assertRedirect();

    Queue::assertNothingPushed();
    expect(ConversationCopilotKnowledgeSuggestion::query()->sole()->status)->toBe('insufficient_context');
});

test('knowledge suggestions require current reply permission', function (): void {
    $world = conversationCopilotKnowledgeWorld();
    $viewerRole = CustomRole::factory()->for($world['account'])->create([
        'permissions' => [AccountPermission::ViewConversations->value],
    ]);
    $viewer = User::factory()->for($world['account'])->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $viewerRole->id,
    ]);
    $world['site']->supportAgents()->attach($viewer);
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Private support message.']);
    Article::factory()->for($world['account'])->published()->create();
    configureConversationCopilotKnowledge();
    Queue::fake();

    $this->actingAs($viewer)
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertDontSee('data-task="knowledge"', false);

    $this->actingAs($viewer)
        ->post(route('dashboard.conversations.copilot-knowledge-suggestion.store', $world['conversation']->support_code))
        ->assertNotFound();

    $this->actingAs($viewer)
        ->get(route('dashboard.conversations.copilot-knowledge-suggestion.show', $world['conversation']->support_code))
        ->assertNotFound();

    Queue::assertNothingPushed();
});

test('a published article exposes an explicit suggested knowledge action', function (): void {
    $world = conversationCopilotKnowledgeWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'How do renewals work?']);
    Article::factory()->for($world['account'])->published()->create();
    configureConversationCopilotKnowledge();

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertSee(__('ai_assist.rail.sections.knowledge'))
        ->assertSee('Prepare suggestion')
        ->assertSee('article titles, content, and the catalogue stay inside Wayfindr')
        ->assertSee('data-task="knowledge"', false)
        ->assertSee('window.wayfindrConversationKnowledgeSuggestionTranscriptUpdated', false);
});

test('requesting knowledge suggestions queues an id-only job and safe audit metadata', function (): void {
    $world = conversationCopilotKnowledgeWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Checkout renewals are private.']);
    Article::factory()->for($world['account'])->published()->create();
    configureConversationCopilotKnowledge();
    Queue::fake();

    $this->actingAs($world['agent'])
        ->post(route('dashboard.conversations.copilot-knowledge-suggestion.store', $world['conversation']->support_code))
        ->assertRedirect()
        ->assertSessionHas('status', 'conversations.flash.ai_knowledge_suggestion_queued');

    $suggestion = ConversationCopilotKnowledgeSuggestion::query()->sole();
    expect($suggestion->status)->toBe(ConversationCopilotKnowledgeSuggestion::STATUS_PENDING)
        ->and($suggestion->article_ids)->toBeNull()
        ->and($suggestion->requested_by_id)->toBe($world['agent']->id);

    $queued = null;
    Queue::assertPushed(GenerateConversationCopilotKnowledgeSuggestion::class, function (GenerateConversationCopilotKnowledgeSuggestion $job) use (&$queued): bool {
        $queued = $job;

        return true;
    });
    expect(serialize($queued))
        ->not->toContain('Checkout renewals')
        ->not->toContain('WF-AIKNOW');

    $event = AuditEvent::query()->where('action', 'conversation.ai_knowledge_suggestion.requested')->sole();
    expect($event->metadata)->toBe([
        'suggestion_id' => $suggestion->id,
        'generation' => $suggestion->generation,
        'connection_revision' => $suggestion->connection_revision,
    ])->and(json_encode($event->metadata))->not->toContain('Checkout renewals');

    $this->actingAs($world['agent'])
        ->post(route('dashboard.conversations.copilot-knowledge-suggestion.store', $world['conversation']->support_code))
        ->assertSessionHas('status', 'conversations.flash.ai_knowledge_suggestion_pending');

    Queue::assertPushed(GenerateConversationCopilotKnowledgeSuggestion::class, 1);
});

test('the worker sends scrubbed transcript text and matches only published account articles locally', function (): void {
    $world = conversationCopilotKnowledgeWorld();
    $renewal = Article::factory()->for($world['account'])->published()->create([
        'title' => 'Renewal checkout troubleshooting',
        'body' => 'Open billing settings and retry the renewal payment.',
    ]);
    $payment = Article::factory()->for($world['account'])->published()->create([
        'title' => 'Payment help',
        'body' => 'A failed card payment can be retried after verification.',
    ]);
    Article::factory()->for($world['account'])->published()->create([
        'title' => 'Shipping policy',
        'body' => 'Packages leave by courier.',
    ]);
    Article::factory()->for($world['account'])->create([
        'title' => 'Renewal checkout internal draft',
        'body' => 'This draft must never be suggested.',
    ]);
    Article::factory()->for(Account::factory())->published()->create([
        'title' => 'Renewal checkout for another account',
        'body' => 'Tenant-private catalogue content.',
    ]);
    $message = ConversationMessage::factory()->for($world['conversation'])->create([
        'sender_type' => Visitor::class,
        'sender_id' => $world['visitor']->id,
        'body' => 'Email ada@example.test {"token":"visitor-secret"}. Renewal checkout rejects payment.',
        'metadata' => ['private_trace' => 'metadata-must-not-leave'],
    ]);
    ConversationMessageAttachment::factory()->forMessage($message)->create([
        'original_filename' => 'private-bank-statement.pdf',
    ]);
    $attachmentOnly = ConversationMessage::factory()->for($world['conversation'])->create(['body' => null]);
    ConversationMessageAttachment::factory()->forMessage($attachmentOnly)->create([
        'original_filename' => 'latest-private-screenshot.png',
    ]);
    configureConversationCopilotKnowledge();
    $job = queueConversationCopilotKnowledge($world);

    $fake = new class implements AgentCopilotProvider
    {
        public ?AgentCopilotPrompt $prompt = null;

        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            $this->prompt = $prompt;

            return new AgentCopilotResult(
                json_encode(['queries' => ['renewal checkout', 'payment']], JSON_THROW_ON_ERROR),
                'fake-provider',
                'fake-model',
                111,
                19,
            );
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('The knowledge suggestion job must not run a provider probe.');
        }
    };
    app()->instance(AgentCopilotProvider::class, $fake);

    app()->call([$job, 'handle']);

    expect($fake->prompt)->not->toBeNull()
        ->and($fake->prompt->purpose)->toBe('conversation_knowledge_suggestion')
        ->and($fake->prompt->timeoutSeconds)->toBe(75)
        ->and($fake->prompt->instructions)->toContain('article catalogue is intentionally unavailable')
        ->and($fake->prompt->input)->toContain('[EMAIL REDACTED]')
        ->and($fake->prompt->input)->not->toContain('visitor-secret')
        ->and($fake->prompt->input)->not->toContain('Private Visitor Name')
        ->and($fake->prompt->input)->not->toContain('private-visitor@example.test')
        ->and($fake->prompt->input)->not->toContain('metadata-must-not-leave')
        ->and($fake->prompt->input)->not->toContain('private-bank-statement.pdf')
        ->and($fake->prompt->input)->not->toContain('latest-private-screenshot.png')
        ->and($fake->prompt->input)->not->toContain($renewal->title)
        ->and($fake->prompt->input)->not->toContain($payment->body);

    $suggestion = ConversationCopilotKnowledgeSuggestion::query()->sole();
    expect($suggestion->status)->toBe(ConversationCopilotKnowledgeSuggestion::STATUS_READY)
        ->and($suggestion->suggestedArticleIds())->toBe([$renewal->id, $payment->id])
        ->and($suggestion->source_message_count)->toBe(1)
        ->and($suggestion->source_last_message_id)->toBe($attachmentOnly->id)
        ->and($suggestion->provider)->toBe('fake-provider')
        ->and($suggestion->model)->toBe('fake-model');

    $generated = AuditEvent::query()->where('action', 'conversation.ai_knowledge_suggestion.generated')->sole();
    expect($generated->metadata['suggested_article_count'])->toBe(2)
        ->and(json_encode($generated->metadata))
        ->not->toContain($renewal->title)
        ->not->toContain('renewal checkout');

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertSee($renewal->title)
        ->assertSee('Open billing settings and retry the renewal payment.')
        ->assertSee($payment->title)
        ->assertDontSee('Shipping policy')
        ->assertDontSee('Tenant-private catalogue content')
        ->assertSee('Insert excerpt')
        ->assertSee('data-copilot-knowledge-use', false)
        ->assertSee("body.value.trim() !== ''", false)
        ->assertSee("body.dispatchEvent(new Event('input'", false)
        ->assertSee('const maximumAttempts = 210;', false);
});

test('invalid knowledge search output becomes a retryable failure', function (string $output): void {
    $world = conversationCopilotKnowledgeWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Please find an article.']);
    Article::factory()->for($world['account'])->published()->create();
    configureConversationCopilotKnowledge();
    $job = queueConversationCopilotKnowledge($world);

    app()->instance(AgentCopilotProvider::class, new class($output) implements AgentCopilotProvider
    {
        public function __construct(private readonly string $output) {}

        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            return new AgentCopilotResult($this->output, 'fake', 'fake');
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('The knowledge suggestion job must not run a provider probe.');
        }
    });

    app()->call([$job, 'handle']);

    $suggestion = ConversationCopilotKnowledgeSuggestion::query()->sole();
    expect($suggestion->status)->toBe(ConversationCopilotKnowledgeSuggestion::STATUS_FAILED)
        ->and($suggestion->failure_code)->toBe('invalid_output')
        ->and($suggestion->article_ids)->toBeNull();
})->with([
    'not json' => 'renewal checkout',
    'wrong shape' => '{"query":"renewal"}',
    'extra key' => '{"queries":["renewal"],"answer":"yes"}',
    'object masquerading as an array' => '{"queries":{"0":"renewal"}}',
    'empty list' => '{"queries":[]}',
    'too many queries' => '{"queries":["one","two","three","four","five","six"]}',
    'short query' => '{"queries":["x"]}',
    'oversized query' => json_encode(['queries' => [str_repeat('a', 81)]], JSON_THROW_ON_ERROR),
    'oversized output with a valid prefix' => '{"queries":["renewal"]}'.str_repeat(' ', 8_000).'forbidden trailing prose',
    'non-string query' => '{"queries":[42]}',
]);

test('no local article match is a safe ready result', function (): void {
    $world = conversationCopilotKnowledgeWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Question about renewals.']);
    Article::factory()->for($world['account'])->published()->create([
        'title' => 'Shipping policy',
        'body' => 'Packages leave by courier.',
    ]);
    configureConversationCopilotKnowledge();
    $job = queueConversationCopilotKnowledge($world);
    app()->instance(AgentCopilotProvider::class, new class implements AgentCopilotProvider
    {
        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            return new AgentCopilotResult('{"queries":["renewal checkout"]}', 'fake', 'fake');
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('The knowledge suggestion job must not run a provider probe.');
        }
    });

    app()->call([$job, 'handle']);

    expect(ConversationCopilotKnowledgeSuggestion::query()->sole()->suggestedArticleIds())->toBe([]);

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertSee('No matching published articles were found.')
        ->assertDontSee('<article class="notice-copy notice-copy-bordered" data-copilot-knowledge-article>', false);
});

test('provider failures stay generic in storage logs and the agent surface', function (): void {
    $world = conversationCopilotKnowledgeWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Please find an article.']);
    Article::factory()->for($world['account'])->published()->create();
    configureConversationCopilotKnowledge();
    $job = queueConversationCopilotKnowledge($world);
    Log::spy();
    app()->instance(AgentCopilotProvider::class, new class implements AgentCopilotProvider
    {
        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            throw new RuntimeException('Bearer provider-secret from https://private-provider.example.test/account/42');
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('The knowledge suggestion job must not run a provider probe.');
        }
    });

    app()->call([$job, 'handle']);

    $suggestion = ConversationCopilotKnowledgeSuggestion::query()->sole();
    expect($suggestion->status)->toBe(ConversationCopilotKnowledgeSuggestion::STATUS_FAILED)
        ->and($suggestion->failure_code)->toBe('provider');

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Conversation copilot knowledge suggestion generation failed.', Mockery::on(
            fn (array $context): bool => ($context['exception_type'] ?? null) === RuntimeException::class
                && ! str_contains(json_encode($context), 'provider-secret')
                && ! str_contains(json_encode($context), 'private-provider.example.test')
        ));

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertSee('AI assistance is unavailable right now. Your support workflow still works.')
        ->assertDontSee('provider-secret');
});

test('the worker rechecks reply permission before sending transcript text', function (): void {
    $world = conversationCopilotKnowledgeWorld();
    $replyRole = CustomRole::factory()->for($world['account'])->create([
        'permissions' => [
            AccountPermission::ViewConversations->value,
            AccountPermission::ReplyToConversations->value,
        ],
    ]);
    $world['agent']->forceFill([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $replyRole->id,
    ])->save();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Private support message.']);
    Article::factory()->for($world['account'])->published()->create();
    configureConversationCopilotKnowledge();
    $job = queueConversationCopilotKnowledge($world);
    $replyRole->forceFill(['permissions' => [AccountPermission::ViewConversations->value]])->save();

    $fake = new class implements AgentCopilotProvider
    {
        public bool $called = false;

        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            $this->called = true;

            return new AgentCopilotResult('{"queries":["private support"]}', 'fake', 'fake');
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('The knowledge suggestion job must not run a provider probe.');
        }
    };
    app()->instance(AgentCopilotProvider::class, $fake);

    app()->call([$job, 'handle']);

    expect($fake->called)->toBeFalse()
        ->and(ConversationCopilotKnowledgeSuggestion::query()->sole()->failure_code)->toBe('access_revoked');
});

test('a new attachment-only message stales knowledge suggestions and blocks insertion', function (): void {
    $world = conversationCopilotKnowledgeWorld();
    $article = Article::factory()->for($world['account'])->published()->create([
        'title' => 'Renewal help',
        'body' => 'Retry the renewal from billing settings.',
    ]);
    $source = ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Can you help?']);
    configureConversationCopilotKnowledge();
    ConversationCopilotKnowledgeSuggestion::query()->create([
        'conversation_id' => $world['conversation']->id,
        'requested_by_id' => $world['agent']->id,
        'generation' => (string) Str::uuid(),
        'connection_revision' => app(AgentCopilotConnectionResolver::class)->forAccount((int) $world['account']->id)->revision,
        'status' => ConversationCopilotKnowledgeSuggestion::STATUS_READY,
        'article_ids' => [$article->id],
        'source_last_message_id' => $source->id,
        'source_message_count' => 1,
        'requested_at' => now()->subMinute(),
        'completed_at' => now()->subMinute(),
    ]);
    $attachmentOnly = ConversationMessage::factory()->for($world['conversation'])->create(['body' => null]);
    ConversationMessageAttachment::factory()->forMessage($attachmentOnly)->create([
        'original_filename' => 'new-context.png',
    ]);

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertSee('New messages arrived after these suggestions.')
        ->assertSee('data-copilot-knowledge-suggestion-stale', false)
        ->assertSee('data-copilot-knowledge-use', false)
        ->assertSee('disabled', false)
        ->assertSee('button.disabled = true', false);
});

test('unpublished or deleted suggestions disappear at display time', function (): void {
    $world = conversationCopilotKnowledgeWorld();
    $remaining = Article::factory()->for($world['account'])->published()->create([
        'title' => 'Remaining article',
        'body' => 'Still public.',
    ]);
    $withdrawn = Article::factory()->for($world['account'])->published()->create([
        'title' => 'Withdrawn article',
        'body' => 'No longer public.',
    ]);
    $message = ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Question.']);
    configureConversationCopilotKnowledge();
    ConversationCopilotKnowledgeSuggestion::query()->create([
        'conversation_id' => $world['conversation']->id,
        'requested_by_id' => $world['agent']->id,
        'generation' => (string) Str::uuid(),
        'connection_revision' => app(AgentCopilotConnectionResolver::class)->forAccount((int) $world['account']->id)->revision,
        'status' => ConversationCopilotKnowledgeSuggestion::STATUS_READY,
        'article_ids' => [$withdrawn->id, $remaining->id],
        'source_last_message_id' => $message->id,
        'source_message_count' => 1,
        'requested_at' => now()->subMinute(),
        'completed_at' => now()->subMinute(),
    ]);
    $withdrawn->forceFill(['published_at' => null])->save();

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.copilot-knowledge-suggestion.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertSee('Remaining article')
        ->assertDontSee('Withdrawn article')
        ->assertDontSee('No longer public.');
});

/** Provider callback runs inside the external wait boundary without making a network call. */
function conversationCopilotKnowledgeRevisionProvider(Closure $callback): AgentCopilotProvider
{
    return new class($callback) implements AgentCopilotProvider
    {
        public int $calls = 0;

        public ?AgentCopilotPrompt $prompt = null;

        public function __construct(private readonly Closure $callback) {}

        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            $this->calls++;
            $this->prompt = $prompt;
            ($this->callback)();

            return new AgentCopilotResult('{"queries":["renewal"]}', 'fake', 'fake');
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('A generation must not run a connection probe.');
        }
    };
}

test('knowledge-suggestion generations reject connection changes before and during provider work', function (bool $duringCall, bool $disable, bool $accountDisabled = false): void {
    $world = conversationCopilotKnowledgeWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Please help with renewal.']);
    Article::factory()->for($world['account'])->published()->create(['title' => 'Renewal help', 'body' => 'Renewal guidance.']);
    configureConversationCopilotKnowledge();
    $job = queueConversationCopilotKnowledge($world);
    $requestedRevision = ConversationCopilotKnowledgeSuggestion::query()->sole()->connection_revision;
    $change = static function () use ($disable, $accountDisabled, $world): void {
        if ($accountDisabled) {
            AccountAiConnection::query()->forceCreate(['account_id' => $world['account']->id, 'enabled' => false]);

            return;
        }
        app(OperatorSettings::class)->set($disable ? 'ai.provider' : 'ai.model', $disable ? '' : 'replacement-model');
    };
    if (! $duringCall) {
        $change();
    }
    $fake = conversationCopilotKnowledgeRevisionProvider(function () use ($duringCall, $change): void {
        if ($duringCall) {
            $change();
        }
    });
    app()->instance(AgentCopilotProvider::class, $fake);

    app()->call([$job, 'handle']);

    $artifact = ConversationCopilotKnowledgeSuggestion::query()->sole();
    expect($fake->calls)->toBe($duringCall ? 1 : 0)
        ->and($artifact->status)->toBe(ConversationCopilotKnowledgeSuggestion::STATUS_FAILED)
        ->and($artifact->failure_code)->toBe('connection_changed');
    if ($duringCall) {
        expect($fake->prompt?->connection?->accountId)->toBe((int) $world['account']->id)
            ->and($fake->prompt?->connection?->revision)->toBe($requestedRevision);
    }
})->with([
    'rotated while queued' => [false, false],
    'disabled while queued' => [false, true],
    'rotated during provider' => [true, false],
    'disabled during provider' => [true, true],
    'account disabled while queued' => [false, false, true],
    'account disabled during provider' => [true, false, true],
]);

test('knowledge-suggestion pending work coalesces only within the requested connection revision', function (): void {
    $world = conversationCopilotKnowledgeWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Please help with renewal.']);
    Article::factory()->for($world['account'])->published()->create(['title' => 'Renewal help', 'body' => 'Renewal guidance.']);
    configureConversationCopilotKnowledge();
    $oldJob = queueConversationCopilotKnowledge($world);
    $old = ConversationCopilotKnowledgeSuggestion::query()->sole();
    app(OperatorSettings::class)->set('ai.model', 'replacement-model');

    $this->actingAs($world['agent'])
        ->post(route('dashboard.conversations.copilot-knowledge-suggestion.store', $world['conversation']->support_code))
        ->assertRedirect();

    $replacement = $old->fresh();
    expect($replacement->generation)->not->toBe($old->generation)
        ->and($replacement->connection_revision)->not->toBe($old->connection_revision);
    Queue::assertPushed(GenerateConversationCopilotKnowledgeSuggestion::class, 2);
    $fake = conversationCopilotKnowledgeRevisionProvider(static function (): void {});
    app()->instance(AgentCopilotProvider::class, $fake);
    app()->call([$oldJob, 'handle']);
    expect($fake->calls)->toBe(0)
        ->and($replacement->fresh()->status)->toBe(ConversationCopilotKnowledgeSuggestion::STATUS_PENDING);
});

test('knowledge-suggestion legacy queued work cannot adopt the current installation connection', function (): void {
    $world = conversationCopilotKnowledgeWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Please help with renewal.']);
    Article::factory()->for($world['account'])->published()->create(['title' => 'Renewal help', 'body' => 'Renewal guidance.']);
    configureConversationCopilotKnowledge();
    $job = queueConversationCopilotKnowledge($world);
    ConversationCopilotKnowledgeSuggestion::query()->sole()->forceFill(['connection_revision' => null])->save();
    $fake = conversationCopilotKnowledgeRevisionProvider(static function (): void {});
    app()->instance(AgentCopilotProvider::class, $fake);

    app()->call([$job, 'handle']);

    expect($fake->calls)->toBe(0)
        ->and(ConversationCopilotKnowledgeSuggestion::query()->sole()->failure_code)->toBe('connection_changed');
});

test('knowledge-suggestion output is withheld after connection changes and for unversioned legacy rows', function (bool $legacy): void {
    $world = conversationCopilotKnowledgeWorld();
    $message = ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Please help with renewal.']);
    $article = Article::factory()->for($world['account'])->published()->create(['title' => 'Previously selected source', 'body' => 'A private obsolete knowledge excerpt.']);
    configureConversationCopilotKnowledge();
    $revision = app(AgentCopilotConnectionResolver::class)->forAccount((int) $world['account']->id)->revision;
    ConversationCopilotKnowledgeSuggestion::query()->create([
        'conversation_id' => $world['conversation']->id,
        'requested_by_id' => $world['agent']->id,
        'generation' => (string) Str::uuid(),
        'connection_revision' => $legacy ? null : $revision,
        'status' => ConversationCopilotKnowledgeSuggestion::STATUS_READY,
        'article_ids' => [$article->id],
        'source_last_message_id' => $message->id,
        'source_message_count' => 1,
        'requested_at' => now(),
        'completed_at' => now(),
    ]);
    if (! $legacy) {
        app(OperatorSettings::class)->set('ai.model', 'replacement-model');
    }

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.copilot-knowledge-suggestion.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertDontSee('A private obsolete knowledge excerpt.');
    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertDontSee('A private obsolete knowledge excerpt.');
})->with(['superseded revision' => false, 'legacy revision' => true]);

test('knowledge-suggestion completion rechecks requester authority after the provider wait', function (): void {
    $world = conversationCopilotKnowledgeWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Please help with renewal.']);
    Article::factory()->for($world['account'])->published()->create(['title' => 'Renewal help', 'body' => 'Renewal guidance.']);
    configureConversationCopilotKnowledge();
    $job = queueConversationCopilotKnowledge($world);
    $fake = conversationCopilotKnowledgeRevisionProvider(function () use ($world): void {
        $world['agent']->forceFill(['deactivated_at' => now()])->save();
    });
    app()->instance(AgentCopilotProvider::class, $fake);

    app()->call([$job, 'handle']);

    expect($fake->calls)->toBe(1)
        ->and(ConversationCopilotKnowledgeSuggestion::query()->sole()->status)->toBe(ConversationCopilotKnowledgeSuggestion::STATUS_FAILED)
        ->and(ConversationCopilotKnowledgeSuggestion::query()->sole()->failure_code)->toBe('access_revoked');
});

test('knowledge-suggestion rotation cannot replace an in-flight claim used by visitor erasure', function (): void {
    $world = conversationCopilotKnowledgeWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Please help.']);
    Article::factory()->for($world['account'])->published()->create();
    configureConversationCopilotKnowledge();
    queueConversationCopilotKnowledge($world);
    $artifact = ConversationCopilotKnowledgeSuggestion::query()->sole();
    $artifact->forceFill([
        'status' => ConversationCopilotKnowledgeSuggestion::STATUS_RUNNING,
        'started_at' => now(),
    ])->save();
    app(OperatorSettings::class)->set('ai.model', 'replacement-model');

    $this->actingAs($world['agent'])
        ->post(route('dashboard.conversations.copilot-knowledge-suggestion.store', $world['conversation']->support_code))
        ->assertRedirect();

    $retained = $artifact->fresh();
    expect($retained->status)->toBe(ConversationCopilotKnowledgeSuggestion::STATUS_RUNNING)
        ->and($retained->generation)->toBe($artifact->generation)
        ->and($retained->started_at->equalTo($artifact->started_at))->toBeTrue()
        ->and($retained->connection_revision)->toBe($artifact->connection_revision);
    Queue::assertPushed(GenerateConversationCopilotKnowledgeSuggestion::class, 1);
});
