<?php

declare(strict_types=1);

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Jobs\GenerateConversationCopilotTicketSuggestion;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationCopilotTicketSuggestion;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketLabel;
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
function conversationCopilotTicketSuggestionWorld(): array
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
        'support_code' => 'WF-AITICKET',
        'subject' => 'Billing checkout for ada@example.test',
        'metadata' => ['private_note' => 'Do not export this conversation metadata'],
    ]);

    return compact('account', 'agent', 'site', 'visitor', 'conversation');
}

function configureConversationCopilotTicketSuggestion(): void
{
    $settings = app(OperatorSettings::class);
    $settings->set('ai.provider', 'ollama');
    $settings->set('ai.model', 'qwen3.5:4b');
    $settings->set('ai.endpoint', 'http://localhost:11434');
    $settings->applyOverrides();
}

function queueConversationCopilotTicketSuggestion(array $world): GenerateConversationCopilotTicketSuggestion
{
    Queue::fake();

    test()->actingAs($world['agent'])
        ->post(route('dashboard.conversations.copilot-ticket-suggestion.store', $world['conversation']->support_code))
        ->assertRedirect();

    $queued = null;
    Queue::assertPushed(GenerateConversationCopilotTicketSuggestion::class, function (GenerateConversationCopilotTicketSuggestion $job) use (&$queued): bool {
        $queued = $job;

        return true;
    });

    expect($queued)->toBeInstanceOf(GenerateConversationCopilotTicketSuggestion::class);

    return $queued;
}

test('the ticket suggestion affordance and endpoints disappear when the provider is unconfigured', function (): void {
    $world = conversationCopilotTicketSuggestionWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Checkout is blocked.']);
    Queue::fake();

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertDontSee('Suggested ticket details')
        ->assertDontSee('data-copilot-ticket-suggestion', false);

    $this->actingAs($world['agent'])
        ->post(route('dashboard.conversations.copilot-ticket-suggestion.store', $world['conversation']->support_code))
        ->assertNotFound();

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.copilot-ticket-suggestion.show', $world['conversation']->support_code))
        ->assertNotFound();

    Queue::assertNothingPushed();
    expect(ConversationCopilotTicketSuggestion::query()->count())->toBe(0);
});

test('ticket suggestions require current ticket-management permission', function (): void {
    $world = conversationCopilotTicketSuggestionWorld();
    $viewerRole = CustomRole::factory()->for($world['account'])->create([
        'permissions' => [AccountPermission::ViewConversations->value],
    ]);
    $viewer = User::factory()->for($world['account'])->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $viewerRole->id,
    ]);
    $world['site']->supportAgents()->attach($viewer);
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Private support message.']);
    configureConversationCopilotTicketSuggestion();
    Queue::fake();

    $this->actingAs($viewer)
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertDontSee('data-copilot-ticket-suggestion', false);

    $this->actingAs($viewer)
        ->post(route('dashboard.conversations.copilot-ticket-suggestion.store', $world['conversation']->support_code))
        ->assertNotFound();

    $this->actingAs($viewer)
        ->get(route('dashboard.conversations.copilot-ticket-suggestion.show', $world['conversation']->support_code))
        ->assertNotFound();

    Queue::assertNothingPushed();
});

test('a configured provider exposes an explicitly suggested ticket helper beside editable creation fields', function (): void {
    $world = conversationCopilotTicketSuggestionWorld();
    $label = TicketLabel::factory()->for($world['account'])->create(['name' => 'Billing', 'slug' => 'billing']);
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'The billing checkout is blocked.']);
    configureConversationCopilotTicketSuggestion();

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertSee('Suggested ticket details')
        ->assertSee('Suggest ticket details')
        ->assertSee('Nothing changes until you review and create the ticket.')
        ->assertSee('data-copilot-ticket-suggestion', false)
        ->assertSee('data-ticket-creation-form', false)
        ->assertSee('name="subject"', false)
        ->assertSee('name="label_ids[]"', false)
        ->assertSee('value="'.$label->id.'"', false)
        ->assertSee('window.wayfindrConversationTicketSuggestionTranscriptUpdated', false);
});

test('requesting ticket details queues one id-only job and records safe audit metadata', function (): void {
    $world = conversationCopilotTicketSuggestionWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Checkout is blocked.']);
    configureConversationCopilotTicketSuggestion();
    Queue::fake();
    $returnQuery = [
        'from_queue' => '1',
        'conversation_filter' => 'needs_reply',
        'conversation_search' => 'checkout',
        'conversation_site' => (string) $world['site']->id,
    ];

    $response = $this->actingAs($world['agent'])
        ->post(route('dashboard.conversations.copilot-ticket-suggestion.store', [
            'supportCode' => $world['conversation']->support_code,
            ...$returnQuery,
        ]));

    $response->assertRedirect(route('dashboard.conversations.show', [
        'supportCode' => $world['conversation']->support_code,
        ...$returnQuery,
    ]))->assertSessionHas('status', 'conversations.flash.ai_ticket_suggestion_queued');

    $suggestion = ConversationCopilotTicketSuggestion::query()->sole();
    expect($suggestion->status)->toBe(ConversationCopilotTicketSuggestion::STATUS_PENDING)
        ->and($suggestion->title)->toBeNull()
        ->and($suggestion->requested_by_id)->toBe($world['agent']->id);

    $queuedJob = null;
    Queue::assertPushed(GenerateConversationCopilotTicketSuggestion::class, function (GenerateConversationCopilotTicketSuggestion $job) use (&$queuedJob): bool {
        $queuedJob = $job;

        return true;
    });
    expect(serialize($queuedJob))
        ->not->toContain('Checkout is blocked')
        ->not->toContain('WF-AITICKET');

    $event = AuditEvent::query()->where('action', 'conversation.ai_ticket_suggestion.requested')->sole();
    expect($event->metadata)->toBe([
        'suggestion_id' => $suggestion->id,
        'generation' => $suggestion->generation,
    ]);

    $this->actingAs($world['agent'])
        ->post(route('dashboard.conversations.copilot-ticket-suggestion.store', $world['conversation']->support_code))
        ->assertSessionHas('status', 'conversations.flash.ai_ticket_suggestion_pending');

    Queue::assertPushed(GenerateConversationCopilotTicketSuggestion::class, 1);
});

test('the queued job stores constrained output and matches existing labels locally', function (): void {
    $world = conversationCopilotTicketSuggestionWorld();
    $billing = TicketLabel::factory()->for($world['account'])->create(['name' => 'Billing', 'slug' => 'billing']);
    $needsDev = TicketLabel::factory()->for($world['account'])->create(['name' => 'Needs dev', 'slug' => 'needs-dev']);
    TicketLabel::factory()->for($world['account'])->create(['name' => 'Confidential Queue Name', 'slug' => 'confidential-queue-name']);
    $visitorMessage = ConversationMessage::factory()->for($world['conversation'])->create([
        'sender_type' => Visitor::class,
        'sender_id' => $world['visitor']->id,
        'body' => 'Email ada@example.test {"token":"visitor-secret"}. Billing checkout is blocked and needs-dev.',
        'metadata' => ['private_trace' => 'metadata-must-not-leave'],
        'created_at' => now()->subMinute(),
    ]);
    ConversationMessageAttachment::factory()->forMessage($visitorMessage)->create([
        'original_filename' => 'private-bank-statement.pdf',
    ]);
    $attachmentOnly = ConversationMessage::factory()->for($world['conversation'])->create([
        'sender_type' => Visitor::class,
        'sender_id' => $world['visitor']->id,
        'body' => null,
        'created_at' => now(),
    ]);
    ConversationMessageAttachment::factory()->forMessage($attachmentOnly)->create([
        'original_filename' => 'latest-private-screenshot.png',
    ]);
    configureConversationCopilotTicketSuggestion();
    $job = queueConversationCopilotTicketSuggestion($world);

    $fake = new class implements AgentCopilotProvider
    {
        public ?AgentCopilotPrompt $prompt = null;

        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            $this->prompt = $prompt;

            return new AgentCopilotResult(
                json_encode(['title' => 'Checkout blocked during billing', 'priority' => 'high'], JSON_THROW_ON_ERROR),
                'fake-provider',
                'fake-model',
                140,
                28,
            );
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('The ticket suggestion job must not run a provider probe.');
        }
    };
    app()->instance(AgentCopilotProvider::class, $fake);

    app()->call([$job, 'handle']);

    expect($fake->prompt)->not->toBeNull()
        ->and($fake->prompt->purpose)->toBe('conversation_ticket_suggestion')
        ->and($fake->prompt->timeoutSeconds)->toBe(75)
        ->and($fake->prompt->instructions)->toContain('exactly one JSON object')
        ->and($fake->prompt->input)->toContain('[EMAIL REDACTED]')
        ->and($fake->prompt->input)->not->toContain('visitor-secret')
        ->and($fake->prompt->input)->not->toContain('Private Visitor Name')
        ->and($fake->prompt->input)->not->toContain('private-visitor@example.test')
        ->and($fake->prompt->input)->not->toContain('metadata-must-not-leave')
        ->and($fake->prompt->input)->not->toContain('private-bank-statement.pdf')
        ->and($fake->prompt->input)->not->toContain('latest-private-screenshot.png')
        ->and($fake->prompt->input)->not->toContain('Confidential Queue Name');

    $suggestion = ConversationCopilotTicketSuggestion::query()->sole();
    expect($suggestion->status)->toBe(ConversationCopilotTicketSuggestion::STATUS_READY)
        ->and($suggestion->title)->toBe('Checkout blocked during billing')
        ->and($suggestion->priority)->toBe('high')
        ->and($suggestion->suggestedLabelIds())->toBe([$billing->id, $needsDev->id])
        ->and($suggestion->source_message_count)->toBe(1)
        ->and($suggestion->source_last_message_id)->toBe($attachmentOnly->id)
        ->and($suggestion->provider)->toBe('fake-provider')
        ->and($suggestion->model)->toBe('fake-model');

    $generated = AuditEvent::query()->where('action', 'conversation.ai_ticket_suggestion.generated')->sole();
    expect($generated->metadata['suggested_label_count'])->toBe(2)
        ->and(json_encode($generated->metadata))
        ->not->toContain($suggestion->title)
        ->not->toContain('billing');

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertSee('Checkout blocked during billing')
        ->assertSee('High')
        ->assertSee('Billing')
        ->assertSee('Needs dev')
        ->assertSee('Use suggested details')
        ->assertSee('data-copilot-ticket-suggestion-use', false)
        ->assertSee("form.dataset.ticketSuggestionDirty === 'true'", false)
        ->assertSee('const maximumAttempts = 210;', false);
});

test('invalid structured provider output becomes a retryable failure', function (string $output): void {
    $world = conversationCopilotTicketSuggestionWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Please suggest a ticket.']);
    configureConversationCopilotTicketSuggestion();
    $job = queueConversationCopilotTicketSuggestion($world);

    app()->instance(AgentCopilotProvider::class, new class($output) implements AgentCopilotProvider
    {
        public function __construct(private readonly string $output) {}

        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            return new AgentCopilotResult($this->output, 'fake', 'fake');
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('The ticket suggestion job must not run a provider probe.');
        }
    });

    app()->call([$job, 'handle']);

    $suggestion = ConversationCopilotTicketSuggestion::query()->sole();
    expect($suggestion->status)->toBe(ConversationCopilotTicketSuggestion::STATUS_FAILED)
        ->and($suggestion->failure_code)->toBe('invalid_output')
        ->and($suggestion->title)->toBeNull();
})->with([
    'not json' => 'High priority checkout issue',
    'unknown priority' => '{"title":"Checkout issue","priority":"critical"}',
    'extra key' => '{"title":"Checkout issue","priority":"high","labels":[]}',
    'blank title' => '{"title":" ","priority":"normal"}',
    'title longer than the prompt contract' => json_encode([
        'title' => str_repeat('a', 121),
        'priority' => 'normal',
    ], JSON_THROW_ON_ERROR),
]);

test('provider failures stay generic in storage logs and the agent surface', function (): void {
    $world = conversationCopilotTicketSuggestionWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Please suggest a ticket.']);
    configureConversationCopilotTicketSuggestion();
    $job = queueConversationCopilotTicketSuggestion($world);
    Log::spy();

    app()->instance(AgentCopilotProvider::class, new class implements AgentCopilotProvider
    {
        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            throw new RuntimeException('Bearer provider-secret from https://private-provider.example.test/account/42');
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('The ticket suggestion job must not run a provider probe.');
        }
    });

    app()->call([$job, 'handle']);

    expect(ConversationCopilotTicketSuggestion::query()->sole()->failure_code)->toBe('provider');
    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Conversation copilot ticket suggestion generation failed.', Mockery::on(
            fn (array $context): bool => ($context['exception_type'] ?? null) === RuntimeException::class
                && ! str_contains(json_encode($context), 'provider-secret')
                && ! str_contains(json_encode($context), 'private-provider.example.test')
        ));

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertOk()
        ->assertSee('The ticket details could not be suggested.')
        ->assertDontSee('provider-secret');
});

test('the worker rechecks ticket-management permission before sending transcript text', function (): void {
    $world = conversationCopilotTicketSuggestionWorld();
    $ticketRole = CustomRole::factory()->for($world['account'])->create([
        'permissions' => [
            AccountPermission::ViewConversations->value,
            AccountPermission::ManageTickets->value,
        ],
    ]);
    $world['agent']->forceFill([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $ticketRole->id,
    ])->save();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Private support message.']);
    configureConversationCopilotTicketSuggestion();
    $job = queueConversationCopilotTicketSuggestion($world);
    $ticketRole->forceFill(['permissions' => [AccountPermission::ViewConversations->value]])->save();

    $fake = new class implements AgentCopilotProvider
    {
        public bool $called = false;

        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            $this->called = true;

            return new AgentCopilotResult('{"title":"Should not run","priority":"normal"}', 'fake', 'fake');
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('The ticket suggestion job must not run a provider probe.');
        }
    };
    app()->instance(AgentCopilotProvider::class, $fake);

    app()->call([$job, 'handle']);

    expect($fake->called)->toBeFalse()
        ->and(ConversationCopilotTicketSuggestion::query()->sole()->failure_code)->toBe('access_revoked');
});

test('an already-created ticket removes the helper and stops queued provider delivery', function (): void {
    $world = conversationCopilotTicketSuggestionWorld();
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Private support message.']);
    configureConversationCopilotTicketSuggestion();
    $job = queueConversationCopilotTicketSuggestion($world);
    Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($world['conversation'])
        ->for($world['visitor'], 'requester')
        ->create();

    $fake = new class implements AgentCopilotProvider
    {
        public bool $called = false;

        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            $this->called = true;

            return new AgentCopilotResult('{"title":"Should not run","priority":"normal"}', 'fake', 'fake');
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('The ticket suggestion job must not run a provider probe.');
        }
    };
    app()->instance(AgentCopilotProvider::class, $fake);

    app()->call([$job, 'handle']);

    expect($fake->called)->toBeFalse()
        ->and(ConversationCopilotTicketSuggestion::query()->sole()->failure_code)->toBe('ticket_exists');

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.copilot-ticket-suggestion.show', $world['conversation']->support_code))
        ->assertNoContent();
});

test('a new attachment-only message visibly stales a ticket suggestion and blocks form insertion', function (): void {
    $world = conversationCopilotTicketSuggestionWorld();
    $source = ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Can you help?']);
    configureConversationCopilotTicketSuggestion();
    ConversationCopilotTicketSuggestion::query()->create([
        'conversation_id' => $world['conversation']->id,
        'requested_by_id' => $world['agent']->id,
        'generation' => (string) Str::uuid(),
        'status' => ConversationCopilotTicketSuggestion::STATUS_READY,
        'title' => 'Help requested',
        'priority' => 'normal',
        'label_ids' => [],
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
        ->assertSee('New messages arrived after this suggestion.')
        ->assertSee('data-copilot-ticket-suggestion-stale', false)
        ->assertSee('data-copilot-ticket-suggestion-use', false)
        ->assertSee('disabled', false)
        ->assertSee('useButton.disabled = true', false);
});

test('realtime message events invalidate ticket suggestions before refreshing the transcript', function (): void {
    $source = file_get_contents(resource_path('views/agent/conversations/show.blade.php'));
    $messageEventHandler = Str::between(
        $source,
        'if (event.event === config.messageEventName) {',
        'if (event.event === config.typingEventName)',
    );
    $invalidateAt = strpos($messageEventHandler, 'window.wayfindrConversationTicketSuggestionTranscriptUpdated();');
    $refreshAt = strpos($messageEventHandler, 'refreshTranscript();');

    expect($invalidateAt)->toBeInt()
        ->and($refreshAt)->toBeInt()
        ->and($invalidateAt)->toBeLessThan($refreshAt);
});

test('ticket creation accepts an edited title and existing account labels atomically', function (): void {
    $world = conversationCopilotTicketSuggestionWorld();
    $billing = TicketLabel::factory()->for($world['account'])->create(['name' => 'Billing', 'slug' => 'billing']);
    $vip = TicketLabel::factory()->for($world['account'])->create(['name' => 'VIP', 'slug' => 'vip']);
    ConversationMessage::factory()->for($world['conversation'])->create(['body' => 'Checkout is blocked.']);

    $this->actingAs($world['agent'])
        ->post(route('dashboard.conversations.tickets.store', $world['conversation']->support_code), [
            'subject' => 'Checkout blocked for renewal',
            'priority' => 'high',
            'category' => 'billing',
            'label_ids' => [$billing->id, $vip->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'conversations.flash.ticket_created');

    $ticket = Ticket::query()->sole();
    expect($ticket->subject)->toBe('Checkout blocked for renewal')
        ->and($ticket->priority)->toBe('high')
        ->and($ticket->labels()->pluck('ticket_labels.id')->sort()->values()->all())
        ->toBe(collect([$billing->id, $vip->id])->sort()->values()->all())
        ->and($ticket->auditEvents()->where('action', 'ticket.label_added')->count())->toBe(2);
});

test('ticket creation rejects labels from another account without creating a ticket', function (): void {
    $world = conversationCopilotTicketSuggestionWorld();
    $otherLabel = TicketLabel::factory()->for(Account::factory())->create();

    $this->actingAs($world['agent'])
        ->from(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->post(route('dashboard.conversations.tickets.store', $world['conversation']->support_code), [
            'subject' => 'Safe title',
            'priority' => 'normal',
            'label_ids' => [$otherLabel->id],
        ])
        ->assertRedirect(route('dashboard.conversations.show', $world['conversation']->support_code))
        ->assertSessionHasErrors('label_ids.0');

    expect(Ticket::query()->count())->toBe(0);
});
