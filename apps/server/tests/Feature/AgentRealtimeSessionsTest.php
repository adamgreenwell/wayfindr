<?php

use App\Support\AgentRealtimeSessions;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;

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
    $pusher = Mockery::mock();
    $pusher->shouldReceive('terminateUserConnections')
        ->twice()
        ->andThrow(new RuntimeException('Reverb is unavailable.'));
    $broadcaster = Mockery::mock(PusherBroadcaster::class);
    $broadcaster->shouldReceive('getPusher')->twice()->andReturn($pusher);
    Broadcast::shouldReceive('connection')->twice()->with('reverb')->andReturn($broadcaster);

    expect(fn () => app(AgentRealtimeSessions::class)->disconnectMany([42, 43]))
        ->not->toThrow(RuntimeException::class);
});
