<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\OidcConnection;
use App\Models\OidcIdentity;
use App\Models\User;
use App\Support\Auth\Oidc\OidcClient;
use App\Support\Auth\Oidc\OidcSignInRecorder;
use App\Support\Auth\Oidc\OidcUser;
use App\Support\Auth\PendingTwoFactorChallenge;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class OidcSessionController extends Controller
{
    public const SESSION_KEY = 'auth.oidc';

    public const LIFETIME_SECONDS = 300;

    /** @var list<string> */
    private const PROTOCOL_SESSION_KEYS = [
        'state',
        'code_verifier',
        'openidconnect_nonce',
    ];

    public function redirect(Request $request, OidcClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'account_slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
        ]);

        $connection = OidcConnection::query()
            ->where('is_enabled', true)
            ->whereHas('account', fn ($query) => $query->where('slug', $validated['account_slug']))
            ->first();

        if ($connection === null) {
            return $this->failed($request);
        }

        $this->clearAttempt($request);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, [
            'connection_public_id' => $connection->public_id,
            'configuration_version' => $connection->configuration_version,
            'started_at' => now()->timestamp,
        ]);

        try {
            return $client->redirect($request, $connection);
        } catch (Throwable $exception) {
            Log::warning('OIDC sign-in could not be started.', [
                'connection_public_id' => $connection->public_id,
                'exception_class' => $exception::class,
            ]);

            return $this->failed($request);
        }
    }

    public function callback(
        Request $request,
        string $connectionPublicId,
        OidcClient $client,
        OidcSignInRecorder $signIns,
    ): RedirectResponse {
        $connection = $this->pendingConnection($request, $connectionPublicId);

        if ($connection === null) {
            return $this->failed($request);
        }

        try {
            $providerUser = $client->user($request, $connection);
            $resolved = $this->resolveIdentity($connection, $providerUser);
        } catch (Throwable $exception) {
            Log::warning('OIDC sign-in failed.', [
                'connection_public_id' => $connection->public_id,
                'exception_class' => $exception::class,
            ]);

            return $this->failed($request);
        } finally {
            $this->clearAttempt($request);
        }

        $user = $resolved['user'];
        $identity = $resolved['identity'];
        $federatedContext = [
            'oidc_connection_id' => $connection->id,
            'oidc_configuration_version' => $connection->configuration_version,
            'oidc_identity_id' => $identity->id,
        ];
        $request->session()->regenerate();
        $request->session()->put(
            'password_hash_'.Auth::getDefaultDriver(),
            Auth::guard('web')->hashPasswordForCookie((string) $user->getAuthPassword()),
        );

        if ($user->hasTwoFactorAuthentication()) {
            $request->session()->put(TwoFactorChallengeController::SESSION_KEY, [
                'user_id' => $user->getKey(),
                'remember' => false,
                'started_at' => now()->timestamp,
                'credential_fingerprint' => PendingTwoFactorChallenge::credentialFingerprint($user),
                ...$federatedContext,
            ]);

            return redirect()->route('two-factor.challenge');
        }

        if (! $signIns->complete($user, $federatedContext)) {
            return $this->failed($request);
        }

        Auth::guard('web')->login($user, false);
        $request->session()->regenerate();

        if ($user->account?->requires_two_factor) {
            return redirect()->route('dashboard.profile.show');
        }

        return redirect()->intended(route('dashboard'));
    }

    private function pendingConnection(Request $request, string $publicId): ?OidcConnection
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending)
            || ! is_string($pending['connection_public_id'] ?? null)
            || ! is_string($pending['configuration_version'] ?? null)
            || ! is_numeric($pending['started_at'] ?? null)
            || now()->timestamp - (int) $pending['started_at'] > self::LIFETIME_SECONDS
            || ! hash_equals($pending['connection_public_id'], $publicId)) {
            return null;
        }

        $connection = OidcConnection::query()
            ->where('public_id', $publicId)
            ->where('is_enabled', true)
            ->first();

        return $connection
            && hash_equals($pending['configuration_version'], $connection->configuration_version)
            ? $connection
            : null;
    }

    /** @return array{user: User, identity: OidcIdentity} */
    private function resolveIdentity(OidcConnection $connection, OidcUser $providerUser): array
    {
        try {
            return DB::transaction(function () use ($connection, $providerUser): array {
                $identityPointer = OidcIdentity::query()
                    ->where('oidc_connection_id', $connection->id)
                    ->where('subject', $providerUser->subject)
                    ->first();

                $user = $identityPointer === null
                    ? $this->unlinkedUser($connection, $providerUser)
                    : User::query()->lockForUpdate()->find($identityPointer->user_id);

                if ($user === null
                    || $user->isDeactivated()
                    || $user->isPlatformOperator()
                    || (int) $user->account_id !== (int) $connection->account_id) {
                    throw new RuntimeException('OIDC identity is not eligible.');
                }

                $lockedConnection = OidcConnection::query()->lockForUpdate()->find($connection->id);

                if ($lockedConnection === null
                    || ! $lockedConnection->is_enabled
                    || ! hash_equals($connection->configuration_version, $lockedConnection->configuration_version)) {
                    throw new RuntimeException('OIDC connection changed during sign-in.');
                }

                $identity = OidcIdentity::query()
                    ->where('oidc_connection_id', $lockedConnection->id)
                    ->where('subject', $providerUser->subject)
                    ->lockForUpdate()
                    ->first();
                $wasLinked = $identity !== null;

                if ($identity !== null && (int) $identity->user_id !== (int) $user->id) {
                    throw new RuntimeException('OIDC subject is already linked.');
                }

                if ($identity === null) {
                    if (OidcIdentity::query()->where('user_id', $user->id)->exists()) {
                        throw new RuntimeException('Wayfindr user is already linked.');
                    }

                    $identity = OidcIdentity::query()->create([
                        'oidc_connection_id' => $lockedConnection->id,
                        'user_id' => $user->id,
                        'subject' => $providerUser->subject,
                    ]);
                }

                if (! $wasLinked) {
                    $this->audit($user, $identity, 'agent.oidc_identity_linked');
                }

                return ['user' => $user, 'identity' => $identity];
            });
        } catch (QueryException) {
            throw new RuntimeException('OIDC identity could not be linked.');
        }
    }

    private function unlinkedUser(OidcConnection $connection, OidcUser $providerUser): ?User
    {
        $email = is_string($providerUser->email) ? Str::lower(trim($providerUser->email)) : '';

        if (! $providerUser->emailVerified
            || $email === ''
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $matches = User::query()
            ->where('account_id', $connection->account_id)
            ->whereNotNull('email_verified_at')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->lockForUpdate()
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function audit(User $user, OidcIdentity $identity, string $action): void
    {
        AuditEvent::query()->create([
            'account_id' => $user->account_id,
            'actor_type' => $user->getMorphClass(),
            'actor_id' => $user->id,
            'subject_type' => $identity->getMorphClass(),
            'subject_id' => $identity->id,
            'action' => $action,
            'metadata' => [],
            'occurred_at' => now(),
        ]);
    }

    private function failed(Request $request): RedirectResponse
    {
        $this->clearAttempt($request);

        return redirect()
            ->route('login')
            ->withErrors(['account_slug' => __('oidc.sign_in.failed')]);
    }

    private function clearAttempt(Request $request): void
    {
        $request->session()->forget([self::SESSION_KEY, ...self::PROTOCOL_SESSION_KEYS]);
    }
}
