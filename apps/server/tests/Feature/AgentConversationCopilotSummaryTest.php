<?php

declare(strict_types=1);

use App\Jobs\GenerateConversationCopilotSummary;
use App\Models\Account;
use App\Models\AccountAiConnection;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationCopilotSummary;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\AgentCopilotPrompt;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\ConversationSummaryPromptBuilder;
use App\Support\Ai\Usage\AiUsageClock;
use App\Support\Settings\OperatorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../Fixtures/copilot-test-clock.php';

uses(RefreshDatabase::class);

/** @return array{account: Account, agent: User, site: Site, visitor: Visitor, conversation: Conversation} */
function conversationCopilotSummaryWorld(): array
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
        'support_code' => 'WF-AISUMRY',
        'subject' => 'Checkout help for ada@example.test',
        'metadata' => ['private_note' => 'Do not export this conversation metadata'],
    ]);

    return compact('account', 'agent', 'site', 'visitor', 'conversation');
}

function configureConversationCopilotSummary(): void
{
    app()->instance(AiUsageClock::class, new CopilotTestUsageClock);
    $settings = app(OperatorSettings::class);
    $settings->set('ai.provider', 'ollama');
    $settings->set('ai.model', 'qwen3.5:4b');
    $settings->set('ai.endpoint', 'http://localhost:11434');
    $settings->applyOverrides();
}

function queueConversationCopilotSummary(array $world): GenerateConversationCopilotSummary
{
    Queue::fake();

    test()->actingAs($world['agent'])
        ->post(route('dashboard.conversations.copilot-summary.store', $world['conversation']->support_code))
        ->assertRedirect();

    $queued = null;
    Queue::assertPushed(GenerateConversationCopilotSummary::class, function (GenerateConversationCopilotSummary $job) use (&$queued): bool {
        $queued = $job;

        return true;
    });

    expect($queued)->toBeInstanceOf(GenerateConversationCopilotSummary::class);

    return $queued;
}

test('the summary affordance and endpoints disappear when the provider is unconfigured', function (): void {
    $world = conversationCopilotSummaryWorld();
    ConversationMessage::factory()->for($world['conversation'])->create([
        'sender_type' => Visitor::class,
        'sender_id' => $world['visitor']->id,
        'body' => 'The checkout button is stuck.',
    ]);
    Queue::fake();

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertDontSee('Conversation summary')
        ->assertDontSee('data-task="summary"', false);

    $this->actingAs($world['agent'])
        ->post(route('dashboard.conversations.copilot-summary.store', $world['conversation']->support_code))
        ->assertNotFound();

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.copilot-summary.show', $world['conversation']->support_code))
        ->assertNotFound();

    Queue::assertNothingPushed();
    expect(ConversationCopilotSummary::query()->count())->toBe(0);
});

test('a configured provider exposes an explicitly suggested on-demand summary', function (): void {
    $world = conversationCopilotSummaryWorld();
    ConversationMessage::factory()->for($world['conversation'])->create([
        'sender_type' => Visitor::class,
        'sender_id' => $world['visitor']->id,
        'body' => 'The checkout button is stuck.',
    ]);
    configureConversationCopilotSummary();

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertSee('Catch up')
        ->assertSee('Suggested')
        ->assertSee('Prepare suggestion')
        ->assertSee('Agent and visitor profile fields, metadata, attachments, and cobrowse data are omitted')
        ->assertSee('data-task="summary"', false);
});

test('requesting a summary queues one id-only job and records safe audit metadata', function (): void {
    $world = conversationCopilotSummaryWorld();
    ConversationMessage::factory()->for($world['conversation'])->create([
        'sender_type' => Visitor::class,
        'sender_id' => $world['visitor']->id,
        'body' => 'The checkout button is stuck.',
    ]);
    configureConversationCopilotSummary();
    Queue::fake();
    $returnQuery = [
        'from_queue' => '1',
        'conversation_filter' => 'needs_reply',
        'conversation_search' => 'checkout',
        'conversation_site' => (string) $world['site']->id,
    ];

    $response = $this->actingAs($world['agent'])
        ->post(route('dashboard.conversations.copilot-summary.store', [
            'supportCode' => $world['conversation']->support_code,
            ...$returnQuery,
        ]));

    $response->assertRedirect(route('dashboard.conversations.show', [
        'supportCode' => $world['conversation']->support_code,
        'from_queue' => '1',
        'conversation_filter' => 'needs_reply',
        'conversation_search' => 'checkout',
        'conversation_site' => $world['site']->id,
    ]))->assertSessionHas('status', 'conversations.flash.ai_summary_queued');

    $summary = ConversationCopilotSummary::query()->sole();
    expect($summary->status)->toBe(ConversationCopilotSummary::STATUS_PENDING)
        ->and($summary->summary)->toBeNull()
        ->and($summary->requested_by_id)->toBe($world['agent']->id);

    Queue::assertPushed(GenerateConversationCopilotSummary::class, 1);
    $queuedJob = null;
    Queue::assertPushed(GenerateConversationCopilotSummary::class, function (GenerateConversationCopilotSummary $job) use (&$queuedJob): bool {
        $queuedJob = $job;

        return true;
    });
    expect(serialize($queuedJob))
        ->not->toContain('checkout button')
        ->not->toContain('WF-AISUMRY');

    $event = AuditEvent::query()->where('action', 'conversation.ai_summary.requested')->sole();
    expect($event->account_id)->toBe($world['account']->id)
        ->and($event->site_id)->toBe($world['site']->id)
        ->and($event->subject_id)->toBe($world['conversation']->id)
        ->and($event->metadata)->toBe([
            'summary_id' => $summary->id,
            'generation' => $summary->generation,
            'connection_revision' => $summary->connection_revision,
        ])
        ->and(json_encode($event->metadata))->not->toContain('checkout button');

    $this->actingAs($world['agent'])
        ->post(route('dashboard.conversations.copilot-summary.store', $world['conversation']->support_code))
        ->assertSessionHas('status', 'conversations.flash.ai_summary_pending');

    Queue::assertPushed(GenerateConversationCopilotSummary::class, 1);
    expect(ConversationCopilotSummary::query()->sole()->generation)->toBe($summary->generation);
});

test('the queued job selects scrubbed bounded text only and stores a reviewable summary', function (): void {
    $world = conversationCopilotSummaryWorld();
    $visitorMessage = ConversationMessage::factory()->for($world['conversation'])->create([
        'sender_type' => Visitor::class,
        'sender_id' => $world['visitor']->id,
        'body' => 'Email ada@example.test {\"token\":\"visitor-secret\",\"api_key\":\"provider-secret\"}. Checkout stalls after payment.',
        'metadata' => ['private_trace' => 'metadata-must-not-leave'],
        'created_at' => now()->subMinute(),
    ]);
    ConversationMessageAttachment::factory()->forMessage($visitorMessage)->create([
        'original_filename' => 'private-bank-statement.pdf',
    ]);
    $lastMessage = ConversationMessage::factory()->for($world['conversation'])->create([
        'sender_type' => User::class,
        'sender_id' => $world['agent']->id,
        'body' => 'Asked the visitor to retry in a private window.',
        'created_at' => now(),
    ]);
    configureConversationCopilotSummary();
    $job = queueConversationCopilotSummary($world);

    $fake = new class implements AgentCopilotProvider
    {
        public ?AgentCopilotPrompt $prompt = null;

        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            $this->prompt = $prompt;

            return new AgentCopilotResult(
                'Checkout stalls after payment. The agent asked for a private-window retry; confirmation is still needed.',
                'fake-provider',
                'fake-model',
                120,
                24,
            );
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('The summary job must not run a provider probe.');
        }
    };
    app()->instance(AgentCopilotProvider::class, $fake);

    app()->call([$job, 'handle']);
    $promptPayload = json_decode($fake->prompt->input, true, flags: JSON_THROW_ON_ERROR);
    config()->set([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'reverb-key',
        'broadcasting.connections.reverb.options.client_host' => 'desk.example.test',
        'broadcasting.connections.reverb.options.client_port' => 443,
        'broadcasting.connections.reverb.options.client_scheme' => 'https',
    ]);

    expect($fake->prompt)->not->toBeNull()
        ->and($fake->prompt->purpose)->toBe('conversation_summary')
        ->and($fake->prompt->timeoutSeconds)->toBe(75)
        ->and($job->timeout)->toBe(85)
        ->and($fake->prompt->input)->toContain('[EMAIL REDACTED]')
        ->and($promptPayload['messages'][0]['body'])->toContain('{\"token\":\"[REDACTED]\",\"api_key\":\"[REDACTED]\"}')
        ->and($fake->prompt->input)->toContain('private window')
        ->and($fake->prompt->input)->not->toContain('visitor-secret')
        ->and($fake->prompt->input)->not->toContain('provider-secret')
        ->and($fake->prompt->input)->not->toContain('Private Visitor Name')
        ->and($fake->prompt->input)->not->toContain('private-visitor@example.test')
        ->and($fake->prompt->input)->not->toContain('metadata-must-not-leave')
        ->and($fake->prompt->input)->not->toContain('private-bank-statement.pdf');

    $summary = ConversationCopilotSummary::query()->sole();
    expect($summary->status)->toBe(ConversationCopilotSummary::STATUS_READY)
        ->and($summary->started_at)->not->toBeNull()
        ->and($summary->source_message_count)->toBe(2)
        ->and($summary->source_last_message_id)->toBe($lastMessage->id)
        ->and($summary->provider)->toBe('fake-provider')
        ->and($summary->model)->toBe('fake-model')
        ->and($summary->prompt_tokens)->toBe(120)
        ->and($summary->completion_tokens)->toBe(24);

    $generated = AuditEvent::query()->where('action', 'conversation.ai_summary.generated')->sole();
    expect($generated->metadata['generation'])->toBe($summary->generation)
        ->and(json_encode($generated->metadata))
        ->not->toContain($summary->summary)
        ->not->toContain('Checkout stalls after payment');

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertSee($summary->summary)
        ->assertSee('Suggested')
        ->assertSee('Based on 2 text messages.')
        ->assertSee('Refresh')
        ->assertSee('data-copilot-summary-stale', false)
        ->assertSee('window.wayfindrConversationSummaryTranscriptUpdated', false)
        ->assertSee("if (hasNewMessages && typeof window.wayfindrConversationSummaryTranscriptUpdated === 'function')", false);

    ConversationMessage::factory()->for($world['conversation'])->create([
        'sender_type' => Visitor::class,
        'sender_id' => $world['visitor']->id,
        'body' => 'The private-window retry worked.',
    ]);

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertSee('Earlier suggestion — review the updated conversation before refreshing.');
});

test('provider failures are generic in storage logs and the agent surface', function (): void {
    $world = conversationCopilotSummaryWorld();
    ConversationMessage::factory()->for($world['conversation'])->create([
        'sender_type' => Visitor::class,
        'sender_id' => $world['visitor']->id,
        'body' => 'Please summarize this.',
    ]);
    configureConversationCopilotSummary();
    $job = queueConversationCopilotSummary($world);
    Log::spy();

    app()->instance(AgentCopilotProvider::class, new class implements AgentCopilotProvider
    {
        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            throw new RuntimeException('Bearer provider-secret from https://private-provider.example.test/account/42');
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('The summary job must not run a provider probe.');
        }
    });

    app()->call([$job, 'handle']);

    $summary = ConversationCopilotSummary::query()->sole();
    expect($summary->status)->toBe(ConversationCopilotSummary::STATUS_FAILED)
        ->and($summary->failure_code)->toBe('provider')
        ->and($summary->summary)->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Conversation copilot summary generation failed.', Mockery::on(
            fn (array $context): bool => ($context['exception_type'] ?? null) === RuntimeException::class
                && ! str_contains(json_encode($context), 'provider-secret')
                && ! str_contains(json_encode($context), 'private-provider.example.test')
        ));

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertSee('AI assistance is unavailable right now. Your support workflow still works.')
        ->assertSee('Try again')
        ->assertDontSee('provider-secret')
        ->assertDontSee('private-provider.example.test');
});

test('worker failures become generic retryable audited state', function (): void {
    $world = conversationCopilotSummaryWorld();
    ConversationMessage::factory()->for($world['conversation'])->create([
        'sender_type' => Visitor::class,
        'sender_id' => $world['visitor']->id,
        'body' => 'Please summarize this.',
    ]);
    configureConversationCopilotSummary();
    $job = queueConversationCopilotSummary($world);
    Log::spy();

    $job->failed(new RuntimeException('Bearer worker-secret from https://private-worker.example.test/account/42'));

    $summary = ConversationCopilotSummary::query()->sole();
    expect($summary->status)->toBe(ConversationCopilotSummary::STATUS_FAILED)
        ->and($summary->failure_code)->toBe('job')
        ->and($summary->summary)->toBeNull();

    $failed = AuditEvent::query()->where('action', 'conversation.ai_summary.failed')->sole();
    expect($failed->metadata)->toBe([
        'summary_id' => $summary->id,
        'generation' => $summary->generation,
        'connection_revision' => $summary->connection_revision,
        'failure_code' => 'job',
    ]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Conversation copilot summary job failed.', Mockery::on(
            fn (array $context): bool => ($context['exception_type'] ?? null) === RuntimeException::class
                && ! str_contains(json_encode($context), 'worker-secret')
                && ! str_contains(json_encode($context), 'private-worker.example.test')
        ));

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertSee('AI assistance is unavailable right now. Your support workflow still works.')
        ->assertSee('Try again')
        ->assertDontSee('worker-secret')
        ->assertDontSee('private-worker.example.test');
});

test('the worker rechecks current access before sending any transcript text', function (): void {
    $world = conversationCopilotSummaryWorld();
    ConversationMessage::factory()->for($world['conversation'])->create([
        'sender_type' => Visitor::class,
        'sender_id' => $world['visitor']->id,
        'body' => 'Private support message.',
    ]);
    configureConversationCopilotSummary();
    $job = queueConversationCopilotSummary($world);
    $world['agent']->forceFill(['deactivated_at' => now()])->save();

    $fake = new class implements AgentCopilotProvider
    {
        public bool $called = false;

        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            $this->called = true;

            return new AgentCopilotResult('Should not run.', 'fake', 'fake');
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('The summary job must not run a provider probe.');
        }
    };
    app()->instance(AgentCopilotProvider::class, $fake);

    app()->call([$job, 'handle']);

    expect($fake->called)->toBeFalse()
        ->and(ConversationCopilotSummary::query()->sole()->failure_code)->toBe('access_revoked');
});

test('summary context is bounded and prioritizes the newest text', function (): void {
    $world = conversationCopilotSummaryWorld();
    config()->set('wayfindr.ai.max_context_characters', 1_000);
    // The full varchar-sized subject still expands beyond its JSON budget,
    // without relying on SQLite's permissive varchar handling.
    $world['conversation']->forceFill(['subject' => str_repeat('\\', 255)])->save();
    ConversationMessage::factory()->for($world['conversation'])->create([
        'body' => 'OLD-CONTEXT '.str_repeat('older details ', 400),
        'created_at' => now()->subMinute(),
    ]);
    ConversationMessage::factory()->count(30)->for($world['conversation'])->create([
        'body' => str_repeat('older bounded details ', 400),
        'created_at' => now()->subSeconds(30),
    ]);
    $latest = ConversationMessage::factory()->for($world['conversation'])->create([
        'body' => 'LATEST-CONTEXT '.str_repeat('newest details ', 200),
        'created_at' => now(),
    ]);

    DB::enableQueryLog();
    $context = app(ConversationSummaryPromptBuilder::class)->build($world['conversation']);
    $transcriptQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains(strtoupper($query['query']), 'SUBSTR'))
        ->values();
    DB::disableQueryLog();
    $payload = json_decode($context->prompt->input, true, flags: JSON_THROW_ON_ERROR);

    expect($context)->not->toBeNull()
        ->and(mb_strlen($context->prompt->input))->toBeLessThanOrEqual(1_000)
        ->and($payload['context_truncated'])->toBeTrue()
        ->and($transcriptQueries)->toHaveCount(1)
        ->and(strtolower($transcriptQueries->first()['query']))->toContain('limit 25')
        ->and($context->prompt->input)->toContain('LATEST-CONTEXT')
        ->and($context->prompt->input)->not->toContain('OLD-CONTEXT')
        ->and($context->messageCount)->toBe(1)
        ->and($context->lastMessageId)->toBe($latest->id);
});

test('summary context redacts a private key whose end marker falls beyond the database prefix', function (): void {
    $world = conversationCopilotSummaryWorld();
    ConversationMessage::factory()->for($world['conversation'])->create([
        'body' => "Visible support context.\n-----BEGIN PRIVATE KEY-----\n"
            .str_repeat('private-key-material', 500)
            ."\n-----END PRIVATE KEY-----",
    ]);

    $context = app(ConversationSummaryPromptBuilder::class)->build($world['conversation']);

    expect($context)->not->toBeNull()
        ->and($context->prompt->input)->toContain('[PRIVATE KEY REDACTED]')
        ->and($context->prompt->input)->not->toContain('private-key-material');
});

test('summary context redacts a quoted credential whose closing quote falls beyond the database prefix', function (): void {
    $world = conversationCopilotSummaryWorld();
    $opening = '{"token":"';
    $secretPrefix = str_pad('bounded-secret-material', 8_000 - strlen($opening) - 1, 'x');
    ConversationMessage::factory()->for($world['conversation'])->create([
        // The SQL prefix ends on the escape immediately before the unseen tail.
        'body' => $opening.$secretPrefix.'\\'.str_repeat('unselected-tail', 100).'"}',
    ]);

    $context = app(ConversationSummaryPromptBuilder::class)->build($world['conversation']);

    expect($context)->not->toBeNull()
        ->and($context->prompt->input)->toContain('[REDACTED]')
        ->and($context->prompt->input)->not->toContain('bounded-secret-material');
});

test('conversation messages are indexed for bounded id-ordered summary reads', function (): void {
    $indexes = collect(Schema::getIndexes('conversation_messages'))->pluck('columns');

    expect($indexes)->toContain(['conversation_id', 'id']);
});

test('another account cannot request or read a conversation summary', function (): void {
    $world = conversationCopilotSummaryWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Private support message.']);
    configureConversationCopilotSummary();
    $outsider = User::factory()->for(Account::factory())->create();
    Queue::fake();

    $this->actingAs($outsider)
        ->post(route('dashboard.conversations.copilot-summary.store', $world['conversation']->support_code))
        ->assertNotFound();

    $this->actingAs($outsider)
        ->get(route('dashboard.conversations.copilot-summary.show', $world['conversation']->support_code))
        ->assertNotFound();

    Queue::assertNothingPushed();
});

test('an expired pending marker can be replaced instead of trapping the panel forever', function (): void {
    $world = conversationCopilotSummaryWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Please summarize this.']);
    configureConversationCopilotSummary();
    Carbon::setTestNow('2026-09-06 12:00:00');
    $expired = ConversationCopilotSummary::query()->create([
        'conversation_id' => $world['conversation']->id,
        'requested_by_id' => $world['agent']->id,
        'generation' => (string) Str::uuid(),
        'connection_revision' => app(AgentCopilotConnectionResolver::class)->forAccount((int) $world['account']->id)->revision,
        'status' => ConversationCopilotSummary::STATUS_PENDING,
        'requested_at' => now()->subMinutes(6),
    ]);
    Queue::fake();

    try {
        $this->actingAs($world['agent'])
            ->post(route('dashboard.conversations.copilot-summary.store', $world['conversation']->support_code))
            ->assertSessionHas('status', 'conversations.flash.ai_summary_queued');

        expect($expired->fresh()->generation)->not->toBe($expired->generation)
            ->and($expired->fresh()->hasFreshPendingRequest())->toBeTrue();
        Queue::assertPushed(GenerateConversationCopilotSummary::class, 1);
    } finally {
        Carbon::setTestNow();
    }
});

test('a delayed request cannot be replaced after its worker claims it', function (): void {
    $world = conversationCopilotSummaryWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Please summarize this.']);
    configureConversationCopilotSummary();
    Carbon::setTestNow('2026-09-06 12:00:00');
    $running = ConversationCopilotSummary::query()->create([
        'conversation_id' => $world['conversation']->id,
        'requested_by_id' => $world['agent']->id,
        'generation' => (string) Str::uuid(),
        'connection_revision' => app(AgentCopilotConnectionResolver::class)->forAccount((int) $world['account']->id)->revision,
        'status' => ConversationCopilotSummary::STATUS_RUNNING,
        'requested_at' => now()->subMinutes(10),
        'started_at' => now(),
    ]);
    Queue::fake();

    try {
        expect($running->hasFreshPendingRequest())->toBeTrue()
            ->and($running->displayStatus())->toBe(ConversationCopilotSummary::STATUS_PENDING);

        $this->actingAs($world['agent'])
            ->post(route('dashboard.conversations.copilot-summary.store', $world['conversation']->support_code))
            ->assertSessionHas('status', 'conversations.flash.ai_summary_pending');

        expect($running->fresh()->generation)->toBe($running->generation);
        Queue::assertNothingPushed();
    } finally {
        Carbon::setTestNow();
    }
});

/** Provider callback runs inside the external wait boundary without making a network call. */
function conversationCopilotSummaryRevisionProvider(Closure $callback): AgentCopilotProvider
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

            return new AgentCopilotResult('A generated summary.', 'fake', 'fake');
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('A generation must not run a connection probe.');
        }
    };
}

test('summary generations reject connection changes before and during provider work', function (bool $duringCall, bool $disable, bool $accountDisabled = false): void {
    $world = conversationCopilotSummaryWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Please help with renewal.']);
    configureConversationCopilotSummary();
    $job = queueConversationCopilotSummary($world);
    $requestedRevision = ConversationCopilotSummary::query()->sole()->connection_revision;
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
    $fake = conversationCopilotSummaryRevisionProvider(function () use ($duringCall, $change): void {
        if ($duringCall) {
            $change();
        }
    });
    app()->instance(AgentCopilotProvider::class, $fake);

    app()->call([$job, 'handle']);

    $artifact = ConversationCopilotSummary::query()->sole();
    expect($fake->calls)->toBe($duringCall ? 1 : 0)
        ->and($artifact->status)->toBe(ConversationCopilotSummary::STATUS_FAILED)
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

test('summary pending work coalesces only within the requested connection revision', function (): void {
    $world = conversationCopilotSummaryWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Please help with renewal.']);
    configureConversationCopilotSummary();
    $oldJob = queueConversationCopilotSummary($world);
    $old = ConversationCopilotSummary::query()->sole();
    app(OperatorSettings::class)->set('ai.model', 'replacement-model');

    $this->actingAs($world['agent'])
        ->post(route('dashboard.conversations.copilot-summary.store', $world['conversation']->support_code))
        ->assertRedirect();

    $replacement = $old->fresh();
    expect($replacement->generation)->not->toBe($old->generation)
        ->and($replacement->connection_revision)->not->toBe($old->connection_revision);
    Queue::assertPushed(GenerateConversationCopilotSummary::class, 2);
    $fake = conversationCopilotSummaryRevisionProvider(static function (): void {});
    app()->instance(AgentCopilotProvider::class, $fake);
    app()->call([$oldJob, 'handle']);
    expect($fake->calls)->toBe(0)
        ->and($replacement->fresh()->status)->toBe(ConversationCopilotSummary::STATUS_PENDING);
});

test('summary legacy queued work cannot adopt the current installation connection', function (): void {
    $world = conversationCopilotSummaryWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Please help with renewal.']);
    configureConversationCopilotSummary();
    $job = queueConversationCopilotSummary($world);
    ConversationCopilotSummary::query()->sole()->forceFill(['connection_revision' => null])->save();
    $fake = conversationCopilotSummaryRevisionProvider(static function (): void {});
    app()->instance(AgentCopilotProvider::class, $fake);

    app()->call([$job, 'handle']);

    expect($fake->calls)->toBe(0)
        ->and(ConversationCopilotSummary::query()->sole()->failure_code)->toBe('connection_changed');
});

test('summary output is withheld after connection changes and for unversioned legacy rows', function (bool $legacy): void {
    $world = conversationCopilotSummaryWorld();
    $message = ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Please help with renewal.']);
    configureConversationCopilotSummary();
    $revision = app(AgentCopilotConnectionResolver::class)->forAccount((int) $world['account']->id)->revision;
    ConversationCopilotSummary::query()->create([
        'conversation_id' => $world['conversation']->id,
        'requested_by_id' => $world['agent']->id,
        'generation' => (string) Str::uuid(),
        'connection_revision' => $legacy ? null : $revision,
        'status' => ConversationCopilotSummary::STATUS_READY,
        'summary' => 'A private obsolete summary.',
        'source_last_message_id' => $message->id,
        'source_message_count' => 1,
        'requested_at' => now(),
        'completed_at' => now(),
    ]);
    if (! $legacy) {
        app(OperatorSettings::class)->set('ai.model', 'replacement-model');
    }

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.copilot-summary.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertDontSee('A private obsolete summary.');
    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertDontSee('A private obsolete summary.');
})->with(['superseded revision' => false, 'legacy revision' => true]);

test('summary completion rechecks requester authority after the provider wait', function (): void {
    $world = conversationCopilotSummaryWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Please help with renewal.']);
    configureConversationCopilotSummary();
    $job = queueConversationCopilotSummary($world);
    $fake = conversationCopilotSummaryRevisionProvider(function () use ($world): void {
        $world['agent']->forceFill(['deactivated_at' => now()])->save();
    });
    app()->instance(AgentCopilotProvider::class, $fake);

    app()->call([$job, 'handle']);

    expect($fake->calls)->toBe(1)
        ->and(ConversationCopilotSummary::query()->sole()->status)->toBe(ConversationCopilotSummary::STATUS_FAILED)
        ->and(ConversationCopilotSummary::query()->sole()->failure_code)->toBe('access_revoked');
});

test('summary rotation cannot replace an in-flight claim used by visitor erasure', function (): void {
    $world = conversationCopilotSummaryWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Please help.']);
    configureConversationCopilotSummary();
    queueConversationCopilotSummary($world);
    $artifact = ConversationCopilotSummary::query()->sole();
    $artifact->forceFill([
        'status' => ConversationCopilotSummary::STATUS_RUNNING,
        'started_at' => now(),
    ])->save();
    app(OperatorSettings::class)->set('ai.model', 'replacement-model');

    $this->actingAs($world['agent'])
        ->post(route('dashboard.conversations.copilot-summary.store', $world['conversation']->support_code))
        ->assertRedirect();

    $retained = $artifact->fresh();
    expect($retained->status)->toBe(ConversationCopilotSummary::STATUS_RUNNING)
        ->and($retained->generation)->toBe($artifact->generation)
        ->and($retained->started_at->equalTo($artifact->started_at))->toBeTrue()
        ->and($retained->connection_revision)->toBe($artifact->connection_revision);
    Queue::assertPushed(GenerateConversationCopilotSummary::class, 1);
});
