<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorIdentityAlias;
use App\Models\VisitorNote;
use App\Support\Attachments\Scanning\AttachmentScanner;
use App\Support\Attachments\Scanning\ScanResult;
use App\Support\VisitorConversationResolver;
use App\Support\Visitors\VisitorIdentityMerger;
use App\Support\Visitors\VisitorIdentityResolver;
use App\Support\VisitorSessionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function mergeAfterVisitorConversationResolution(User $manager, Visitor $source, Visitor $target): void
{
    app()->extend(VisitorConversationResolver::class, fn ($resolver) => new class($resolver, app(VisitorIdentityMerger::class), $manager, $source, $target) extends VisitorConversationResolver
    {
        public function __construct(
            private $inner,
            private VisitorIdentityMerger $merger,
            private User $manager,
            private Visitor $source,
            private Visitor $target,
        ) {}

        public function resolve(Request $request, string $supportCode, string $sitePublicKey, string $anonymousId): Conversation
        {
            $conversation = $this->inner->resolve($request, $supportCode, $sitePublicKey, $anonymousId);
            $this->merger->merge($this->manager, $this->source, (int) $this->target->id);

            return $conversation;
        }
    });
}

test('a contact manager can find a same-site contact to keep', function (): void {
    $account = Account::factory()->create();
    $manager = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();
    $source = Visitor::factory()->for($site)->create(['name' => 'Duplicate Contact']);
    $target = Visitor::factory()->for($site)->create([
        'name' => 'River Canonical',
        'email' => 'river@example.test',
    ]);
    $foreign = Visitor::factory()->for(Site::factory()->for($account))->create(['name' => 'River Elsewhere']);

    $this->actingAs($manager)
        ->get(route('dashboard.visitors.show', [$source, 'merge_search' => 'River']))
        ->assertOk()
        ->assertSee('Merge duplicate contact')
        ->assertSee('River Canonical')
        ->assertSee('river@example.test')
        ->assertSee(route('dashboard.visitors.merge', $source), false)
        ->assertDontSee('River Elsewhere');

    expect($target->site_id)->toBe($site->id)
        ->and($foreign->site_id)->not->toBe($site->id);
});

test('a merge moves person-owned support records and retains canonical values', function (): void {
    $account = Account::factory()->create();
    $manager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Manager',
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Docs']);
    $source = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-duplicate',
        'external_id' => null,
        'name' => 'Duplicate Name',
        'email' => 'river@example.test',
        'metadata' => [
            'context' => ['plan' => 'Starter', 'region' => 'EU'],
            'last_page_url' => 'https://docs.example.test/latest',
        ],
        'last_seen_at' => Carbon::parse('2026-09-05 14:00:00 UTC'),
        'last_web_seen_at' => Carbon::parse('2026-09-05 14:00:00 UTC'),
        'current_visit_started_at' => Carbon::parse('2026-09-05 13:30:00 UTC'),
        'created_at' => Carbon::parse('2026-08-01 12:00:00 UTC'),
    ]);
    $target = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-canonical',
        'external_id' => 'customer-42',
        'name' => 'River Canonical',
        'email' => null,
        'metadata' => [
            'context' => ['plan' => 'Team'],
            'last_page_url' => 'https://docs.example.test/older',
        ],
        'last_seen_at' => Carbon::parse('2026-09-04 14:00:00 UTC'),
        'last_web_seen_at' => Carbon::parse('2026-09-04 14:00:00 UTC'),
        'current_visit_started_at' => Carbon::parse('2026-09-04 13:45:00 UTC'),
        'created_at' => Carbon::parse('2026-08-10 12:00:00 UTC'),
    ]);
    DB::table('visitors')->where('id', $source->id)->update([
        'current_visit_started_at' => Carbon::parse('2026-09-05 13:30:00 UTC'),
    ]);
    $source->refresh();
    VisitorIdentityAlias::query()->create([
        'site_id' => $site->id,
        'visitor_id' => $source->id,
        'anonymous_id' => 'anon-duplicate-older',
    ]);
    $conversation = Conversation::factory()->for($site)->for($source)->create(['subject' => 'Merged conversation']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($source, 'requester')
        ->create(['subject' => 'Merged ticket']);
    $cobrowse = CobrowseSession::factory()->for($conversation)->create([
        'site_id' => $site->id,
        'visitor_id' => $source->id,
    ]);
    $note = VisitorNote::factory()->create([
        'account_id' => $account->id,
        'visitor_id' => $source->id,
        'author_id' => $manager->id,
        'body' => 'Private continuity note',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $source->id,
    ]);
    $attachment = ConversationMessageAttachment::factory()->pendingFor($conversation, $source)->create();
    $visitorSubjectEvent = AuditEvent::query()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => $manager->getMorphClass(),
        'actor_id' => $manager->id,
        'subject_type' => $source->getMorphClass(),
        'subject_id' => $source->id,
        'action' => 'visitor.note_added',
        'metadata' => ['note_id' => $note->id],
        'occurred_at' => now(),
    ]);
    $visitorActorEvent = AuditEvent::query()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => $source->getMorphClass(),
        'actor_id' => $source->id,
        'subject_type' => $conversation->getMorphClass(),
        'subject_id' => $conversation->id,
        'action' => 'cobrowse.snapshot_received',
        'metadata' => [],
        'occurred_at' => now(),
    ]);

    $this->actingAs($manager)
        ->post(route('dashboard.visitors.merge', $source), [
            'target_id' => (string) $target->id,
            'confirmed' => '1',
        ])
        ->assertRedirect(route('dashboard.visitors.show', $target).'#visitor-merge-heading')
        ->assertSessionHas('status', 'visitor_merge.flash.merged');

    $target->refresh();

    expect(Visitor::query()->find($source->id))->toBeNull()
        ->and($target->external_id)->toBe('customer-42')
        ->and($target->name)->toBe('River Canonical')
        ->and($target->email)->toBe('river@example.test')
        ->and($target->metadata)->toBe([
            'context' => ['plan' => 'Team', 'region' => 'EU'],
            'last_page_url' => 'https://docs.example.test/latest',
        ])
        ->and($target->last_seen_at?->toDateTimeString())->toBe('2026-09-05 14:00:00')
        ->and($target->last_web_seen_at?->toDateTimeString())->toBe('2026-09-05 14:00:00')
        ->and($target->current_visit_started_at?->toDateTimeString())->toBe('2026-09-05 13:30:00')
        ->and($target->created_at?->toDateTimeString())->toBe('2026-08-01 12:00:00')
        ->and($target->presence_only)->toBeFalse()
        ->and($conversation->fresh()?->visitor_id)->toBe($target->id)
        ->and($ticket->fresh()?->requester_id)->toBe($target->id)
        ->and($cobrowse->fresh()?->visitor_id)->toBe($target->id)
        ->and($note->fresh()?->visitor_id)->toBe($target->id)
        ->and($message->fresh()?->sender_id)->toBe($target->id)
        ->and($attachment->fresh()?->uploaded_by_id)->toBe($target->id)
        ->and($visitorSubjectEvent->fresh()?->subject_id)->toBe($target->id)
        ->and($visitorSubjectEvent->fresh()?->subject?->is($target))->toBeTrue()
        ->and($visitorActorEvent->fresh()?->actor_id)->toBe($target->id)
        ->and($visitorActorEvent->fresh()?->actor?->is($target))->toBeTrue()
        ->and(VisitorIdentityAlias::query()->where('visitor_id', $target->id)->pluck('anonymous_id')->sort()->values()->all())
        ->toBe(['anon-duplicate', 'anon-duplicate-older']);

    expect(VisitorIdentityAlias::query()
        ->where('visitor_id', $target->id)
        ->get()
        ->every(fn (VisitorIdentityAlias $alias): bool => $alias->previous_visitor_ids === [$source->id]))
        ->toBeTrue();

    $event = AuditEvent::query()->where('action', 'visitor.merged')->sole();

    expect($event->subject_type)->toBe(Visitor::class)
        ->and($event->subject_id)->toBe($target->id)
        ->and($event->metadata)->toBe([
            'source_visitor_id' => $source->id,
            'destination_visitor_id' => $target->id,
            'moved' => [
                'conversations' => 1,
                'tickets' => 1,
                'cobrowse_sessions' => 1,
                'contact_notes' => 1,
                'messages' => 1,
                'attachments' => 1,
                'audit_subjects' => 1,
                'audit_actors' => 1,
            ],
        ])
        ->and(json_encode($event->metadata, JSON_THROW_ON_ERROR))
        ->not->toContain('river@example.test', 'Private continuity note', 'customer-42');
});

test('merged browser identities continue through presence bootstrap and old signed tokens', function (): void {
    $account = Account::factory()->create();
    $manager = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create([
        'settings' => ['presence' => ['enabled' => true, 'page_urls' => false]],
    ]);
    $source = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-old-tab',
        'name' => 'Known from intake',
    ]);
    $target = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-canonical-tab',
        'external_id' => 'customer-tab',
    ]);
    $oldToken = app(VisitorSessionToken::class)->issue($site, $source);

    $this->actingAs($manager)
        ->post(route('dashboard.visitors.merge', $source), [
            'target_id' => (string) $target->id,
            'confirmed' => '1',
        ])
        ->assertRedirect();

    $this->postJson('/api/widget/presence', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-old-tab',
    ])->assertAccepted();

    $bootstrap = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-old-tab',
        'external_id' => 'customer-tab',
    ])->assertOk()
        ->assertJsonPath('data.visitor.anonymous_id', 'anon-old-tab')
        ->assertJsonPath('data.visitor.identified', true);

    $canonicalToken = (string) $bootstrap->json('data.visitor.token');
    $finalTarget = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-final-tab',
        'external_id' => null,
    ]);

    $this->actingAs($manager)
        ->post(route('dashboard.visitors.merge', $target), [
            'target_id' => (string) $finalTarget->id,
            'confirmed' => '1',
        ])
        ->assertRedirect();

    // Bootstrap issues its response token after releasing the site lock. If a
    // second merge lands in that narrow window, the stale canonical model is
    // still valid lineage for the alias and must be allowed to mint the token.
    $tokenIssuedFromStaleCanonical = app(VisitorSessionToken::class)
        ->issue($site, $target, 'anon-old-tab');

    $this->postJson('/api/conversations', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-old-tab',
        'visitor_token' => $oldToken,
        'subject' => 'Old tab still works',
    ])->assertCreated();

    $this->postJson('/api/conversations', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-old-tab',
        'visitor_token' => $canonicalToken,
        'subject' => 'Token refreshed after the first merge still works',
    ])->assertCreated();

    $this->postJson('/api/conversations', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-old-tab',
        'visitor_token' => $tokenIssuedFromStaleCanonical,
        'subject' => 'A token issued across the second merge still works',
    ])->assertCreated();

    $alias = VisitorIdentityAlias::query()->where('anonymous_id', 'anon-old-tab')->sole();

    expect(Visitor::query()->count())->toBe(1)
        ->and(Conversation::query()->where('visitor_id', $finalTarget->id)->count())->toBe(3)
        ->and($alias->visitor_id)->toBe($finalTarget->id)
        ->and($alias->previous_visitor_ids)->toBe([$source->id, $target->id]);

    $finalTarget->delete();

    expect(VisitorIdentityAlias::query()->count())->toBe(0);

    Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-old-tab']);

    $this->postJson('/api/conversations', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-old-tab',
        'visitor_token' => $oldToken,
        'subject' => 'A deleted lineage cannot authenticate a replacement',
    ])->assertUnauthorized();
});

test('a conversation that loses the merge race follows the alias instead of recreating the duplicate', function (): void {
    $account = Account::factory()->create();
    $manager = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();
    $source = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-racing-conversation']);
    $target = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-racing-canonical']);

    $sessionTokens = Mockery::mock(VisitorSessionToken::class, [app(VisitorIdentityResolver::class)])
        ->makePartial();
    $sessionTokens->shouldReceive('visitorFromRequest')
        ->once()
        ->andReturnUsing(function (Request $request, Site $resolvedSite, string $anonymousId) use ($manager, $source, $target): Visitor {
            expect($resolvedSite->is($source->site))->toBeTrue()
                ->and($anonymousId)->toBe('anon-racing-conversation');

            app(VisitorIdentityMerger::class)->merge($manager, $source, (int) $target->id);

            // This is the copy token validation resolved before the merge.
            return $source;
        });
    $this->app->instance(VisitorSessionToken::class, $sessionTokens);

    $this->postJson('/api/conversations', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-racing-conversation',
        'visitor_token' => 'resolved-before-merge',
        'subject' => 'Do not recreate me',
    ])->assertCreated();

    expect(Visitor::query()->pluck('id')->all())->toBe([$target->id])
        ->and(Conversation::query()->sole()->visitor_id)->toBe($target->id)
        ->and(VisitorIdentityAlias::query()->sole()->visitor_id)->toBe($target->id);
});

test('a message that loses the merge race uses the canonical sender and pending upload', function (): void {
    $account = Account::factory()->create();
    $manager = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();
    $source = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-racing-message']);
    $target = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-racing-message-canonical']);
    $conversation = Conversation::factory()->for($site)->for($source)->create(['support_code' => 'WF-MERGERACE1']);
    $attachment = ConversationMessageAttachment::factory()->pendingFor($conversation, $source)->create();
    $token = app(VisitorSessionToken::class)->issue($site, $source);

    mergeAfterVisitorConversationResolution($manager, $source, $target);

    $this->postJson('/api/conversations/WF-MERGERACE1/messages', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-racing-message',
        'visitor_token' => $token,
        'body' => 'Follow the merge.',
        'attachment_ids' => [$attachment->id],
    ])->assertCreated();

    $message = ConversationMessage::query()->sole();

    expect(Visitor::query()->pluck('id')->all())->toBe([$target->id])
        ->and($conversation->fresh()?->visitor_id)->toBe($target->id)
        ->and($message->sender_id)->toBe($target->id)
        ->and($attachment->fresh()?->uploaded_by_id)->toBe($target->id)
        ->and($attachment->fresh()?->conversation_message_id)->toBe($message->id);
});

test('an upload that loses the merge race belongs to the canonical visitor', function (): void {
    Storage::fake('attachments');

    $account = Account::factory()->create();
    $manager = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();
    $source = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-racing-upload']);
    $target = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-racing-upload-canonical']);
    $conversation = Conversation::factory()->for($site)->for($source)->create(['support_code' => 'WF-MERGERACE2']);
    $token = app(VisitorSessionToken::class)->issue($site, $source);

    mergeAfterVisitorConversationResolution($manager, $source, $target);

    $this->post('/api/conversations/WF-MERGERACE2/attachments', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-racing-upload',
        'visitor_token' => $token,
        'file' => UploadedFile::fake()->image('merge-race.png'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $attachment = ConversationMessageAttachment::query()->sole();

    expect(Visitor::query()->pluck('id')->all())->toBe([$target->id])
        ->and($conversation->fresh()?->visitor_id)->toBe($target->id)
        ->and($attachment->uploaded_by_id)->toBe($target->id);
    Storage::disk('attachments')->assertExists($attachment->storage_key);
});

test('a rejected upload that loses the merge race audits the canonical visitor', function (): void {
    Storage::fake('attachments');
    app()->instance(AttachmentScanner::class, new class implements AttachmentScanner
    {
        public function scan(string $path): ScanResult
        {
            return ScanResult::infected('Test.Merge.Race');
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function isAvailable(): bool
        {
            return true;
        }
    });

    $account = Account::factory()->create();
    $manager = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();
    $source = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-racing-rejected-upload']);
    $target = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-racing-rejected-canonical']);
    $conversation = Conversation::factory()->for($site)->for($source)->create(['support_code' => 'WF-MERGERACE3']);
    $token = app(VisitorSessionToken::class)->issue($site, $source);

    mergeAfterVisitorConversationResolution($manager, $source, $target);

    $this->post('/api/conversations/WF-MERGERACE3/attachments', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-racing-rejected-upload',
        'visitor_token' => $token,
        'file' => UploadedFile::fake()->image('infected.png'),
    ], ['Accept' => 'application/json'])->assertUnprocessable();

    $event = AuditEvent::query()->where('action', 'attachment.quarantined')->sole();

    expect(Visitor::query()->pluck('id')->all())->toBe([$target->id])
        ->and($event->actor_id)->toBe($target->id)
        ->and($event->actor?->is($target))->toBeTrue()
        ->and(ConversationMessageAttachment::query()->count())->toBe(0);
});

test('a cobrowse snapshot that loses the merge race audits the canonical visitor', function (): void {
    $account = Account::factory()->create();
    $manager = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();
    $source = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-racing-snapshot']);
    $target = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-racing-snapshot-canonical']);
    $conversation = Conversation::factory()->for($site)->for($source)->create(['support_code' => 'WF-MERGERACE4']);
    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($source)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [],
    ]);
    $token = app(VisitorSessionToken::class)->issue($site, $source);

    mergeAfterVisitorConversationResolution($manager, $source, $target);

    $this->postJson('/api/conversations/WF-MERGERACE4/cobrowse-snapshot', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-racing-snapshot',
        'visitor_token' => $token,
        'page_url' => 'https://example.test/help',
        'html' => '<main>Masked support view</main>',
        'text' => 'Masked support view',
        'node_count' => 2,
        'masked_count' => 1,
    ])->assertOk();

    $event = AuditEvent::query()->where('action', 'cobrowse.snapshot_received')->sole();

    expect($session->fresh()?->visitor_id)->toBe($target->id)
        ->and($event->actor_id)->toBe($target->id)
        ->and($event->actor?->is($target))->toBeTrue();
});

test('cobrowse telemetry that loses the merge race audits the canonical visitor', function (): void {
    $account = Account::factory()->create();
    $manager = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();
    $source = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-racing-telemetry']);
    $target = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-racing-telemetry-canonical']);
    $conversation = Conversation::factory()->for($site)->for($source)->create(['support_code' => 'WF-MERGERACE5']);
    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($source)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [
            'resync_request' => [
                'id' => 'merge-race-resync',
                'requested_at' => now()->subSeconds(30)->toJSON(),
                'fulfilled_at' => null,
            ],
        ],
    ]);
    $token = app(VisitorSessionToken::class)->issue($site, $source);

    mergeAfterVisitorConversationResolution($manager, $source, $target);

    $this->postJson('/api/conversations/WF-MERGERACE5/cobrowse-telemetry', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-racing-telemetry',
        'visitor_token' => $token,
        'resync_request_id' => 'merge-race-resync',
        'resync_attempts_exhausted' => true,
        'rtt_ms' => 950,
        'payload_bytes' => 4096,
        'dropped_batches' => 4,
        'reconnects' => 3,
    ])->assertOk();

    $event = AuditEvent::query()->where('action', 'cobrowse.resync_exhausted')->sole();

    expect($session->fresh()?->visitor_id)->toBe($target->id)
        ->and($event->actor_id)->toBe($target->id)
        ->and($event->actor?->is($target))->toBeTrue();
});

test('merge permission site and identity conflicts fail closed', function (): void {
    $account = Account::factory()->create();
    $managerRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageContacts->value],
    ]);
    $viewerRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ViewConversations->value],
    ]);
    $manager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $managerRole->id,
    ]);
    $viewer = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $viewerRole->id,
    ]);
    $site = Site::factory()->for($account)->create();
    $otherSite = Site::factory()->for($account)->create();
    $site->supportAgents()->attach([$manager->id, $viewer->id]);
    $otherSite->supportAgents()->attach($manager);
    $source = Visitor::factory()->for($site)->create(['external_id' => 'customer-one']);
    $target = Visitor::factory()->for($site)->create(['external_id' => 'customer-two']);
    $otherSiteTarget = Visitor::factory()->for($otherSite)->create();

    $this->actingAs($viewer)
        ->post(route('dashboard.visitors.merge', $source), [
            'target_id' => (string) $target->id,
            'confirmed' => '1',
        ])
        ->assertForbidden();

    $this->actingAs($manager)
        ->post(route('dashboard.visitors.merge', $source), [
            'target_id' => (string) $otherSiteTarget->id,
            'confirmed' => '1',
        ])
        ->assertNotFound();

    $this->actingAs($manager)
        ->from(route('dashboard.visitors.show', $source))
        ->post(route('dashboard.visitors.merge', $source), [
            'target_id' => (string) $source->id,
            'confirmed' => '1',
        ])
        ->assertSessionHasErrors('target_id');

    $this->actingAs($manager)
        ->from(route('dashboard.visitors.show', $source))
        ->post(route('dashboard.visitors.merge', $source), [
            'target_id' => (string) $target->id,
            'confirmed' => '1',
        ])
        ->assertSessionHasErrors('target_id');

    $this->actingAs($manager)
        ->from(route('dashboard.visitors.show', $source))
        ->post(route('dashboard.visitors.merge', $source), [
            'target_id' => '999999999999999999999999999999',
            'confirmed' => '1',
        ])
        ->assertSessionHasErrors('target_id');

    $this->actingAs($manager)
        ->from(route('dashboard.visitors.show', $source))
        ->post(route('dashboard.visitors.merge', $source), [
            'target_id' => (string) $target->id,
        ])
        ->assertSessionHasErrors('confirmed');

    expect(Visitor::query()->whereIn('id', [$source->id, $target->id, $otherSiteTarget->id])->count())->toBe(3)
        ->and(AuditEvent::query()->where('action', 'visitor.merged')->count())->toBe(0);
});

test('a browser supplied host id never silently merges another contact', function (): void {
    $site = Site::factory()->create();
    $canonical = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-canonical-claim',
        'external_id' => 'customer-claimed-publicly',
    ]);
    $claimingBrowser = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-untrusted-claim',
        'external_id' => null,
    ]);

    $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => $claimingBrowser->anonymous_id,
        'external_id' => $canonical->external_id,
    ])->assertOk()
        ->assertJsonPath('data.visitor.identified', false);

    expect(Visitor::query()->count())->toBe(2)
        ->and($claimingBrowser->fresh()?->external_id)->toBeNull()
        ->and(VisitorIdentityAlias::query()->count())->toBe(0);
});

test('a retained browser id finds the canonical contact across support search surfaces', function (): void {
    $account = Account::factory()->create();
    $manager = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();
    $source = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-find-after-merge']);
    $target = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-kept-after-merge',
        'name' => 'Canonical Search Result',
    ]);
    Conversation::factory()->for($site)->for($source)->create(['subject' => 'Alias conversation']);
    Ticket::factory()->for($account)->for($site)->for($source, 'requester')->create(['subject' => 'Alias ticket']);
    AuditEvent::query()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => $manager->getMorphClass(),
        'actor_id' => $manager->id,
        'subject_type' => $source->getMorphClass(),
        'subject_id' => $source->id,
        'action' => 'visitor.note_added',
        'metadata' => ['note_id' => 42],
        'occurred_at' => now()->subMinute(),
    ]);

    $this->actingAs($manager)->post(route('dashboard.visitors.merge', $source), [
        'target_id' => (string) $target->id,
        'confirmed' => '1',
    ])->assertRedirect();

    $this->actingAs($manager)
        ->get(route('dashboard.visitors.index', ['search' => 'anon-find-after-merge']))
        ->assertOk()
        ->assertSee('Canonical Search Result');

    $this->actingAs($manager)
        ->get(route('dashboard.support-code.lookup', [
            'reference_type' => 'visitor',
            'support_code' => 'anon-find-after-merge',
        ]))
        ->assertRedirect(route('dashboard.visitors.show', $target));

    $this->actingAs($manager)
        ->get(route('dashboard.conversations.index', ['conversation_search' => 'anon-find-after-merge']))
        ->assertOk()
        ->assertSee('Alias conversation');

    $this->actingAs($manager)
        ->get(route('dashboard.tickets.index', ['ticket_search' => 'anon-find-after-merge']))
        ->assertOk()
        ->assertSee('Alias ticket');

    $this->actingAs($manager)
        ->get(route('dashboard.account.audit.index', ['audit_search' => 'anon-find-after-merge']))
        ->assertOk()
        ->assertSee('Visitor contact merged')
        ->assertSee('Contact note added')
        ->assertSee('Canonical Search Result');
});
