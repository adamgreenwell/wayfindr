<?php

declare(strict_types=1);

namespace App\Support\Updates;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use RuntimeException;
use Throwable;

/** Keep the updater's hold separate from an operator or restore's maintenance. */
final readonly class ManagedUpdateMaintenanceMode implements MaintenanceMode
{
    public function __construct(
        private MaintenanceMode $ordinary,
        private ManagedUpdateGate $gate,
    ) {}

    public function activate(array $payload): void
    {
        $this->ordinary->activate($payload);
    }

    public function deactivate(): void
    {
        // A restore that owned ordinary maintenance may finish after the updater
        // entered its hold. Its `up` must preserve both windows until the host
        // has checked recovery and explicitly released this operation's hold.
        if ($this->managedHoldActive()) {
            throw new RuntimeException('managed_update_busy');
        }

        $this->ordinary->deactivate();
    }

    public function active(): bool
    {
        return $this->managedHoldActive() || $this->ordinary->active();
    }

    public function ordinaryActive(): bool
    {
        return $this->ordinary->active();
    }

    public function data(): array
    {
        if ($this->managedHoldActive()) {
            return [
                'except' => [],
                'redirect' => null,
                'retry' => 60,
                'refresh' => null,
                'secret' => null,
                'status' => 503,
                'template' => null,
            ];
        }

        return $this->ordinary->data();
    }

    private function managedHoldActive(): bool
    {
        try {
            return $this->gate->active();
        } catch (Throwable) {
            return true;
        }
    }
}
