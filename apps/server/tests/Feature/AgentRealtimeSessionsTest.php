<?php

use App\Jobs\EvictAgentRealtimeSessions;
use App\Models\AgentRealtimeEviction;
use App\Support\AgentRealtimeSessions;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;

uses(RefreshDatabase::class);

test('agent realtime sessions terminate every reverb connection for the user', function (): void {
    config()->set('broadcasting.default', 'reverb');
    $pusher = Mockery::mock();
    $pusher->shouldReceive('terminateUserConnections')->once()->with('42');
    $broadcaster = Mockery::mock(PusherBroadcaster::class);
    $broadcaster->shouldReceive('getPusher')->once()->andReturn($pusher);
    Broadcast::shouldReceive('connection')->once()->with('reverb')->andReturn($broadcaster);

    app(AgentRealtimeSessions::class)->disconnect(42);
});

test('agent realtime session termination is a no-op without reverb', function (): void {
    config()->set('broadcasting.default', 'null');
    Broadcast::shouldReceive('connection')->never();

    app(AgentRealtimeSessions::class)->disconnect(42);
});

test('a reverb outage cannot interrupt best-effort session eviction', function (): void {
    config()->set('broadcasting.default', 'reverb');
    Queue::fake();
    $pusher = Mockery::mock();
    $pusher->shouldReceive('terminateUserConnections')
        ->twice()
        ->andThrow(new RuntimeException('Reverb is unavailable.'));
    $broadcaster = Mockery::mock(PusherBroadcaster::class);
    $broadcaster->shouldReceive('getPusher')->twice()->andReturn($pusher);
    Broadcast::shouldReceive('connection')->twice()->with('reverb')->andReturn($broadcaster);

    $sessions = app(AgentRealtimeSessions::class);
    $sessions->requestMany([42, 43]);

    expect(fn () => $sessions->disconnectMany([42, 43]))
        ->not->toThrow(RuntimeException::class);

    expect(AgentRealtimeEviction::query()->pluck('attempts', 'agent_id')->all())
        ->toBe([42 => 1, 43 => 1]);
    Queue::assertPushed(
        EvictAgentRealtimeSessions::class,
        fn (EvictAgentRealtimeSessions $job): bool => in_array($job->agentId, [42, 43], true),
    );
    Queue::assertPushed(EvictAgentRealtimeSessions::class, 2);
});

test('a successful immediate termination clears its durable eviction request', function (): void {
    config()->set('broadcasting.default', 'reverb');
    Queue::fake();
    $pusher = Mockery::mock();
    $pusher->shouldReceive('terminateUserConnections')->once()->with('42');
    $broadcaster = Mockery::mock(PusherBroadcaster::class);
    $broadcaster->shouldReceive('getPusher')->once()->andReturn($pusher);
    Broadcast::shouldReceive('connection')->once()->with('reverb')->andReturn($broadcaster);

    $sessions = app(AgentRealtimeSessions::class);
    $sessions->requestMany([42]);
    $sessions->disconnectMany([42]);

    expect(AgentRealtimeEviction::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

test('the queued eviction keeps retrying the durable request until reverb accepts it', function (): void {
    config()->set('broadcasting.default', 'reverb');
    $pusher = Mockery::mock();
    $pusher->shouldReceive('terminateUserConnections')
        ->once()
        ->with('42')
        ->andThrow(new RuntimeException('Reverb is unavailable.'));
    $broadcaster = Mockery::mock(PusherBroadcaster::class);
    $broadcaster->shouldReceive('getPusher')->once()->andReturn($pusher);
    Broadcast::shouldReceive('connection')->once()->with('reverb')->andReturn($broadcaster);

    $sessions = app(AgentRealtimeSessions::class);
    $sessions->requestMany([42]);
    $job = new EvictAgentRealtimeSessions(42);

    expect(fn () => $job->handle($sessions))->toThrow(RuntimeException::class);
    expect(AgentRealtimeEviction::query()->where('agent_id', 42)->value('attempts'))->toBe(1)
        ->and($job->tries)->toBe(0)
        ->and($job->backoff())->toBe([10, 30, 60, 300, 900]);
});

test('the scheduler recovers durable realtime evictions whose queue handoff failed', function (): void {
    Queue::fake();
    AgentRealtimeEviction::query()->create([
        'agent_id' => 42,
        'request_id' => (string) str()->uuid(),
        'requested_at' => now(),
    ]);

    $this->artisan('wayfindr:queue-agent-realtime-evictions')
        ->expectsOutput('Queued 1 agent realtime eviction.')
        ->assertSuccessful();

    Queue::assertPushed(
        EvictAgentRealtimeSessions::class,
        fn (EvictAgentRealtimeSessions $job): bool => $job->agentId === 42,
    );
    expect(collect(Schedule::events())->contains(
        fn ($event): bool => str_contains($event->command ?? '', 'wayfindr:queue-agent-realtime-evictions')
    ))->toBeTrue();
});
