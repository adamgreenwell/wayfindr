<?php

use Illuminate\Support\Facades\DB;

/**
 * The suite is testing the database it was told to test.
 *
 * This exists because the obvious check does not work. Asserting the driver
 * from a standalone script reads `.env`, which names PostgreSQL, while the
 * suite itself is configured by `phpunit.xml`, which pins SQLite -- so a
 * standalone guard reports success while every test runs on the other engine.
 *
 * Asserting it from inside the suite is the only place the answer is the real
 * one. CI sets the expected driver per job; locally the variable is absent and
 * this skips, because a developer running the suite has not claimed anything
 * about which engine they meant.
 */
test('the suite runs on the database the job asked for', function (): void {
    $expected = env('WAYFINDR_EXPECTED_DB_DRIVER');

    if (! is_string($expected) || $expected === '') {
        $this->markTestSkipped('No driver was demanded, so none is asserted.');
    }

    expect(DB::connection()->getDriverName())->toBe($expected);
});
