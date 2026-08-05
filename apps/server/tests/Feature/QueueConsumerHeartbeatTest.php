<?php

declare(strict_types=1);

use App\Support\Queue\QueueConsumerHeartbeat;
use App\Support\Release\CheckRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;

/**
 * The distinction these tests exist to protect: "no worker" and "cannot tell"
 * are different answers, and only one of them is the operator's fault.
 *
 * Note the cache store is set explicitly in most tests. The heartbeat refuses to
 * write through a store nothing else could read, so a test that leaves the store
 * on `array` is testing the unobservable path whether it meant to or not.
 */
beforeEach(function (): void {
    config()->set('cache.default', 'file');
    Cache::store('file')->clear();
});

it('reports a worker that announced itself', function (): void {
    $heartbeat = new QueueConsumerHeartbeat;

    $heartbeat->record('backups', 'backups');

    expect($heartbeat->seenWithin('backups', 'backups', 60))->toBeTrue()
        ->and($heartbeat->lastSeenAt('backups', 'backups'))->not->toBeNull();
});

it('reports false when the channel works and nothing was seen', function (): void {
    // The distinction that matters: this install COULD have observed a worker,
    // and did not. That is a real negative, not an absence of evidence.
    $heartbeat = new QueueConsumerHeartbeat;

    expect($heartbeat->canObserve())->toBeTrue()
        ->and($heartbeat->seenWithin('backups', 'backups', 60))->toBeFalse();
});

it('reports null when a sighting could never travel between processes', function (): void {
    // An array store answers only inside one request, so a worker in another
    // process could not have been seen no matter what it did. Reporting "no
    // worker" here would blame the operator for their cache driver.
    config()->set('cache.default', 'array');

    $heartbeat = new QueueConsumerHeartbeat;

    expect($heartbeat->canObserve())->toBeFalse()
        ->and($heartbeat->seenWithin('backups', 'backups', 60))->toBeNull();
});

it('does not count a sighting older than the window', function (): void {
    $heartbeat = new QueueConsumerHeartbeat;

    CarbonImmutable::setTestNow(CarbonImmutable::now()->subMinutes(30));
    $heartbeat->record('backups', 'backups');
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(30));

    expect($heartbeat->seenWithin('backups', 'backups', 300))->toBeFalse()
        ->and($heartbeat->seenWithin('backups', 'backups', 3600))->toBeTrue();

    CarbonImmutable::setTestNow();
});

it('credits every queue a multi-queue worker drains', function (): void {
    // `queue:work --queue=a,backups` passes the raw list through. Recording it
    // whole would file the sighting under a queue called "a,backups", which
    // nothing ever asks about, and `backups` would read as unconsumed.
    $heartbeat = new QueueConsumerHeartbeat;

    $heartbeat->record('backups', 'urgent,backups');

    expect($heartbeat->seenWithin('backups', 'backups', 60))->toBeTrue();
});

it('falls back to the connection default when the worker names no queue', function (): void {
    config()->set('queue.connections.backups.queue', 'backups');

    $heartbeat = new QueueConsumerHeartbeat;
    $heartbeat->record('backups', null);

    expect($heartbeat->seenWithin('backups', null, 60))->toBeTrue();
});

it('throttles repeated writes without losing the sighting', function (): void {
    $heartbeat = new QueueConsumerHeartbeat(throttleSeconds: 3600);

    $heartbeat->record('backups', 'backups');
    $first = $heartbeat->lastSeenAt('backups', 'backups');

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5));
    $heartbeat->record('backups', 'backups');

    // Throttled, so the stamp is unchanged — but still readable, which is what
    // callers depend on.
    expect($heartbeat->lastSeenAt('backups', 'backups')?->getTimestamp())
        ->toBe($first?->getTimestamp());

    CarbonImmutable::setTestNow();
});

it('never throws out of a worker loop', function (): void {
    // Recording runs inside queue:work. A cache blip must not take down the
    // worker to protect a diagnostic.
    config()->set('cache.default', 'nonexistent-store');

    $heartbeat = new QueueConsumerHeartbeat;

    expect(fn () => $heartbeat->record('backups', 'backups'))->not->toThrow(Throwable::class);
});

it('is emitted by the worker loop event', function (): void {
    // Proves the wiring, not just the service: without the listener the check
    // has nothing to read and would report "no worker" on every install.
    event(new Looping('backups', 'backups'));

    expect(app(QueueConsumerHeartbeat::class)->seenWithin('backups', 'backups', 60))->toBeTrue();
})->skip(fn (): bool => ! app()->runningInConsole(), 'The listener is console-only.');

describe('the backups-queue-consumer check', function (): void {
    it('passes once a worker has been seen', function (): void {
        app(QueueConsumerHeartbeat::class)->record('backups', 'backups');

        expect((new CheckRegistry)->evaluate('backups-queue-consumer'))->toBeTrue();
    });

    it('fails when the channel works and no worker has been seen', function (): void {
        expect((new CheckRegistry)->evaluate('backups-queue-consumer'))->toBeFalse();
    });

    it('cannot evaluate without a backups connection', function (): void {
        // The declaration is asking about something this install does not have.
        // That is unanswerable, not unmet — reporting false would demand the
        // operator fix a queue they were never asked to configure.
        config()->set('queue.connections.backups', null);

        expect((new CheckRegistry)->evaluate('backups-queue-consumer'))->toBeNull();
    });

    it('tolerates a job that runs longer than any poll interval', function (): void {
        // The regression this guards: a backup running under --timeout=3600
        // stops the worker looping, so a short window would report "no worker"
        // exactly while one was busy doing the backup.
        config()->set('queue.connections.backups.retry_after', 3900);

        app(QueueConsumerHeartbeat::class)->record('backups', 'backups');

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(45));

        expect((new CheckRegistry)->evaluate('backups-queue-consumer'))->toBeTrue();

        CarbonImmutable::setTestNow();
    });

    it('does not pass on a worker that stopped long ago', function (): void {
        config()->set('queue.connections.backups.retry_after', 3900);

        app(QueueConsumerHeartbeat::class)->record('backups', 'backups');

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(3));

        expect((new CheckRegistry)->evaluate('backups-queue-consumer'))->toBeFalse();

        CarbonImmutable::setTestNow();
    });
});
