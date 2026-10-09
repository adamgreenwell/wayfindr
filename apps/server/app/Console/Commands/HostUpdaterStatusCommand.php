<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Updates\HostUpdaterClient;
use App\Support\Updates\HostUpdaterException;
use App\Support\Updates\InstallationCapabilities;
use Illuminate\Console\Command;
use Throwable;

/** Inspection only. The independent host CLI also works while Laravel is down. */
final class HostUpdaterStatusCommand extends Command
{
    protected $signature = 'wayfindr:updater-status {operation? : Operation UUID; omit to inspect the active operation} {--logs : Include the first bounded page of operation events} {--json : Emit the authenticated helper response as JSON} {--protocol-contract : Emit the application protocol contract without connecting to the helper}';

    protected $description = 'Inspect the host updater operation without changing it';

    public function handle(HostUpdaterClient $updater): int
    {
        try {
            $operation = $this->argument('operation');
            $operation = is_string($operation) && $operation !== '' ? $operation : null;

            if ($this->option('protocol-contract')) {
                if ($operation !== null || $this->option('logs')) {
                    throw new HostUpdaterException('helper_request_invalid');
                }

                $this->line(json_encode([
                    'schema' => 1,
                    'protocol' => InstallationCapabilities::HELPER_PROTOCOL,
                    'minimum_helper_version' => InstallationCapabilities::MINIMUM_HELPER_VERSION,
                    'capabilities' => InstallationCapabilities::REQUIRED_HELPER_CAPABILITIES,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            if ($this->option('logs') && $operation === null) {
                throw new HostUpdaterException('helper_request_invalid');
            }

            $result = ['schema' => 1, 'status' => $updater->status($operation)];

            if ($this->option('logs')) {
                $result['logs'] = $updater->logs($operation);
            }

            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $this->info('Host updater status');
                $snapshot = $result['status'];
                $this->line('Journal revision: '.$snapshot['revision']);
                $operation = $snapshot['operation'];

                if ($operation === null) {
                    $this->line('No active operation.');
                } else {
                    $this->line('Operation: '.$operation['operation_id']);
                    $this->line('Release: '.$operation['release_tag']);
                    $this->line('Phase: '.$operation['phase'].' (checkpoint: '.$operation['checkpoint'].')');

                    if ($operation['error'] !== null) {
                        $this->warn('Reason: '.$operation['error']);
                    }
                }

                foreach ($result['logs']['events'] ?? [] as $event) {
                    $this->line($event['revision'].': '.$event['code'].' ('.$event['phase'].')');
                }

                if (($result['logs']['has_more'] ?? false) === true) {
                    $this->line('More events are available through the host updater CLI.');
                }
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $reason = $exception instanceof HostUpdaterException ? $exception->reason : 'helper_unavailable';

            if ($this->option('json')) {
                $this->line(json_encode(['schema' => 1, 'status' => 'failed', 'reason' => $reason], JSON_THROW_ON_ERROR));
            } else {
                $this->error('The host updater could not be verified ('.$reason.'). Use the host updater CLI if application services are stopped.');
            }

            return self::FAILURE;
        }
    }
}
