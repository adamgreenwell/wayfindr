<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateApiToken;
use App\Models\ApiToken;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Ticket;
use App\Observers\ConversationMessageObserver;
use App\Observers\ConversationObserver;
use App\Observers\TicketObserver;
use App\Policies\AlertPolicy;
use App\Support\AgentWebPushChannel;
use App\Support\AgentWebPushFactory;
use App\Support\Attachments\Scanning\AttachmentScanner;
use App\Support\Attachments\Scanning\ClamAvScanner;
use App\Support\Attachments\Scanning\NullScanner;
use App\Support\Auth\Oidc\OidcClient;
use App\Support\Auth\Oidc\SocialiteOidcClient;
use App\Support\Automation\AutomationExecutionGuard;
use App\Support\Backup\DatabaseDumper;
use App\Support\Backup\DatabaseRestorer;
use App\Support\Backup\PostgresDatabaseDumper;
use App\Support\Backup\PostgresDatabaseRestorer;
use App\Support\Release\CheckRegistry;
use App\Support\Release\UpgradeContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\ReportHandler;
use NotificationChannels\WebPush\WebPushChannel;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One observation of pre-migration state per process. The guard records
        // whether the database was empty on `CommandStarting`, and the recorder
        // reads it back on `CommandFinished` — migrating between those two points
        // is precisely what destroys the evidence, so a per-resolution instance
        // would hand the recorder a reading taken after the fact.
        $this->app->singleton(UpgradeContext::class);

        // Shared, so that `register()` reaches the guard. Resolved per call it
        // built a fresh registry each time, which made registering a check from
        // anywhere but its own constructor silently do nothing.
        $this->app->singleton(CheckRegistry::class);
        $this->app->singleton(AutomationExecutionGuard::class);

        // Backups dump Postgres with pg_dump and restore with psql; tests bind
        // fakes so archive assembly and restore logic run without a live server.
        $this->app->bind(DatabaseDumper::class, PostgresDatabaseDumper::class);
        $this->app->bind(DatabaseRestorer::class, PostgresDatabaseRestorer::class);
        $this->app->bind(OidcClient::class, SocialiteOidcClient::class);

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
        // Browser subscriptions contain an outbound URL. Replace the package's
        // stock channel with Wayfindr's DNS-pinned transport and retry-aware
        // report handling. A transient report must fail the queued listener;
        // the package otherwise emits an event and silently returns success.
        $this->app->bind(WebPushChannel::class, fn (): AgentWebPushChannel => new AgentWebPushChannel(
            $this->app->make(AgentWebPushFactory::class)->make(),
            $this->app->make(ReportHandler::class),
        ));

        Gate::policy(DatabaseNotification::class, AlertPolicy::class);

        Conversation::observe(ConversationObserver::class);
        ConversationMessage::observe(ConversationMessageObserver::class);
        Ticket::observe(TicketObserver::class);

        $this->configureRateLimiters();
    }

    private function configureRateLimiters(): void
    {
        // Asking for a reset link is unauthenticated and sends mail, so it is
        // throttled on both the address and the source. Keying on the address
        // alone would let one attacker deny an agent their own recovery;
        // keying on the source alone would let a distributed one farm a single
        // address.
        RateLimiter::for('password-reset-request', fn (Request $request): array => [
            Limit::perMinute(5)->by('password-reset-request-ip:'.$request->ip()),
            Limit::perMinutes(15, 5)->by('password-reset-request-email:'.Str::lower((string) $request->input('email'))),
        ]);

        // SUBMITTING a reset carries its own quota, deliberately separate from
        // the one above. Sharing a bucket meant an attacker could spend an
        // agent's completion allowance just by requesting links for their
        // address, and keep doing it every window -- the agent's valid token
        // would be refused before it was read. Keyed on the source only, for
        // the same reason: an address-keyed bucket here is the denial.
        RateLimiter::for('password-reset-submit', fn (Request $request): Limit => Limit::perMinute(10)->by(
            'password-reset-submit-ip:'.$request->ip()
        ));

        RateLimiter::for('two-factor-challenge', fn (Request $request): array => [
            Limit::perMinute(5)->by('two-factor-challenge-ip:'.$request->ip()),
            Limit::perHour(25)->by('two-factor-challenge-user:'.(string) data_get(
                $request->session()->get('auth.two_factor_challenge'),
                'user_id',
                'missing',
            )),
        ]);

        RateLimiter::for('two-factor-confirmation', fn (Request $request): array => [
            Limit::perMinute(5)->by('two-factor-confirmation-user:'.(string) $request->user()?->getAuthIdentifier()),
            Limit::perMinute(15)->by('two-factor-confirmation-ip:'.$request->ip()),
        ]);

        // A browser calls this after a realtime subscription succeeds. Key it
        // to the authenticated agent so one reconnecting desk cannot spend
        // another agent's catch-up budget behind a shared address.
        RateLimiter::for('agent-alert-reconcile', fn (Request $request): Limit => Limit::perMinute(30)->by(
            'agent-alert-reconcile-user:'.(string) $request->user()?->getAuthIdentifier()
        ));

        // Every visible tab acknowledges the same live event. Isolate the
        // quota by exact alert version so duplicate tabs cannot spend the
        // agent's allowance for later alerts; hash the unvalidated input so
        // cache keys never retain identifiers or attacker-controlled length.
        RateLimiter::for('agent-alert-realtime-receipt', function (Request $request): array {
            $agent = (string) $request->user()?->getAuthIdentifier();
            $exactAlert = hash('sha256', (string) json_encode([
                $request->input('alert_id'),
                $request->input('version'),
            ]));

            return [
                // Bound a compromised authenticated browser without making a
                // normal multi-tab alert burst compete for a tiny global pool.
                Limit::perMinute(6000)->by('agent-alert-realtime-receipt-user:'.$agent),
                Limit::perMinute(120)->by(
                    'agent-alert-realtime-receipt-user:'.$agent.':alert:'.$exactAlert
                ),
            ];
        });

        RateLimiter::for('oidc-redirect', fn (Request $request): array => [
            Limit::perMinute(10)->by('oidc-redirect-ip:'.$request->ip()),
            Limit::perMinutes(15, 20)->by(
                'oidc-redirect-account-source:'
                .Str::lower((string) $request->input('account_slug'))
                .'|'.$request->ip()
            ),
        ]);

        RateLimiter::for('oidc-callback', fn (Request $request): Limit => Limit::perMinute(20)->by(
            'oidc-callback-ip:'.$request->ip()
        ));

        RateLimiter::for(
            'widget-bootstrap',
            fn (Request $request): Limit => $this->widgetLimit($request, 'bootstrap_per_minute', 'bootstrap')
        );

        // Presence reports at 45-second intervals, so a genuine tab makes about
        // 1.33 requests a minute and 80 an hour.
        //
        // TWO limits, because one cannot do this job. Every other widget
        // limiter is keyed by site and source IP, which is right for endpoints
        // a visitor hits occasionally -- but this one every visitor hits
        // continuously, so a shared per-IP bucket divides by the number of
        // people behind the address. An office, a school or a carrier NAT would
        // have put roughly sixteen simultaneous visitors over the old ceiling,
        // and the symptom is not an error anybody reports: valid heartbeats
        // take a 429 and those visitors flicker to inactive on the board.
        //
        // So the everyday quota is per VISITOR, and a much higher per-IP
        // ceiling stays as the abuse cap -- which is the limit that actually
        // wants to be there, because the thing worth bounding is a forged
        // client rotating anonymous IDs to create rows, not a busy office.
        // Public site configuration, read once per page load. Sized for that
        // rather than for the panel being opened: a page view is not a visitor
        // doing anything, and several people behind one address browsing
        // normally must not be able to spend a budget that gates starting a
        // conversation. The response is identical for everyone on the site and
        // writes nothing, so it is cheap to serve and safe to allow generously.
        RateLimiter::for(
            'widget-config',
            fn (Request $request): Limit => $this->widgetLimit($request, 'config_per_minute', 'config')
        );

        RateLimiter::for('widget-presence', fn (Request $request): array => [
            $this->widgetLimit($request, 'presence_per_minute', 'presence')
                ->by($this->widgetPresenceVisitorKey($request)),
            $this->widgetLimit($request, 'presence_per_ip_per_minute', 'presence-ip'),
        ]);

        RateLimiter::for('widget-proactive', fn (Request $request): array => [
            $this->widgetLimit($request, 'proactive_per_minute', 'proactive')
                ->by($this->widgetVisitorKey($request, 'proactive-visitor')),
            $this->widgetLimit($request, 'proactive_per_ip_per_minute', 'proactive-ip'),
        ]);

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
        // Per token, not per IP (ADR 0018). An integration runs from one host,
        // so an IP limit would make two tokens on the same server throttle each
        // other; and a token moving between hosts would carry no history at
        // all.
        //
        // This runs AFTER authentication, so the token is always present -- an
        // unauthenticated request never reaches it. The first version of this
        // carried an IP fallback for that case, which was unreachable code
        // describing something that cannot happen.
        RateLimiter::for('api-token', function (Request $request): Limit {
            $token = $request->attributes->get(AuthenticateApiToken::ATTRIBUTE);
            $limit = max(1, (int) config('wayfindr.api_rate_limit', 120));

            return Limit::perMinute($limit)->by('api-token:'.($token instanceof ApiToken ? (string) $token->getKey() : 'unauthenticated'));
        });

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

    /**
     * The heartbeat's everyday quota belongs to one visitor, not one address.
     *
     * Falls back to the IP-scoped key when no anonymous ID is present. That is
     * not a loophole -- the endpoint requires one, so a request without it is
     * rejected by validation before it can spend the quota, and keying those to
     * the address means a client sending malformed requests cannot mint an
     * unlimited number of empty buckets.
     */
    private function widgetPresenceVisitorKey(Request $request): string
    {
        return $this->widgetVisitorKey($request, 'presence-visitor');
    }

    private function widgetVisitorKey(Request $request, string $scope): string
    {
        $anonymousId = $request->input('anonymous_id');

        if (! is_scalar($anonymousId) || (string) $anonymousId === '') {
            return $this->widgetRateLimitKey($request, $scope.'-anonymous');
        }

        return implode('|', [
            $scope,
            hash('sha256', $this->widgetSitePublicKeyForRateLimit($request)),
            hash('sha256', (string) $anonymousId),
        ]);
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
