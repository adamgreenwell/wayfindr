<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Release\UpgradeContext;
use Illuminate\Console\Events\CommandStarting;

/**
 * Remembers which command the operator actually ran (ADR 0013).
 *
 * Laravel commands call other commands, and every one of them fires its own
 * `CommandStarting` and `CommandFinished`. A listener keyed on the command name
 * therefore cannot tell an operator's `migrate:reset` from the `migrate:reset`
 * that `migrate:refresh` runs on its way to re-migrating — and those two want
 * opposite handling of the recorded release.
 *
 * The first event in the process is the invoked command, so first-wins is the
 * whole rule.
 */
class RecordOuterCommand
{
    public function handle(CommandStarting $event): void
    {
        app(UpgradeContext::class)->observeCommand($event->command);
    }
}
