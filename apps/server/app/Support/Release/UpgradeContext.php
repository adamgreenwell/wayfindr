<?php

declare(strict_types=1);

namespace App\Support\Release;

/**
 * What was true about this install BEFORE its migrations ran.
 *
 * Freshness is the one fact the guard needs that migrating destroys. It is read
 * off the migrations table — empty means nothing has ever been installed here —
 * and the first thing `migrate` does is populate it. So the pre-migration check
 * sees a fresh install and the post-migration one, evaluating the very same
 * install, sees a legacy upgrade from an unknown starting point and hands it the
 * whole published history.
 *
 * A singleton for the life of the process, which is exactly the scope that
 * matters: the guard observes freshness on `CommandStarting`, and the recorder
 * reads it back on `CommandFinished` of the same command.
 *
 * Only the FIRST observation is kept. Later ones in the same process are made
 * after the evidence has changed, and are not observations of the same thing.
 */
final class UpgradeContext
{
    private ?bool $freshInstall = null;

    private ?string $outerCommand = null;

    /**
     * Keep the earliest observation, discarding later ones.
     */
    public function observeFreshInstall(bool $fresh): void
    {
        $this->freshInstall ??= $fresh;
    }

    /**
     * What was observed, or null if nothing has been observed yet — in which case
     * the caller's own reading is the best available and should be used.
     */
    public function wasFreshInstall(): ?bool
    {
        return $this->freshInstall;
    }

    /**
     * The command the operator actually ran, as opposed to one it called.
     *
     * `migrate:refresh` runs `migrate:reset` and then a nested `migrate`, and
     * each fires its own `CommandFinished` — so a listener keyed on the command
     * name alone cannot tell a standalone reset, which should forget the recorded
     * release, from the reset inside a refresh, which must not: forgetting there
     * leaves the nested migrate reading an empty ledger, calling the install
     * fresh, and recording an exemption that erases outstanding work.
     *
     * First observation wins, and the first `CommandStarting` in the process is
     * the one the operator invoked.
     */
    public function observeCommand(string $command): void
    {
        $this->outerCommand ??= $command;
    }

    public function outerCommand(): ?string
    {
        return $this->outerCommand;
    }
}
