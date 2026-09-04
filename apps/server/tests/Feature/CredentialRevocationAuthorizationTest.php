<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\CustomRole;
use App\Models\OutboundWebhookDelivery;
use App\Models\OutboundWebhookEndpoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('credential revocations reauthorize a stale custom role under the account lock', function (string $credential): void {
    $account = Account::factory()->create();
    $integrationRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageIntegrations->value],
    ]);
    $revokedRole = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $manager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $integrationRole->id,
    ]);
    $token = ApiToken::factory()->for($account)->create(['revoked_at' => null]);
    $endpoint = OutboundWebhookEndpoint::factory()->for($account)->create(['disabled_at' => null]);
    $delivery = OutboundWebhookDelivery::factory()
        ->for($endpoint, 'endpoint')
        ->create(['cancelled_at' => null]);

    $this->actingAs($manager);
    expect($manager->hasAccountPermission(AccountPermission::ManageIntegrations))->toBeTrue();
    User::query()->whereKey($manager->id)->update(['custom_role_id' => $revokedRole->id]);

    $response = match ($credential) {
        'API token' => $this->delete(route('dashboard.account.api-tokens.destroy', $token)),
        'outbound webhook' => $this->delete(route('dashboard.account.outbound-webhooks.destroy', $endpoint)),
    };

    $response->assertForbidden();

    expect($token->fresh()->revoked_at)->toBeNull()
        ->and($endpoint->fresh()->disabled_at)->toBeNull()
        ->and($delivery->fresh()->cancelled_at)->toBeNull();
    $this->assertDatabaseCount('audit_events', 0);
})->with(['API token', 'outbound webhook']);
