<?php

namespace App\Providers;

use App\Support\Settings\OperatorSettings;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Applies operator-set config overrides at runtime (ADR 0011).
 *
 * The web tier runs per request (FrankenPHP classic, no Octane), so boot()
 * applies fresh overrides on every request. Long-running queue workers re-apply
 * before each job, so a setting changed in the browser is live across all
 * containers without a restart. Fails safe: if the database or cache is not
 * reachable yet (fresh boot / mid-migration), nothing is applied and the env
 * defaults stand.
 */
class OperatorSettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OperatorSettings::class);
    }

    public function boot(): void
    {
        $this->applyOverrides();

        Event::listen(JobProcessing::class, function (): void {
            $this->applyOverrides();
        });
    }

    private function applyOverrides(): void
    {
        try {
            $this->app->make(OperatorSettings::class)->applyOverrides();
        } catch (Throwable) {
            // Database/cache not reachable yet (fresh boot or mid-migration):
            // env defaults stand until the operator_settings table exists.
        }
    }
}
