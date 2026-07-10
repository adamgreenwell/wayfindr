<?php

// Abandoned cobrowse sessions (visitor closes the tab without revoking) used to
// stay status=granted / ended_at=null forever. That kept them "active" in
// operator readiness AND — because the retention pruner only touches ended
// sessions — left their captured page content retained indefinitely. This
// command ends granted sessions idle past the window, stamping ended_at to the
// last activity so the 72h pruner's clock counts from when the visitor left.

use App\Models\CobrowseSession;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Create a granted session whose last activity (updated_at) is $minutesAgo old. */
function idleGrantedSession(int $minutesAgo): CobrowseSession
{
    $session = CobrowseSession::factory()
        ->for(Conversation::factory())
        ->create(['status' => 'granted', 'ended_at' => null]);

    // Bypass Eloquent so we can pin updated_at into the past.
    DB::table('cobrowse_sessions')
        ->where('id', $session->id)
        ->update(['updated_at' => now()->subMinutes($minutesAgo)]);

    return $session->fresh();
}

test('it ends granted sessions idle past the window, dating ended_at to last activity', function (): void {
    $idle = idleGrantedSession(20);
    $lastActivity = $idle->updated_at;

    $this->artisan('wayfindr:expire-idle-cobrowse-sessions')
        ->expectsOutputToContain('Expired 1 idle cobrowse session')
        ->assertSuccessful();

    $idle->refresh();

    expect($idle->status)->toBe('ended')
        ->and($idle->ended_at)->not->toBeNull()
        // ended_at is the last-activity time (~20 min ago), NOT now().
        ->and($idle->ended_at->diffInSeconds($lastActivity))->toBeLessThanOrEqual(2)
        ->and($idle->ended_at->lessThan(now()->subMinutes(10)))->toBeTrue()
        ->and(data_get($idle->metadata, 'ended_by_type'))->toBe('system')
        ->and(data_get($idle->metadata, 'ended_reason'))->toBe('idle_timeout')
        ->and(data_get($idle->metadata, 'idle_expiry_minutes'))->toBe(15);
});

test('it leaves recently-active granted sessions alone', function (): void {
    $recent = idleGrantedSession(2);

    $this->artisan('wayfindr:expire-idle-cobrowse-sessions')
        ->expectsOutputToContain('Expired 0 idle cobrowse sessions')
        ->assertSuccessful();

    $recent->refresh();

    expect($recent->status)->toBe('granted')
        ->and($recent->ended_at)->toBeNull();
});

test('it ignores sessions that are already ended', function (): void {
    $endedAt = now()->subHours(3);
    $session = CobrowseSession::factory()
        ->for(Conversation::factory())
        ->create(['status' => 'ended', 'ended_at' => $endedAt]);

    DB::table('cobrowse_sessions')
        ->where('id', $session->id)
        ->update(['updated_at' => now()->subHours(3)]);

    $this->artisan('wayfindr:expire-idle-cobrowse-sessions')
        ->expectsOutputToContain('Expired 0 idle cobrowse sessions')
        ->assertSuccessful();

    expect($session->fresh()->ended_at->diffInSeconds($endedAt))->toBeLessThanOrEqual(2);
});

test('the dry run reports without changing anything', function (): void {
    $idle = idleGrantedSession(20);

    $this->artisan('wayfindr:expire-idle-cobrowse-sessions --dry-run')
        ->expectsOutputToContain('Would expire 1 idle cobrowse session')
        ->assertSuccessful();

    $idle->refresh();

    expect($idle->status)->toBe('granted')
        ->and($idle->ended_at)->toBeNull();
});

test('the idle window is configurable', function (): void {
    config(['wayfindr.cobrowse.session_idle_expiry_minutes' => 30]);

    // 20 minutes idle is within a 30-minute window, so it stays active.
    $idle = idleGrantedSession(20);

    $this->artisan('wayfindr:expire-idle-cobrowse-sessions')
        ->expectsOutputToContain('past the 30-minute idle window')
        ->assertSuccessful();

    expect($idle->fresh()->status)->toBe('granted');
});
