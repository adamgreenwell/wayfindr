<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function purgeableSite(Account $account, bool $archived = true): Site
{
    $site = Site::factory()->for($account)->create([
        'name' => 'Doomed Site',
        'archived_at' => $archived ? now() : null,
    ]);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['status' => 'open']);
    ConversationMessage::factory()->for($conversation)->create();

    return $site;
}

test('a site cannot be purged until it has been archived', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $site = purgeableSite($account, archived: false);

    $this->actingAs($owner)
        ->delete("/dashboard/sites/{$site->id}", ['confirm_name' => 'Doomed Site'])
        ->assertRedirect(route('dashboard.sites.show', $site))
        ->assertSessionHasErrors('confirm_name');

    $this->assertDatabaseHas('sites', ['id' => $site->id]);
    $this->assertDatabaseCount('conversations', 1);
});

test('purging requires the site name typed exactly', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $site = purgeableSite($account);

    $this->actingAs($owner)
        ->delete("/dashboard/sites/{$site->id}", ['confirm_name' => 'doomed site'])
        ->assertRedirect(route('dashboard.sites.show', $site))
        ->assertSessionHasErrors('confirm_name');

    $this->assertDatabaseHas('sites', ['id' => $site->id]);
});

test('an admin who is not the owner cannot purge a site', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = purgeableSite($account);

    $this->actingAs($admin)
        ->delete("/dashboard/sites/{$site->id}", ['confirm_name' => 'Doomed Site'])
        ->assertForbidden();

    $this->assertDatabaseHas('sites', ['id' => $site->id]);
});

test('purging destroys the site, its records and its attachment binaries', function (): void {
    Storage::fake('attachments');

    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create([
        'account_role' => AccountRole::Owner,
        'locale' => 'de',
    ]);
    $site = purgeableSite($account);

    $message = ConversationMessage::query()->firstOrFail();
    $attachment = ConversationMessageAttachment::factory()->create([
        'conversation_message_id' => $message->id,
        'storage_disk' => 'attachments',
        'storage_key' => 'attachments/doomed/file.png',
    ]);
    Storage::disk('attachments')->put('attachments/doomed/file.png', 'binary');
    Storage::disk('attachments')->assertExists('attachments/doomed/file.png');

    // A second site's binary must survive: purge is scoped to one site.
    $survivor = Site::factory()->for($account)->create(['name' => 'Survivor']);
    $survivorVisitor = Visitor::factory()->for($survivor)->create();
    $survivorConversation = Conversation::factory()->for($survivor)->for($survivorVisitor)->create();
    $survivorMessage = ConversationMessage::factory()->for($survivorConversation)->create();
    ConversationMessageAttachment::factory()->create([
        'conversation_message_id' => $survivorMessage->id,
        'storage_disk' => 'attachments',
        'storage_key' => 'attachments/survivor/file.png',
    ]);
    Storage::disk('attachments')->put('attachments/survivor/file.png', 'binary');

    $this->actingAs($owner)
        ->delete("/dashboard/sites/{$site->id}", ['confirm_name' => 'Doomed Site'])
        ->assertRedirect(route('dashboard.sites.index'))
        ->assertSessionHas('status', fn (mixed $status): bool => is_array($status)
            && ($status['key'] ?? null) === 'sites.flash.purged');

    $this->assertDatabaseMissing('sites', ['id' => $site->id]);
    $this->assertDatabaseMissing('conversations', ['site_id' => $site->id]);
    $this->assertDatabaseMissing('visitors', ['site_id' => $site->id]);
    $this->assertDatabaseMissing('conversation_message_attachments', ['id' => $attachment->id]);

    Storage::disk('attachments')->assertMissing('attachments/doomed/file.png');

    // The neighbouring site is untouched.
    $this->assertDatabaseHas('sites', ['id' => $survivor->id]);
    Storage::disk('attachments')->assertExists('attachments/survivor/file.png');

    $this->get(route('dashboard.sites.index'))
        ->assertOk()
        ->assertSee('<html lang="de"', false)
        ->assertSee('Website „<span lang="">Doomed Site</span>“ wurde zusammen mit 1 Unterhaltung, 0 Tickets und 1 Anhang dauerhaft gelöscht.', false)
        ->assertDontSee('was permanently deleted');
});

test('the record of a purge outlives the site it destroyed', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $site = purgeableSite($account);
    $siteId = $site->id;

    $this->actingAs($owner)
        ->delete("/dashboard/sites/{$siteId}", ['confirm_name' => 'Doomed Site'])
        ->assertRedirect(route('dashboard.sites.index'));

    // audit_events.site_id cascades, so the purge record must be account-scoped
    // with a null site_id or it would delete itself along with the site.
    $this->assertDatabaseHas('audit_events', [
        'account_id' => $account->id,
        'site_id' => null,
        'action' => 'site.purged',
    ]);

    $record = DB::table('audit_events')->where('action', 'site.purged')->first();
    $metadata = json_decode($record->metadata, true);

    expect($metadata['site_id'])->toBe($siteId)
        ->and($metadata['site_name'])->toBe('Doomed Site')
        ->and($metadata['destroyed']['conversations'])->toBe(1);

    // And the site's own audit trail is gone with it.
    $this->assertDatabaseMissing('audit_events', ['site_id' => $siteId]);
});
