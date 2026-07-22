<?php

namespace App\Providers;

use App\Policies\AlertPolicy;
use App\Support\Attachments\Scanning\AttachmentScanner;
use App\Support\Attachments\Scanning\ClamAvScanner;
use App\Support\Attachments\Scanning\NullScanner;
use App\Support\Backup\DatabaseDumper;
use App\Support\Backup\DatabaseRestorer;
use App\Support\Backup\PostgresDatabaseDumper;
use App\Support\Backup\PostgresDatabaseRestorer;
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
        // Backups dump Postgres with pg_dump and restore with psql; tests bind
        // fakes so archive assembly and restore logic run without a live server.
        $this->app->bind(DatabaseDumper::class, PostgresDatabaseDumper::class);
        $this->app->bind(DatabaseRestorer::class, PostgresDatabaseRestorer::class);

        // Select the attachment malware scanner from config. An unset/null
        // driver is accept-with-defense-in-depth; 'clamav' scans every upload
        // against a local clamd. An unknown value (e.g. a typo of clamav) throws
        // rather than silently falling back to no scanning — a misconfigured
        // security control should fail loudly, not disable itself.
        $this->app->singleton(AttachmentScanner::class, function (): AttachmentScanner {
            $driver = strtolower(trim((string) config('wayfindr.attachments.scanner.driver')));

            if ($driver === '' || $driver === 'null' || $driver === 'none') {
                return new NullScanner;
            }

            if ($driver === 'clamav') {
                return new ClamAvScanner(
                    (string) config('wayfindr.attachments.scanner.clamav.socket', 'tcp://127.0.0.1:3310'),
                    (int) config('wayfindr.attachments.scanner.timeout_seconds', 30),
                );
            }

            throw new \InvalidArgumentException(sprintf(
                "Unknown attachment scanner driver [%s]. Set WAYFINDR_ATTACHMENT_SCANNER to 'clamav' or leave it unset.",
                $driver,
            ));
        });
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

        RateLimiter::for(
            'widget-attachment-upload',
            fn (Request $request): Limit => $this->widgetLimit($request, 'attachment_upload_per_minute', 'attachment-upload')
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
