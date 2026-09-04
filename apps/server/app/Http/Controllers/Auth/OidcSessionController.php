<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\AccountRole;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CustomRole;
use App\Models\OidcConnection;
use App\Models\OidcIdentity;
use App\Models\OidcRoleMapping;
use App\Models\User;
use App\Support\AgentRealtimeSessions;
use App\Support\Auth\Oidc\OidcClient;
use App\Support\Auth\Oidc\OidcSignInRecorder;
use App\Support\Auth\Oidc\OidcUser;
use App\Support\Auth\PendingTwoFactorChallenge;
use App\Support\Sites\SiteManagerCoverage;
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

    public function __construct(
        private readonly SiteManagerCoverage $siteManagerCoverage,
        private readonly AgentRealtimeSessions $agentRealtimeSessions,
    ) {}

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
            $resolved = DB::transaction(function () use ($connection, $providerUser): array {
                $this->siteManagerCoverage->lockAccount((int) $connection->account_id);
                $identityPointer = OidcIdentity::query()
                    ->where('oidc_connection_id', $connection->id)
                    ->where('subject', $providerUser->subject)
                    ->first();
                $user = $identityPointer instanceof OidcIdentity
                    ? User::query()->lockForUpdate()->find($identityPointer->user_id)
                    : $this->unlinkedUser($connection, $providerUser);
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
                $wasProvisioned = false;

                if ($identity === null && $user === null && $lockedConnection->jit_provisioning_enabled) {
                    $role = $this->mappedRole($lockedConnection, $providerUser);
                    $user = $this->provisionUser($lockedConnection, $providerUser, $role);
                    $wasProvisioned = true;
                }

                if ($user === null
                    || $user->isDeactivated()
                    || $user->isPlatformOperator()
                    || (int) $user->account_id !== (int) $lockedConnection->account_id) {
                    throw new RuntimeException('OIDC identity is not eligible.');
                }

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
                        'provisioned_at' => $user->oidc_provisioned_at,
                    ]);
                }

                $roleChanged = false;

                if ($identity->provisioned_at !== null) {
                    if ($user->account_role === AccountRole::Owner) {
                        throw new RuntimeException('Account owners cannot be managed by OIDC role claims.');
                    }

                    $roleChanged = $this->applyMappedRole(
                        $user,
                        $identity,
                        $lockedConnection,
                        $this->mappedRole($lockedConnection, $providerUser),
                    );
                }

                if (! $wasLinked) {
                    $this->audit($user, $identity, $lockedConnection, 'agent.oidc_identity_linked');
                }

                if ($wasProvisioned) {
                    $this->audit($user, $identity, $lockedConnection, 'agent.oidc_provisioned', [
                        ...$this->roleMetadata($user),
                    ]);
                }

                return ['user' => $user, 'identity' => $identity, 'role_changed' => $roleChanged];
            });
        } catch (QueryException) {
            throw new RuntimeException('OIDC identity could not be linked.');
        }

        if ($resolved['role_changed']) {
            $this->agentRealtimeSessions->disconnectMany([$resolved['user']->id]);
        }

        return ['user' => $resolved['user'], 'identity' => $resolved['identity']];
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

    private function provisionUser(
        OidcConnection $connection,
        OidcUser $providerUser,
        AccountRole|CustomRole $role,
    ): User {
        $email = $this->verifiedEmail($providerUser);

        if ($email === null || User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw new RuntimeException('OIDC email is not available for provisioning.');
        }

        $name = is_string($providerUser->name) && trim($providerUser->name) !== ''
            ? Str::limit(trim($providerUser->name), 255, '')
            : Str::limit($email, 255, '');
        $user = new User;
        $user->forceFill([
            'account_id' => $connection->account_id,
            'account_role' => $role instanceof CustomRole ? AccountRole::Agent : $role,
            'custom_role_id' => $role instanceof CustomRole ? $role->id : null,
            'platform_role' => null,
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'oidc_provisioned_at' => now(),
            // Local password recovery stays available, but the provider never
            // supplies or learns a reusable Wayfindr credential.
            'password' => Str::random(64),
        ])->save();

        return $user;
    }

    private function verifiedEmail(OidcUser $providerUser): ?string
    {
        $email = is_string($providerUser->email) ? Str::lower(trim($providerUser->email)) : '';

        return $providerUser->emailVerified
            && $email !== ''
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
                ? $email
                : null;
    }

    private function mappedRole(OidcConnection $connection, OidcUser $providerUser): AccountRole|CustomRole
    {
        if (! is_string($connection->role_claim)
            || trim($connection->role_claim) === ''
            || $providerUser->roleClaimValues === []) {
            throw new RuntimeException('OIDC role claim has no configured match.');
        }

        $mappings = OidcRoleMapping::query()
            ->where('oidc_connection_id', $connection->id)
            ->whereIn('claim_value', $providerUser->roleClaimValues)
            ->lockForUpdate()
            ->get();
        $targetKeys = $mappings
            ->map(fn (OidcRoleMapping $mapping): string => $mapping->custom_role_id !== null
                ? 'custom:'.$mapping->custom_role_id
                : 'built_in:'.$mapping->built_in_role?->value)
            ->unique()
            ->values();

        if ($mappings->isEmpty() || $targetKeys->count() !== 1) {
            throw new RuntimeException('OIDC role claim mapping is missing or ambiguous.');
        }

        $mapping = $mappings->firstOrFail();

        if ($mapping->custom_role_id !== null) {
            $role = CustomRole::query()
                ->whereKey($mapping->custom_role_id)
                ->where('account_id', $connection->account_id)
                ->lockForUpdate()
                ->first();

            if ($role instanceof CustomRole) {
                return $role;
            }
        }

        if (in_array($mapping->built_in_role, [AccountRole::Admin, AccountRole::Agent], true)) {
            return $mapping->built_in_role;
        }

        throw new RuntimeException('OIDC role claim mapping has an invalid target.');
    }

    private function applyMappedRole(
        User $user,
        OidcIdentity $identity,
        OidcConnection $connection,
        AccountRole|CustomRole $role,
    ): bool {
        $oldRole = $user->account_role;
        $oldCustomRole = $user->customRole()->first();
        $newRole = $role instanceof CustomRole ? AccountRole::Agent : $role;
        $newCustomRoleId = $role instanceof CustomRole ? $role->id : null;

        if ($oldRole === $newRole && (int) $user->custom_role_id === (int) $newCustomRoleId) {
            return false;
        }

        $this->siteManagerCoverage->ensureAgentRoleCanChange($user, $role);
        $user->forceFill([
            'account_role' => $newRole,
            'custom_role_id' => $newCustomRoleId,
        ])->save();
        $this->agentRealtimeSessions->requestMany([$user->id]);
        $this->audit($user, $identity, $connection, 'agent.oidc_role_mapped', [
            'old_role' => $oldCustomRole instanceof CustomRole ? 'custom:'.$oldCustomRole->id : $oldRole->value,
            'old_role_name' => $oldCustomRole?->name ?? $oldRole->value,
            ...$this->roleMetadata($user),
        ]);

        return true;
    }

    /** @return array{role: string, role_name: string} */
    private function roleMetadata(User $user): array
    {
        $customRole = $user->customRole()->first();

        return [
            'role' => $customRole instanceof CustomRole ? 'custom:'.$customRole->id : $user->account_role->value,
            'role_name' => $customRole?->name ?? $user->account_role->value,
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function audit(
        User $user,
        OidcIdentity $identity,
        OidcConnection $connection,
        string $action,
        array $metadata = [],
    ): void {
        AuditEvent::query()->create([
            'account_id' => $user->account_id,
            'actor_type' => $user->getMorphClass(),
            'actor_id' => $user->id,
            'subject_type' => $identity->getMorphClass(),
            'subject_id' => $identity->id,
            'action' => $action,
            'metadata' => ['oidc_provider_name' => $connection->name, ...$metadata],
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
