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

it('reports null when a shareable store cannot actually be read', function (): void {
    // The gap this closes: canObserve() reads CONFIGURATION, so a Redis that is
    // configured but down looked shareable, the caught read error produced no
    // timestamp, and that was reported as "no worker" — telling an operator to
    // add a backups worker they are already running, when the only demonstrated
    // problem is that the cache is down.
    config()->set('cache.default', 'redis');
    config()->set('cache.stores.redis', ['driver' => 'redis', 'connection' => 'nonexistent-connection']);

    $heartbeat = new QueueConsumerHeartbeat;

    expect($heartbeat->canObserve())->toBeTrue()
        ->and($heartbeat->observe('backups', 'backups')['state'])
        ->toBe(QueueConsumerHeartbeat::UNKNOWN)
        ->and($heartbeat->seenWithin('backups', 'backups', 60))->toBeNull();
});

it('separates "read fine, nothing there" from "could not read"', function (): void {
    $heartbeat = new QueueConsumerHeartbeat;

    // The file store is reachable and empty: a real negative.
    expect($heartbeat->observe('backups', 'backups')['state'])->toBe(QueueConsumerHeartbeat::NONE);

    $heartbeat->record('backups', 'backups');

    expect($heartbeat->observe('backups', 'backups')['state'])->toBe(QueueConsumerHeartbeat::SEEN);
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

it('keeps a sighting readable for longer than the window asked about', function (): void {
    // A very large backup can push retry_after past a day via
    // BACKUP_QUEUE_RETRY_AFTER / WAYFINDR_BACKUP_JOB_TIMEOUT. A fixed 24h
    // retention would expire the sighting while the worker was still legally
    // mid-job, reporting "no worker" for a worker that is right there.
    config()->set('queue.connections.backups.retry_after', 172800); // 48h

    $heartbeat = new QueueConsumerHeartbeat;
    $heartbeat->record('backups', 'backups');

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(30));

    expect($heartbeat->seenWithin('backups', 'backups', 172800))->toBeTrue();

    CarbonImmutable::setTestNow();
});

it('derives the freshness window from the connection, for every caller', function (): void {
    // One definition. A page applying a different rule from the check would
    // call a worker healthy while the check called the requirement unmet.
    config()->set('queue.connections.backups.retry_after', 3900);
    expect((new QueueConsumerHeartbeat)->windowSecondsFor('backups'))->toBe(3900);

    config()->set('queue.connections.backups.retry_after', null);
    expect((new QueueConsumerHeartbeat)->windowSecondsFor('backups'))->toBe(3600);
});

it('writes and reads the pinned member of a failover chain', function (): void {
    config()->set('cache.default', 'failover');
    config()->set('cache.stores.failover', ['driver' => 'failover', 'stores' => ['file', 'array']]);
    Cache::store('file')->clear();

    $heartbeat = new QueueConsumerHeartbeat;
    $heartbeat->record('backups', 'backups');

    // Landed in the shared member specifically, not wherever the chain went.
    expect(Cache::store('file')->get('wayfindr:queue-consumer:backups:backups'))->not->toBeNull()
        ->and($heartbeat->seenWithin('backups', 'backups', 60))->toBeTrue();
});

it('does not silently fall through to the array member when the shared one is down', function (): void {
    // The failure this prevents. Laravel's failover store uses the first member
    // whose operation SUCCEEDS -- it does not write through the chain -- and
    // this repo's chain ends in `array`. Going through the facade, a worker
    // with the shared member down writes its sighting into its OWN
    // process-local array, the web process reads an empty one, and the page
    // confidently tells the operator to add a worker that is running fine.
    //
    // Pinned to the shared member, the write and the read both fail instead,
    // which is UNKNOWN -- the truth. The array fallback is never mistaken for
    // a channel between processes.
    config()->set('cache.default', 'failover');
    config()->set('cache.stores.failover', ['driver' => 'failover', 'stores' => ['redis', 'array']]);
    config()->set('cache.stores.redis', ['driver' => 'redis', 'connection' => 'nonexistent-connection']);

    $heartbeat = new QueueConsumerHeartbeat;
    $heartbeat->record('backups', 'backups');

    expect($heartbeat->observe('backups', 'backups')['state'])->toBe(QueueConsumerHeartbeat::UNKNOWN)
        ->and($heartbeat->seenWithin('backups', 'backups', 60))->toBeNull();
});

it('cannot observe when every failover member is process-local', function (): void {
    config()->set('cache.default', 'failover');
    config()->set('cache.stores.failover', ['driver' => 'failover', 'stores' => ['array', 'null']]);

    $heartbeat = new QueueConsumerHeartbeat;

    expect($heartbeat->canObserve())->toBeFalse()
        ->and($heartbeat->observe('backups', 'backups')['state'])->toBe(QueueConsumerHeartbeat::UNKNOWN);
});
