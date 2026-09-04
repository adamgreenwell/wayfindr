<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\AuditEvent;
use App\Models\CustomRole;
use App\Models\OidcConnection;
use App\Models\OidcRoleMapping;
use App\Models\User;
use App\Support\Auth\PendingTwoFactorChallenge;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AgentAccountOidcProvisioningController extends Controller
{
    public function __construct(private readonly SiteManagerCoverage $siteManagerCoverage) {}

    public function update(Request $request): RedirectResponse
    {
        $actor = $this->initialActor($request);
        $validated = $request->validate([
            'role_claim' => ['required', 'string', 'max:255'],
            'jit_provisioning_enabled' => ['nullable', 'boolean'],
        ]);
        $roleClaim = trim($validated['role_claim']);
        $enabled = $request->boolean('jit_provisioning_enabled');
        $this->ensurePrintableValue($roleClaim, 'role_claim');
        $credentialFingerprint = PendingTwoFactorChallenge::credentialFingerprint($actor);

        DB::transaction(function () use ($actor, $roleClaim, $enabled, $credentialFingerprint): void {
            $actor = $this->lockedOwner($actor, $credentialFingerprint);
            $connection = $this->lockedConnection($actor);

            if ($enabled && ! $connection->roleMappings()->exists()) {
                throw ValidationException::withMessages([
                    'jit_provisioning_enabled' => __('oidc.provisioning.mapping_required'),
                ]);
            }

            if ($connection->role_claim === $roleClaim
                && $connection->jit_provisioning_enabled === $enabled) {
                return;
            }

            $connection->forceFill([
                'role_claim' => $roleClaim,
                'jit_provisioning_enabled' => $enabled,
                'configuration_version' => (string) Str::uuid(),
            ])->save();

            $this->audit($actor, $connection, 'account.oidc_provisioning_updated', [
                'enabled' => $enabled,
                'role_claim' => $roleClaim,
                'mapping_count' => $connection->roleMappings()->count(),
            ]);
        });

        return $this->redirectWithStatus('oidc.flash.provisioning_updated');
    }

    public function storeMapping(Request $request): RedirectResponse
    {
        $actor = $this->initialActor($request);
        $validated = $request->validate([
            'claim_value' => ['required', 'string', 'max:255'],
            'role_target' => ['required', 'string', 'max:100'],
        ]);
        $claimValue = trim($validated['claim_value']);
        $this->ensurePrintableValue($claimValue, 'claim_value');
        $credentialFingerprint = PendingTwoFactorChallenge::credentialFingerprint($actor);

        try {
            DB::transaction(function () use ($actor, $claimValue, $validated, $credentialFingerprint): void {
                $actor = $this->lockedOwner($actor, $credentialFingerprint);
                $connection = $this->lockedConnection($actor);
                [$builtInRole, $customRole] = $this->lockedRoleTarget($actor, $validated['role_target']);

                $mapping = OidcRoleMapping::query()->create([
                    'oidc_connection_id' => $connection->id,
                    'claim_value' => $claimValue,
                    'built_in_role' => $builtInRole,
                    'custom_role_id' => $customRole?->id,
                ]);
                $connection->forceFill(['configuration_version' => (string) Str::uuid()])->save();

                $this->audit($actor, $connection, 'account.oidc_role_mapping_created', [
                    'mapping_id' => $mapping->id,
                    'claim_value' => $mapping->claim_value,
                    'role' => $this->roleKey($builtInRole, $customRole),
                    'role_name' => $customRole?->name ?? $builtInRole?->value,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'claim_value' => __('oidc.provisioning.duplicate_mapping'),
            ]);
        }

        return $this->redirectWithStatus('oidc.flash.mapping_created');
    }

    public function destroyMapping(Request $request, string $mapping): RedirectResponse
    {
        $actor = $this->initialActor($request);
        abort_unless(ctype_digit($mapping), 404);
        $credentialFingerprint = PendingTwoFactorChallenge::credentialFingerprint($actor);

        DB::transaction(function () use ($actor, $mapping, $credentialFingerprint): void {
            $actor = $this->lockedOwner($actor, $credentialFingerprint);
            $connection = $this->lockedConnection($actor);
            $mapping = OidcRoleMapping::query()
                ->whereKey((int) $mapping)
                ->where('oidc_connection_id', $connection->id)
                ->with('customRole')
                ->lockForUpdate()
                ->firstOrFail();
            $builtInRole = $mapping->built_in_role;
            $customRole = $mapping->customRole;
            $metadata = [
                'mapping_id' => $mapping->id,
                'claim_value' => $mapping->claim_value,
                'role' => $this->roleKey($builtInRole, $customRole),
                'role_name' => $customRole?->name ?? $builtInRole?->value,
            ];

            $mapping->delete();
            $connection->forceFill(['configuration_version' => (string) Str::uuid()])->save();
            $this->audit($actor, $connection, 'account.oidc_role_mapping_deleted', $metadata);
        });

        return $this->redirectWithStatus('oidc.flash.mapping_deleted');
    }

    private function initialActor(Request $request): User
    {
        $actor = $request->user();
        abort_unless(
            $actor instanceof User
            && $actor->account_id
            && $actor->hasAccountPermission(AccountPermission::ManageRoles),
            403,
        );

        return $actor;
    }

    private function lockedOwner(User $actor, string $credentialFingerprint): User
    {
        $accountId = (int) $actor->account_id;
        $this->siteManagerCoverage->lockAccount($accountId);
        $actor = User::query()
            ->whereKey($actor->id)
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->first();

        abort_unless(
            $actor instanceof User
            && ! $actor->isDeactivated()
            && $actor->hasAccountPermission(AccountPermission::ManageRoles)
            && hash_equals(
                $credentialFingerprint,
                PendingTwoFactorChallenge::credentialFingerprint($actor),
            ),
            403,
        );

        return $actor;
    }

    private function lockedConnection(User $actor): OidcConnection
    {
        return OidcConnection::query()
            ->where('account_id', $actor->account_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @return array{0: ?AccountRole, 1: ?CustomRole} */
    private function lockedRoleTarget(User $actor, string $target): array
    {
        if ($target === 'built_in:admin') {
            return [AccountRole::Admin, null];
        }

        if ($target === 'built_in:agent') {
            return [AccountRole::Agent, null];
        }

        if (preg_match('/^custom:(\d+)$/', $target, $matches) === 1) {
            $role = CustomRole::query()
                ->whereKey((int) $matches[1])
                ->where('account_id', $actor->account_id)
                ->lockForUpdate()
                ->first();

            if ($role instanceof CustomRole) {
                return [null, $role];
            }
        }

        throw ValidationException::withMessages([
            'role_target' => __('oidc.provisioning.invalid_role'),
        ]);
    }

    private function ensurePrintableValue(string $value, string $field): void
    {
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw ValidationException::withMessages([
                $field => __('oidc.provisioning.invalid_claim'),
            ]);
        }
    }

    private function roleKey(?AccountRole $builtInRole, ?CustomRole $customRole): string
    {
        return $customRole instanceof CustomRole
            ? 'custom:'.$customRole->id
            : 'built_in:'.$builtInRole?->value;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(User $actor, OidcConnection $connection, string $action, array $metadata): void
    {
        AuditEvent::query()->create([
            'account_id' => $actor->account_id,
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->id,
            'subject_type' => $connection->getMorphClass(),
            'subject_id' => $connection->id,
            'action' => $action,
            'metadata' => ['oidc_provider_name' => $connection->name, ...$metadata],
            'occurred_at' => now(),
        ]);
    }

    private function redirectWithStatus(string $status): RedirectResponse
    {
        return redirect()
            ->route('dashboard.account.security.show')
            ->with('status', $status);
    }
}
