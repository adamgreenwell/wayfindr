<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('a contact manager can add and delete a note that remains independent from tickets', function (): void {
    $account = Account::factory()->create();
    $manager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Manager',
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Docs']);
    $visitor = Visitor::factory()->for($site)->create([
        'name' => 'Avery Contact',
        'anonymous_id' => 'anon-contact-note',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($visitor, 'requester')
        ->create();

    $this->actingAs($manager)
        ->post(route('dashboard.visitors.notes.store', $visitor), [
            'body' => "  Prefers an email summary.\nConfirm the timeline first.  ",
        ])
        ->assertRedirect(route('dashboard.visitors.show', $visitor).'#visitor-notes-heading')
        ->assertSessionHas('status', 'visitor_notes.flash.added');

    $note = VisitorNote::query()->sole();

    expect($note->account_id)->toBe($account->id)
        ->and($note->visitor_id)->toBe($visitor->id)
        ->and($note->author_id)->toBe($manager->id)
        ->and($note->body)->toBe("Prefers an email summary.\nConfirm the timeline first.");

    $ticket->delete();

    expect($note->fresh())->not->toBeNull();

    $this->actingAs($manager)
        ->get(route('dashboard.visitors.show', $visitor))
        ->assertOk()
        ->assertSee('Contact notes')
        ->assertSee('Prefers an email summary.')
        ->assertSee('Ada Manager')
        ->assertSee('Delete note');

    $createdEvent = AuditEvent::query()->where('action', 'visitor.note_added')->sole();

    expect($createdEvent->account_id)->toBe($account->id)
        ->and($createdEvent->site_id)->toBe($site->id)
        ->and($createdEvent->subject_type)->toBe(Visitor::class)
        ->and($createdEvent->subject_id)->toBe($visitor->id)
        ->and($createdEvent->metadata)->toBe(['note_id' => $note->id])
        ->and(json_encode($createdEvent->metadata, JSON_THROW_ON_ERROR))->not->toContain('Prefers');

    $this->actingAs($manager)
        ->get(route('dashboard.account.audit.index', ['audit_search' => 'Avery Contact']))
        ->assertOk()
        ->assertSee('Contact note added')
        ->assertSee('Avery Contact')
        ->assertDontSee('Prefers an email summary.');

    $this->actingAs($manager)
        ->delete(route('dashboard.visitors.notes.destroy', [$visitor, $note]))
        ->assertRedirect(route('dashboard.visitors.show', $visitor).'#visitor-notes-heading')
        ->assertSessionHas('status', 'visitor_notes.flash.deleted');

    expect(VisitorNote::query()->count())->toBe(0);

    $deletedEvent = AuditEvent::query()->where('action', 'visitor.note_deleted')->sole();

    expect($deletedEvent->metadata)->toBe(['note_id' => $note->id])
        ->and(json_encode($deletedEvent->metadata, JSON_THROW_ON_ERROR))->not->toContain('Prefers');
});

test('a support viewer can read contact notes without gaining permission to write or delete them', function (): void {
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ViewConversations->value],
    ]);
    $viewer = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $author = User::factory()->for($account)->create(['name' => 'Note Author']);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($viewer);
    $visitor = Visitor::factory()->for($site)->create();
    $note = VisitorNote::factory()->create([
        'account_id' => $account->id,
        'visitor_id' => $visitor->id,
        'author_id' => $author->id,
        'body' => 'Keep this context between tickets.',
    ]);

    $this->actingAs($viewer)
        ->get(route('dashboard.visitors.show', $visitor))
        ->assertOk()
        ->assertSee('Keep this context between tickets.')
        ->assertDontSee('Add a private contact note')
        ->assertDontSee('Delete note');

    $this->actingAs($viewer)
        ->post(route('dashboard.visitors.notes.store', $visitor), ['body' => 'Not allowed'])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->delete(route('dashboard.visitors.notes.destroy', [$visitor, $note]))
        ->assertForbidden();

    expect($note->fresh())->not->toBeNull();
});

test('contact note writes fail closed across accounts visitors and site assignments', function (): void {
    $account = Account::factory()->create();
    $managerRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageContacts->value],
    ]);
    $manager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $managerRole->id,
    ]);
    $otherAgent = User::factory()->for($account)->create();
    $assignedSite = Site::factory()->for($account)->create();
    $unassignedSite = Site::factory()->for($account)->create();
    $assignedSite->supportAgents()->attach($manager);
    $unassignedSite->supportAgents()->attach($otherAgent);
    $assignedVisitor = Visitor::factory()->for($assignedSite)->create();
    $otherVisitor = Visitor::factory()->for($assignedSite)->create();
    $unassignedVisitor = Visitor::factory()->for($unassignedSite)->create();
    $note = VisitorNote::factory()->create([
        'account_id' => $account->id,
        'visitor_id' => $assignedVisitor->id,
        'author_id' => $manager->id,
    ]);
    $foreignSite = Site::factory()->for(Account::factory())->create();
    $foreignVisitor = Visitor::factory()->for($foreignSite)->create();

    $this->actingAs($manager)
        ->post(route('dashboard.visitors.notes.store', $unassignedVisitor), ['body' => 'Out of scope'])
        ->assertNotFound();

    $this->actingAs($manager)
        ->post(route('dashboard.visitors.notes.store', $foreignVisitor), ['body' => 'Wrong account'])
        ->assertNotFound();

    $this->actingAs($manager)
        ->delete(route('dashboard.visitors.notes.destroy', [$otherVisitor, $note]))
        ->assertNotFound();

    expect($note->fresh())->not->toBeNull()
        ->and(VisitorNote::query()->count())->toBe(1);
});

test('contact note validation rejects blank and oversized bodies without an audit receipt', function (): void {
    $account = Account::factory()->create();
    $manager = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $visitor = Visitor::factory()->for(Site::factory()->for($account))->create();

    $this->actingAs($manager)
        ->post(route('dashboard.visitors.notes.store', $visitor), ['body' => " \n\t "])
        ->assertSessionHasErrors('body');

    $this->actingAs($manager)
        ->post(route('dashboard.visitors.notes.store', $visitor), ['body' => str_repeat('a', 4001)])
        ->assertSessionHasErrors('body');

    expect(VisitorNote::query()->count())->toBe(0)
        ->and(AuditEvent::query()->whereIn('action', ['visitor.note_added', 'visitor.note_deleted'])->count())->toBe(0);
});

test('older contact notes remain reachable through dedicated pagination', function (): void {
    $account = Account::factory()->create();
    $manager = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $visitor = Visitor::factory()->for(Site::factory()->for($account))->create();

    foreach (range(1, 11) as $index) {
        VisitorNote::factory()->create([
            'account_id' => $account->id,
            'visitor_id' => $visitor->id,
            'author_id' => $manager->id,
            'body' => $index === 1 ? 'Oldest durable note' : 'Contact note '.$index,
            'created_at' => Carbon::parse('2026-09-05 12:00:00 UTC')->addMinutes($index),
        ]);
    }

    $this->actingAs($manager)
        ->get(route('dashboard.visitors.show', $visitor))
        ->assertOk()
        ->assertSee('11 notes')
        ->assertSee('Contact note 11')
        ->assertDontSee('Oldest durable note');

    $this->actingAs($manager)
        ->get(route('dashboard.visitors.show', [$visitor, 'notes_page' => 2]))
        ->assertOk()
        ->assertSee('Oldest durable note')
        ->assertDontSee('Contact note 11');
});

test('an out-of-range notes page links back to the last available page', function (): void {
    $account = Account::factory()->create();
    $manager = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $visitor = Visitor::factory()->for(Site::factory()->for($account))->create();

    VisitorNote::factory()->count(11)->create([
        'account_id' => $account->id,
        'visitor_id' => $visitor->id,
        'author_id' => $manager->id,
    ]);

    $lastPageUrl = route('dashboard.visitors.show', [$visitor, 'notes_page' => 2]).'#visitor-notes-heading';

    $this->actingAs($manager)
        ->get(route('dashboard.visitors.show', [$visitor, 'notes_page' => 3]))
        ->assertOk()
        ->assertSee('This notes page is no longer available.')
        ->assertSee($lastPageUrl, false)
        ->assertDontSee('No contact notes yet');
});

test('contact note bodies cascade with the visitor record', function (): void {
    $account = Account::factory()->create();
    $author = User::factory()->for($account)->create();
    $visitor = Visitor::factory()->for(Site::factory()->for($account))->create();
    $note = VisitorNote::factory()->create([
        'visitor_id' => $visitor->id,
        'author_id' => $author->id,
        'body' => 'Delete this with the person.',
    ]);

    expect($note->account_id)->toBe($account->id);

    $visitor->delete();

    expect(VisitorNote::query()->count())->toBe(0);
});

test('adding a contact note protects a presence-only visitor from both cleanup paths', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $site = Site::factory()->for($account)->create([
        'settings' => ['presence' => ['enabled' => true, 'page_urls' => false]],
    ]);
    $staleVisitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'presence-note-stale',
        'presence_only' => true,
        'last_seen_at' => now()->subDays(40),
    ]);
    $disableVisitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'presence-note-disable',
        'presence_only' => true,
        'last_seen_at' => now()->subDay(),
    ]);

    foreach ([$staleVisitor, $disableVisitor] as $visitor) {
        $this->actingAs($owner)
            ->post(route('dashboard.visitors.notes.store', $visitor), [
                'body' => 'This visitor is now an intentional contact record.',
            ])
            ->assertSessionHasNoErrors();

        expect($visitor->fresh()->presence_only)->toBeFalse();
    }

    $this->artisan('wayfindr:prune-presence-visitors')->assertExitCode(0);

    expect($staleVisitor->fresh())->not->toBeNull()
        ->and($staleVisitor->contactNotes()->count())->toBe(1);

    $this->actingAs($owner)
        ->put(route('dashboard.sites.presence.update', $site), [])
        ->assertSessionHasNoErrors();

    expect($disableVisitor->fresh())->not->toBeNull()
        ->and($disableVisitor->contactNotes()->count())->toBe(1);
});
