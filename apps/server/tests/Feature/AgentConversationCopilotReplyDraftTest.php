<?php

declare(strict_types=1);

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Jobs\GenerateConversationCopilotReplyDraft;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationCopilotReplyDraft;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Ai\AgentCopilotPrompt;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Settings\OperatorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array{account: Account, agent: User, site: Site, visitor: Visitor, conversation: Conversation} */
function conversationCopilotReplyDraftWorld(): array
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
        'support_code' => 'WF-AIDRAFT',
        'subject' => 'Checkout help for ada@example.test',
        'metadata' => ['private_note' => 'Do not export this conversation metadata'],
    ]);

    return compact('account', 'agent', 'site', 'visitor', 'conversation');
}

function configureConversationCopilotReplyDraft(): void
{
    $settings = app(OperatorSettings::class);
    $settings->set('ai.provider', 'ollama');
    $settings->set('ai.model', 'qwen3.5:4b');
    $settings->set('ai.endpoint', 'http://localhost:11434');
    $settings->applyOverrides();
}

function queueConversationCopilotReplyDraft(array $world): GenerateConversationCopilotReplyDraft
{
    Queue::fake();

    test()->actingAs($world['agent'])
        ->post(route('dashboard.conversations.copilot-reply-draft.store', $world['conversation']->support_code))
        ->assertRedirect();

    $queued = null;
    Queue::assertPushed(GenerateConversationCopilotReplyDraft::class, function (GenerateConversationCopilotReplyDraft $job) use (&$queued): bool {
        $queued = $job;

        return true;
    });

    expect($queued)->toBeInstanceOf(GenerateConversationCopilotReplyDraft::class);

    return $queued;
}

test('the reply draft affordance and endpoints disappear when the provider is unconfigured', function (): void {
    $world = conversationCopilotReplyDraftWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'The checkout button is stuck.']);
    Queue::fake();

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertDontSee('Suggested reply')
        ->assertDontSee('data-copilot-reply-draft', false);

    $this->actingAs($world['agent'])
        ->post(route('dashboard.conversations.copilot-reply-draft.store', $world['conversation']->support_code))
        ->assertNotFound();

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.copilot-reply-draft.show', $world['conversation']->support_code))
        ->assertNotFound();

    Queue::assertNothingPushed();
    expect(ConversationCopilotReplyDraft::query()->count())->toBe(0);
});

test('reply suggestions require current reply permission even when the transcript is visible', function (): void {
    $world = conversationCopilotReplyDraftWorld();
    $viewerRole = CustomRole::factory()->for($world['account'])->create([
        'permissions' => [AccountPermission::ViewConversations->value],
    ]);
    $viewer = User::factory()->for($world['account'])->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $viewerRole->id,
    ]);
    $world['site']->supportAgents()->attach($viewer);
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Private support message.']);
    configureConversationCopilotReplyDraft();
    Queue::fake();

    $this->actingAs($viewer)
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertDontSee('data-copilot-reply-draft', false);

    $this->actingAs($viewer)
        ->post(route('dashboard.conversations.copilot-reply-draft.store', $world['conversation']->support_code))
        ->assertNotFound();

    $this->actingAs($viewer)
        ->get(route('dashboard.conversations.copilot-reply-draft.show', $world['conversation']->support_code))
        ->assertNotFound();

    Queue::assertNothingPushed();
});

test('a configured provider exposes an explicitly suggested on-demand reply draft', function (): void {
    $world = conversationCopilotReplyDraftWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'The checkout button is stuck.']);
    configureConversationCopilotReplyDraft();

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertSee('Suggested reply')
        ->assertSee('Draft a reply')
        ->assertSee('You review and send every reply.')
        ->assertSee('data-copilot-reply-draft', false)
        ->assertSee('window.wayfindrConversationReplyDraftTranscriptUpdated', false);
});

test('requesting a reply draft queues one id-only job and records safe audit metadata', function (): void {
    $world = conversationCopilotReplyDraftWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'The checkout button is stuck.']);
    configureConversationCopilotReplyDraft();
    Queue::fake();
    $returnQuery = [
        'from_queue' => '1',
        'conversation_filter' => 'needs_reply',
        'conversation_search' => 'checkout',
        'conversation_site' => (string) $world['site']->id,
    ];

    $response = $this->actingAs($world['agent'])
        ->post(route('dashboard.conversations.copilot-reply-draft.store', [
            'supportCode' => $world['conversation']->support_code,
            ...$returnQuery,
        ]));

    $response->assertRedirect(route('dashboard.conversations.show', [
        'supportCode' => $world['conversation']->support_code,
        'from_queue' => '1',
        'conversation_filter' => 'needs_reply',
        'conversation_search' => 'checkout',
        'conversation_site' => $world['site']->id,
    ]))->assertSessionHas('status', 'conversations.flash.ai_reply_draft_queued');

    $draft = ConversationCopilotReplyDraft::query()->sole();
    expect($draft->status)->toBe(ConversationCopilotReplyDraft::STATUS_PENDING)
        ->and($draft->draft)->toBeNull()
        ->and($draft->requested_by_id)->toBe($world['agent']->id);

    $queuedJob = null;
    Queue::assertPushed(GenerateConversationCopilotReplyDraft::class, function (GenerateConversationCopilotReplyDraft $job) use (&$queuedJob): bool {
        $queuedJob = $job;

        return true;
    });
    expect(serialize($queuedJob))
        ->not->toContain('checkout button')
        ->not->toContain('WF-AIDRAFT');

    $event = AuditEvent::query()->where('action', 'conversation.ai_reply_draft.requested')->sole();
    expect($event->metadata)->toBe([
        'draft_id' => $draft->id,
        'generation' => $draft->generation,
    ])->and(json_encode($event->metadata))->not->toContain('checkout button');

    $this->actingAs($world['agent'])
        ->post(route('dashboard.conversations.copilot-reply-draft.store', $world['conversation']->support_code))
        ->assertSessionHas('status', 'conversations.flash.ai_reply_draft_pending');

    Queue::assertPushed(GenerateConversationCopilotReplyDraft::class, 1);
});

test('the queued job sends scrubbed bounded text and stores an editable suggestion', function (): void {
    $world = conversationCopilotReplyDraftWorld();
    $visitorMessage = ConversationMessage::factory()->for($world['conversation'])->create([
        'sender_type' => Visitor::class,
        'sender_id' => $world['visitor']->id,
        'body' => 'Email ada@example.test {"token":"visitor-secret"}. Checkout stalls after payment.',
        'metadata' => ['private_trace' => 'metadata-must-not-leave'],
        'created_at' => now()->subMinute(),
    ]);
    ConversationMessageAttachment::factory()->forMessage($visitorMessage)->create([
        'original_filename' => 'private-bank-statement.pdf',
    ]);
    ConversationMessage::factory()->for($world['conversation'])->create([
        'sender_type' => User::class,
        'sender_id' => $world['agent']->id,
        'body' => 'Asked the visitor to retry in a private window.',
        'created_at' => now(),
    ]);
    $attachmentOnly = ConversationMessage::factory()->for($world['conversation'])->create([
        'sender_type' => Visitor::class,
        'sender_id' => $world['visitor']->id,
        'body' => null,
        'created_at' => now()->addSecond(),
    ]);
    ConversationMessageAttachment::factory()->forMessage($attachmentOnly)->create([
        'original_filename' => 'latest-private-screenshot.png',
    ]);
    configureConversationCopilotReplyDraft();
    $job = queueConversationCopilotReplyDraft($world);

    $fake = new class implements AgentCopilotProvider
    {
        public ?AgentCopilotPrompt $prompt = null;

        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            $this->prompt = $prompt;

            return new AgentCopilotResult(
                'Thanks for trying that. Could you tell me whether checkout still stalls after payment?',
                'fake-provider',
                'fake-model',
                120,
                24,
            );
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('The reply draft job must not run a provider probe.');
        }
    };
    app()->instance(AgentCopilotProvider::class, $fake);

    app()->call([$job, 'handle']);

    expect($fake->prompt)->not->toBeNull()
        ->and($fake->prompt->purpose)->toBe('conversation_reply_draft')
        ->and($fake->prompt->timeoutSeconds)->toBe(75)
        ->and($fake->prompt->instructions)->toContain('Output only the editable reply body')
        ->and($fake->prompt->input)->toContain('[EMAIL REDACTED]')
        ->and($fake->prompt->input)->toContain('private window')
        ->and($fake->prompt->input)->not->toContain('visitor-secret')
        ->and($fake->prompt->input)->not->toContain('Private Visitor Name')
        ->and($fake->prompt->input)->not->toContain('private-visitor@example.test')
        ->and($fake->prompt->input)->not->toContain('metadata-must-not-leave')
        ->and($fake->prompt->input)->not->toContain('private-bank-statement.pdf')
        ->and($fake->prompt->input)->not->toContain('latest-private-screenshot.png');

    $draft = ConversationCopilotReplyDraft::query()->sole();
    expect($draft->status)->toBe(ConversationCopilotReplyDraft::STATUS_READY)
        ->and($draft->source_message_count)->toBe(2)
        ->and($draft->source_last_message_id)->toBe($attachmentOnly->id)
        ->and($draft->provider)->toBe('fake-provider')
        ->and($draft->model)->toBe('fake-model')
        ->and($draft->prompt_tokens)->toBe(120)
        ->and($draft->completion_tokens)->toBe(24);

    $generated = AuditEvent::query()->where('action', 'conversation.ai_reply_draft.generated')->sole();
    expect(json_encode($generated->metadata))
        ->not->toContain($draft->draft)
        ->not->toContain('checkout');

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertSee($draft->draft)
        ->assertSee('Use suggested draft')
        ->assertSee('data-copilot-reply-draft-use', false)
        ->assertSee('body.value.trim() !==', false)
        ->assertSee("body.dispatchEvent(new Event('input'", false)
        ->assertSee('const maximumAttempts = 180;', false);
});

test('a new attachment-only message visibly stales a reply suggestion and blocks composer insertion', function (): void {
    $world = conversationCopilotReplyDraftWorld();
    $source = ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Can you help?']);
    configureConversationCopilotReplyDraft();
    ConversationCopilotReplyDraft::query()->create([
        'conversation_id' => $world['conversation']->id,
        'requested_by_id' => $world['agent']->id,
        'generation' => (string) Str::uuid(),
        'status' => ConversationCopilotReplyDraft::STATUS_READY,
        'draft' => 'Absolutely. What happened?',
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
        ->assertSee('New messages arrived after this draft.')
        ->assertSee('data-copilot-reply-draft-stale', false)
        ->assertSee('data-copilot-reply-draft-use', false)
        ->assertSee('disabled', false)
        ->assertSee('useButton.disabled = true', false);
});

test('provider failures are generic in storage logs and the agent surface', function (): void {
    $world = conversationCopilotReplyDraftWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Please draft a reply.']);
    configureConversationCopilotReplyDraft();
    $job = queueConversationCopilotReplyDraft($world);
    Log::spy();

    app()->instance(AgentCopilotProvider::class, new class implements AgentCopilotProvider
    {
        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            throw new RuntimeException('Bearer provider-secret from https://private-provider.example.test/account/42');
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('The reply draft job must not run a provider probe.');
        }
    });

    app()->call([$job, 'handle']);

    $draft = ConversationCopilotReplyDraft::query()->sole();
    expect($draft->status)->toBe(ConversationCopilotReplyDraft::STATUS_FAILED)
        ->and($draft->failure_code)->toBe('provider')
        ->and($draft->draft)->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Conversation copilot reply draft generation failed.', Mockery::on(
            fn (array $context): bool => ($context['exception_type'] ?? null) === RuntimeException::class
                && ! str_contains(json_encode($context), 'provider-secret')
                && ! str_contains(json_encode($context), 'private-provider.example.test')
        ));

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertSee('The reply draft could not be generated.')
        ->assertSee('Try draft again')
        ->assertDontSee('provider-secret');
});

test('an empty provider response becomes a retryable failure instead of a blank suggestion', function (): void {
    $world = conversationCopilotReplyDraftWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Please draft a reply.']);
    configureConversationCopilotReplyDraft();
    $job = queueConversationCopilotReplyDraft($world);

    app()->instance(AgentCopilotProvider::class, new class implements AgentCopilotProvider
    {
        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            return new AgentCopilotResult(" \n ", 'fake', 'fake');
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('The reply draft job must not run a provider probe.');
        }
    });

    app()->call([$job, 'handle']);

    $draft = ConversationCopilotReplyDraft::query()->sole();
    expect($draft->status)->toBe(ConversationCopilotReplyDraft::STATUS_FAILED)
        ->and($draft->failure_code)->toBe('empty_output')
        ->and($draft->draft)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'conversation.ai_reply_draft.failed')->sole()->metadata['failure_code'])
        ->toBe('empty_output');
});

test('another account cannot request or read a conversation reply draft', function (): void {
    $world = conversationCopilotReplyDraftWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Private support message.']);
    configureConversationCopilotReplyDraft();
    $outsider = User::factory()->for(Account::factory())->create();
    Queue::fake();

    $this->actingAs($outsider)
        ->post(route('dashboard.conversations.copilot-reply-draft.store', $world['conversation']->support_code))
        ->assertNotFound();

    $this->actingAs($outsider)
        ->get(route('dashboard.conversations.copilot-reply-draft.show', $world['conversation']->support_code))
        ->assertNotFound();

    Queue::assertNothingPushed();
});

test('the worker rechecks reply permission before sending transcript text', function (): void {
    $world = conversationCopilotReplyDraftWorld();
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
    configureConversationCopilotReplyDraft();
    $job = queueConversationCopilotReplyDraft($world);
    $replyRole->forceFill(['permissions' => [AccountPermission::ViewConversations->value]])->save();

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
            throw new LogicException('The reply draft job must not run a provider probe.');
        }
    };
    app()->instance(AgentCopilotProvider::class, $fake);

    app()->call([$job, 'handle']);

    expect($fake->called)->toBeFalse()
        ->and(ConversationCopilotReplyDraft::query()->sole()->failure_code)->toBe('access_revoked');
});
