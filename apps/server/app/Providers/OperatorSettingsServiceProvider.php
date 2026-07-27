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
 * containers without a restart. applyOverrides() itself is fail-safe — an
 * unreadable store or a corrupt secret lands the env baseline, never a stale or
 * half-applied config — and this catch is a final guard around resolution.
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
