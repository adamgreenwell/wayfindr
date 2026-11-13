<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Release\UpgradeGuard;
use App\Support\Updates\ManagedUpdateGate;
use App\Support\Updates\ManagedUpdateMaintenanceMode;
use Illuminate\Console\Command;
use Throwable;

/** The host's fixed maintenance handshake; never drains or applies an update. */
final class UpgradeWindowCommand extends Command
{
    protected $signature = 'wayfindr:upgrade-window
        {operation : Update operation UUID}
        {--action=status : enter, status, or release}
        {--json : Emit the redacted host receipt}';

    protected $description = 'Enter, inspect, or release one managed update operation hold';

    public function handle(ManagedUpdateGate $gate, UpgradeGuard $guard): int
    {
        try {
            $operation = (string) $this->argument('operation');
            $action = $this->option('action');

            if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $operation) !== 1
                || ! in_array($action, ['enter', 'status', 'release'], true)) {
                throw new \RuntimeException('managed_update_request_invalid');
            }

            $state = match ($action) {
                'enter' => $gate->enter($operation),
                'release' => $gate->release($operation),
                default => $gate->status(),
            };

            // Inspection can report an empty window, but must never label a
            // different operation's hold as the caller's recovery boundary.
            if ($state['held'] && $state['operation_id'] !== $operation) {
                throw new \RuntimeException('managed_update_busy');
            }

            $maintenance = app()->maintenanceMode();
            $version = config('wayfindr.release.version');
            $commit = config('wayfindr.release.commit');
            $result = [
                'schema' => 1,
                'operation_id' => $state['operation_id'],
                'held' => $state['held'],
                'ordinary_maintenance' => $maintenance instanceof ManagedUpdateMaintenanceMode
                    ? $maintenance->ordinaryActive() : $maintenance->active(),
                'source' => [
                    'version' => is_string($version) && preg_match('/^v?(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $version) === 1
                        ? (str_starts_with($version, 'v') ? substr($version, 1) : $version) : null,
                    'commit' => is_string($commit) && preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/Di', $commit) === 1
                        ? strtolower($commit) : null,
                    'profile' => $guard->installationProfile(),
                ],
                'ledger_supported' => rtrim((string) config('wayfindr.erasure.ledger_path'), '/') === storage_path('app/erasure-ledger'),
            ];
        } catch (Throwable $exception) {
            $reason = in_array($exception->getMessage(), [
                'managed_update_request_invalid', 'managed_update_busy',
                'managed_update_state_invalid', 'managed_update_state_unavailable',
            ], true) ? $exception->getMessage() : 'managed_update_state_unavailable';

            if ($this->option('json')) {
                $this->line(json_encode(['schema' => 1, 'status' => 'failed', 'reason' => $reason], JSON_THROW_ON_ERROR));
            } else {
                $this->error('The managed update window could not be changed or verified ('.$reason.').');
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info($result['held'] ? 'This operation holds managed update maintenance.' : 'No managed update operation holds maintenance.');
            $this->line('Ordinary maintenance: '.($result['ordinary_maintenance'] ? 'active' : 'inactive').'.');
        }

        return self::SUCCESS;
    }
}
