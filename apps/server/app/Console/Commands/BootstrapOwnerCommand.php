<?php

namespace App\Console\Commands;

use App\Actions\BootstrapOwner;
use Illuminate\Console\Command;
use Throwable;

class BootstrapOwnerCommand extends Command
{
    protected $signature = 'wayfindr:bootstrap-owner
        {--request-id= : Stable canonical UUID for this single bootstrap request}
        {--email= : Email address for the ordinary account owner}
        {--claim-file= : New absolute private file receiving the password-setting claim}
        {--account=Wayfindr Support : Name for the support account}
        {--slug= : Slug for the support account}
        {--name=Support Owner : Name for the owner}
        {--site=Default Site : Name for the first site}
        {--domain= : Optional domain for the first site}
        {--status : Observe the completed request and its current initial bindings only}
        {--completion-only : With --status, require the durable receipt without restricting later ownership changes}';

    protected $description = 'Bootstrap a fresh instance with an ordinary owner and a private, expiring password-setting claim.';

    public function handle(BootstrapOwner $bootstrap): int
    {
        try {
            if ($this->option('completion-only') && ! $this->option('status')) {
                $this->line('{"status":"held","code":"owner_bootstrap_invalid_request"}');

                return self::FAILURE;
            }
            if ($this->option('status')) {
                if ($this->option('email') !== null || $this->option('claim-file') !== null) {
                    $this->line('{"status":"held","code":"owner_bootstrap_invalid_request"}');

                    return self::FAILURE;
                }

                $receipt = $bootstrap->status((string) $this->option('request-id'));
                $this->line(json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

                return $this->option('completion-only') || $receipt['initial_binding_ready'] ? self::SUCCESS : self::FAILURE;
            }

            $receipt = $bootstrap->handle([
                'request_id' => $this->option('request-id'),
                'email' => $this->option('email'),
                'claim_file' => $this->option('claim-file'),
                'account' => $this->option('account'),
                'slug' => $this->option('slug'),
                'name' => $this->option('name'),
                'site' => $this->option('site'),
                'domain' => $this->option('domain'),
            ]);
            $this->line(json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            // Never report the exception: a broker/filesystem exception may
            // include private claim material or operator paths in its message.
            $this->line(json_encode(['status' => 'held', 'code' => BootstrapOwner::failureCode($exception)], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
