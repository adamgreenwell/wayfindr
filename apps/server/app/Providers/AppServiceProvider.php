<?php

namespace App\Providers;

use App\Policies\AlertPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(DatabaseNotification::class, AlertPolicy::class);

        $this->configureRateLimiters();
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for(
            'widget-bootstrap',
            fn (Request $request): Limit => $this->widgetLimit($request, 'bootstrap_per_minute', 'bootstrap')
        );

        RateLimiter::for(
            'widget-broadcast-auth',
            fn (Request $request): Limit => $this->widgetLimit($request, 'broadcast_auth_per_minute', 'broadcast-auth')
        );

        RateLimiter::for(
            'widget-conversation',
            fn (Request $request): Limit => $this->widgetLimit($request, 'conversation_per_minute', 'conversation')
        );

        RateLimiter::for(
            'widget-message',
            fn (Request $request): Limit => $this->widgetLimit($request, 'message_per_minute', 'message')
        );

        RateLimiter::for(
            'widget-cobrowse',
            fn (Request $request): Limit => $this->widgetLimit($request, 'cobrowse_per_minute', 'cobrowse')
        );

        RateLimiter::for(
            'widget-attachment',
            fn (Request $request): Limit => $this->widgetLimit($request, 'attachment_per_minute', 'attachment')
        );

        // Inbound integration webhooks are per-connection (the route binds a
        // connection) and bursty; a generous per-connection ceiling keeps a
        // noisy or hostile source from flooding without blocking normal
        // issue-event traffic.
        // Inbound integration webhooks are per-connection (the route binds a
        // connection) and bursty; a generous per-connection ceiling keeps a
        // noisy or hostile source from flooding without blocking normal
        // issue-event traffic.
        RateLimiter::for('integrations-webhook', function (Request $request): Limit {
            $connection = $request->route('connection');
            $key = $connection instanceof Model ? (string) $connection->getKey() : (string) $request->ip();

            return Limit::perMinute(120)->by('integrations-webhook:'.$key);
        });
    }

    private function widgetLimit(Request $request, string $configKey, string $scope): Limit
    {
        $limit = max(1, (int) config("wayfindr.widget_rate_limits.{$configKey}", 60));

        return Limit::perMinute($limit)->by($this->widgetRateLimitKey($request, $scope));
    }

    private function widgetRateLimitKey(Request $request, string $scope): string
    {
        return implode('|', [
            $scope,
            $request->ip() ?? 'unknown-ip',
            hash('sha256', $this->widgetSitePublicKeyForRateLimit($request)),
        ]);
    }

    private function widgetSitePublicKeyForRateLimit(Request $request): string
    {
        $sitePublicKey = $request->input('site_public_key');

        return is_scalar($sitePublicKey) ? (string) $sitePublicKey : 'unknown-site';
    }
}
