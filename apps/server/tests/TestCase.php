<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every erasure writes to the ledger on the volume, so each test gets its
     * own: one test's entries must not be settled, re-applied or counted by
     * the next, and none may land in the working tree's storage directory.
     */
    private ?string $erasureLedgerPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->erasureLedgerPath = sys_get_temp_dir().'/wayfindr-test-erasure-ledger-'.bin2hex(random_bytes(6));
        config()->set('wayfindr.erasure.ledger_path', $this->erasureLedgerPath);
    }

    protected function tearDown(): void
    {
        if ($this->erasureLedgerPath !== null && is_dir($this->erasureLedgerPath)) {
            exec('rm -rf '.escapeshellarg($this->erasureLedgerPath));
        }

        parent::tearDown();
    }
}
