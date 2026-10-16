<?php

declare(strict_types=1);

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AccountAiConnection;
use App\Models\AuditEvent;
use App\Models\OperatorSetting;
use App\Models\User;
use App\Support\Ai\AccountAiConnectionProbe;
use App\Support\Ai\AccountAiConnections;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\Execution\AiExecutionContext;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Ai\UnavailableAccountAiConnectionProbe;
use App\Support\Settings\OperatorSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function accountAiFoundationOwner(): User
{
    return User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Owner]);
}

function accountAiFoundationProposal(User $owner, array $changes = []): AccountAiConnection
{
    return app(AccountAiConnections::class)->propose($owner, (int) $owner->account_id, [
        'source' => 'account', 'name' => 'Support model', 'provider' => 'openai-compatible',
        'model' => 'support-model', 'endpoint' => 'https://models.example.test/v1',
        'api_key' => 'synthetic-account-key', ...$changes,
    ]);
}

function accountAiFoundationActivate(User $owner, array $changes = []): AccountAiConnection
{
    $record = accountAiFoundationProposal($owner, $changes);
    app(AccountAiConnections::class)->test($owner, (int) $owner->account_id, $record->proposed_revision);

    return app(AccountAiConnections::class)->activate($owner, (int) $owner->account_id, $record->proposed_revision);
}

beforeEach(function (): void {
    config([
        'ai.providers.wayfindr.driver' => 'openai',
        'ai.providers.wayfindr.models.text.default' => 'installation-model',
        'ai.providers.wayfindr.key' => 'synthetic-installation-key',
        'ai.providers.wayfindr.url' => null,
        'ai.providers.wayfindr.openrouter.provider' => '',
    ]);
    app(OperatorSettings::class)->captureBaseline();
    app()->instance(AccountAiConnectionProbe::class, new class implements AccountAiConnectionProbe
    {
        public string $policy = 'synthetic-policy-v1';

        public int $calls = 0;

        public ?Closure $during = null;

        public function policyRevision(): string
        {
            return $this->policy;
        }

        public function probe(ResolvedAgentCopilotConnection $connection, ?AiExecutionContext $context = null): AgentCopilotResult
        {
            $this->calls++;

            if ($this->during !== null) {
                return ($this->during)($connection);
            }

            return new AgentCopilotResult('Synthetic acknowledgement', $connection->provider, $connection->model);
        }
    });
});

test('drafting preserves the explicit manual installation path and never copies its secret', function (): void {
    $owner = accountAiFoundationOwner();
    $resolver = app(AgentCopilotConnectionResolver::class);
    $legacy = $resolver->forAccount((int) $owner->account_id);
    $draft = accountAiFoundationProposal($owner);

    expect($resolver->forAccount((int) $owner->account_id)->revision)->toBe($legacy->revision)
        ->and($draft->active_revision)->toBeNull()
        ->and($draft->enabled)->toBeTrue()
        ->and($resolver->isCurrent((int) $owner->account_id, null))->toBeFalse();

    $installation = app(AccountAiConnections::class)->propose($owner, (int) $owner->account_id, ['source' => 'installation']);
    expect($installation->proposed_configuration)->toHaveKeys(['source', 'installation_revision'])
        ->not->toHaveKey('api_key')
        ->and($installation->credentialStatus())->toBe('installation')
        ->and(json_encode($installation->proposed_configuration))->not->toContain('synthetic-installation-key');
});

test('only an exact server-tested proposal can activate and name-only edits preserve its receipt', function (): void {
    $owner = accountAiFoundationOwner();
    $service = app(AccountAiConnections::class);
    $record = accountAiFoundationProposal($owner);
    expect(fn () => $service->activate($owner, (int) $owner->account_id, $record->proposed_revision))
        ->toThrow(ValidationException::class);
    $tested = $service->test($owner, (int) $owner->account_id, $record->proposed_revision);
    $renamed = accountAiFoundationProposal($owner, ['name' => 'New name']);
    expect($renamed->proposed_revision)->toBe($tested->proposed_revision)
        ->and($renamed->tested_at->equalTo($tested->tested_at))->toBeTrue();

    $active = $service->activate($owner, (int) $owner->account_id, $renamed->proposed_revision);
    expect($active->active_revision)->toBe($tested->proposed_revision)
        ->and(app(AgentCopilotConnectionResolver::class)->forAccount((int) $owner->account_id)->apiKey())
        ->toBe('synthetic-account-key');

    $changed = accountAiFoundationProposal($owner, ['model' => 'changed-model']);
    expect($changed->tested_revision)->toBeNull()
        ->and($changed->active_revision)->toBe($active->active_revision)
        ->and(fn () => $service->activate($owner, (int) $owner->account_id, $tested->proposed_revision))
        ->toThrow(ValidationException::class);
});

test('credentials stay encrypted and excluded from serialization and content-free audit', function (): void {
    $owner = accountAiFoundationOwner();
    $active = accountAiFoundationActivate($owner);
    $stored = DB::table('account_ai_connections')->where('id', $active->id)->first();
    expect($stored->proposed_configuration)->not->toContain('synthetic-account-key')
        ->and($stored->active_configuration)->not->toContain('synthetic-account-key')
        ->and($active->toJson())->not->toContain('synthetic-account-key', 'proposed_configuration', 'active_configuration')
        ->and(AuditEvent::query()->get()->toJson())->not->toContain('synthetic-account-key', 'models.example.test');
    $resolved = app(AgentCopilotConnectionResolver::class)->forAccount((int) $owner->account_id);
    ob_start();
    var_dump($resolved);
    $debug = ob_get_clean();
    expect($debug)->not->toContain('synthetic-account-key', 'models.example.test')
        ->and(json_encode($resolved))->not->toContain('synthetic-account-key', 'models.example.test')
        ->and(fn () => serialize($resolved))->toThrow(LogicException::class);

    $owner->account->delete();
    expect(AccountAiConnection::query()->count())->toBe(0);
});

test('rotation and clear are proposed only and another driver never inherits the saved key', function (): void {
    $owner = accountAiFoundationOwner();
    accountAiFoundationActivate($owner);
    $resolver = app(AgentCopilotConnectionResolver::class);
    $original = $resolver->forAccount((int) $owner->account_id);
    $replacement = accountAiFoundationProposal($owner, ['api_key' => 'rotated-synthetic-key']);
    expect($replacement->credentialStatus())->toBe('replacement')
        ->and($resolver->forAccount((int) $owner->account_id)->apiKey())->toBe($original->apiKey())
        ->and($replacement->tested_revision)->toBeNull();

    $cleared = accountAiFoundationProposal($owner, ['api_key' => '', 'clear_api_key' => true]);
    expect($cleared->credentialStatus())->toBe('clear')
        ->and($cleared->proposed_configuration['api_key'])->toBe('')
        ->and($resolver->isCurrentConnection($original))->toBeTrue();

    $different = accountAiFoundationProposal($owner, ['provider' => 'anthropic', 'api_key' => '']);
    expect($different->proposed_configuration['api_key'])->toBe('');
});

test('failed probes and policy changes cannot replace active configuration', function (): void {
    $owner = accountAiFoundationOwner();
    $active = accountAiFoundationActivate($owner);
    $draft = accountAiFoundationProposal($owner, ['model' => 'candidate-model']);
    $probe = app(AccountAiConnectionProbe::class);
    $probe->during = fn () => throw new RuntimeException('synthetic-account-key https://private.example.test');
    $failure = app(AccountAiConnections::class)->test($owner, (int) $owner->account_id, $draft->proposed_revision);
    expect($failure->test_status)->toBe('unavailable')
        ->and($failure->active_revision)->toBe($active->active_revision)
        ->and($failure->tested_revision)->toBeNull()
        ->and($failure->toJson())->not->toContain('private.example.test');
    $probe->during = null;
    $tested = app(AccountAiConnections::class)->test($owner, (int) $owner->account_id, $draft->proposed_revision);
    $probe->policy = 'changed-policy';
    expect(fn () => app(AccountAiConnections::class)->activate($owner, (int) $owner->account_id, $tested->proposed_revision))
        ->toThrow(ValidationException::class)
        ->and(app(AgentCopilotConnectionResolver::class)->forAccount((int) $owner->account_id))->toBeNull();
});

test('probe completion fences duplicate tests changed proposals and revoked managers', function (): void {
    $owner = accountAiFoundationOwner();
    $draft = accountAiFoundationProposal($owner);
    $service = app(AccountAiConnections::class);
    $probe = app(AccountAiConnectionProbe::class);
    $probe->during = function ($connection) use ($service, $owner, $draft): AgentCopilotResult {
        expect(fn () => $service->test($owner, (int) $owner->account_id, $draft->proposed_revision))
            ->toThrow(ValidationException::class, 'A connection test is already running.');
        accountAiFoundationProposal($owner, ['model' => 'changed-during-test']);

        return new AgentCopilotResult('OK', $connection->provider, $connection->model);
    };
    expect(fn () => $service->test($owner, (int) $owner->account_id, $draft->proposed_revision))
        ->toThrow(ValidationException::class)
        ->and($probe->calls)->toBe(1)
        ->and($draft->fresh()->tested_revision)->toBeNull();
    $draft = $draft->fresh();
    $probe->during = function ($connection) use ($owner): AgentCopilotResult {
        $owner->forceFill(['account_role' => AccountRole::Agent])->save();

        return new AgentCopilotResult('OK', $connection->provider, $connection->model);
    };
    expect(fn () => $service->test($owner, (int) $owner->account_id, $draft->proposed_revision))
        ->toThrow(AuthorizationException::class)
        ->and($draft->fresh()->tested_revision)->toBeNull();
});

test('all lifecycle writes reject foreign accounts and freshly revoked actors', function (): void {
    $owner = accountAiFoundationOwner();
    $foreign = accountAiFoundationOwner();
    expect(fn () => app(AccountAiConnections::class)->propose($owner, (int) $foreign->account_id, ['source' => 'installation']))
        ->toThrow(AuthorizationException::class)
        ->and(AccountAiConnection::query()->count())->toBe(0);
    $staleActor = User::query()->findOrFail($owner->id);
    $owner->forceFill(['deactivated_at' => now()])->save();
    expect(fn () => app(AccountAiConnections::class)->setEnabled($staleActor, (int) $owner->account_id, false))
        ->toThrow(AuthorizationException::class);
});

test('disable and disconnect need no test and cannot silently return to installation processing', function (): void {
    $owner = accountAiFoundationOwner();
    accountAiFoundationActivate($owner);
    $service = app(AccountAiConnections::class);
    $service->setEnabled($owner, (int) $owner->account_id, false);
    expect(app(AgentCopilotConnectionResolver::class)->forAccount((int) $owner->account_id))->toBeNull();
    $disconnected = $service->disconnect($owner, (int) $owner->account_id);
    expect($disconnected->active_configuration)->toBeNull()
        ->and($disconnected->proposed_configuration)->toBeNull()
        ->and(app(AgentCopilotConnectionResolver::class)->forAccount((int) $owner->account_id))->toBeNull();
    expect(fn () => $service->setEnabled($owner, (int) $owner->account_id, true))
        ->toThrow(ValidationException::class, 'Choose and test a connection before re-enabling.');
});

test('fresh installation reads detect raw committed rotation without mutating worker config', function (): void {
    $owner = accountAiFoundationOwner();
    $resolver = app(AgentCopilotConnectionResolver::class);
    $before = $resolver->forAccount((int) $owner->account_id);
    $global = config('ai.providers.wayfindr');
    OperatorSetting::query()->updateOrCreate(['key' => 'ai.api_key'], ['value' => Crypt::encryptString('fresh-synthetic-operator-key')]);
    $after = $resolver->forAccount((int) $owner->account_id);
    expect($after->apiKey())->toBe('fresh-synthetic-operator-key')
        ->and($after->revision)->not->toBe($before->revision)
        ->and($resolver->isCurrentConnection($before))->toBeFalse()
        ->and(config('ai.providers.wayfindr'))->toBe($global);
    DB::table('operator_settings')->where('key', 'ai.api_key')->update(['value' => 'unreadable-ciphertext']);
    expect($resolver->forAccount((int) $owner->account_id))->toBeNull();
});

test('the default account probe executor is unavailable and makes no attestation', function (): void {
    app()->bind(AccountAiConnectionProbe::class, UnavailableAccountAiConnectionProbe::class);
    $owner = accountAiFoundationOwner();
    $draft = accountAiFoundationProposal($owner);
    $failed = app(AccountAiConnections::class)->test($owner, (int) $owner->account_id, $draft->proposed_revision);
    expect($failed->test_status)->toBe('unavailable')->and($failed->tested_revision)->toBeNull()
        ->and(fn () => app(AccountAiConnections::class)->activate($owner, (int) $owner->account_id, $draft->proposed_revision))
        ->toThrow(ValidationException::class);
});

test('every processing-field edit invalidates the exact tested proposal', function (array $changes): void {
    $owner = accountAiFoundationOwner();
    $base = ['provider' => 'openrouter', 'model' => 'anthropic/support-model', 'endpoint' => '', 'openrouter_provider' => 'amazon-bedrock'];
    $record = accountAiFoundationProposal($owner, $base);
    app(AccountAiConnections::class)->test($owner, (int) $owner->account_id, $record->proposed_revision);
    $changed = accountAiFoundationProposal($owner, [...$base, ...$changes]);
    expect($changed->proposed_revision)->not->toBe($record->proposed_revision)
        ->and($changed->tested_revision)->toBeNull()
        ->and(fn () => app(AccountAiConnections::class)->activate($owner, (int) $owner->account_id, $changed->proposed_revision))
        ->toThrow(ValidationException::class);
})->with([
    'provider' => [['provider' => 'openai']],
    'model' => [['model' => 'anthropic/other-model']],
    'endpoint' => [['endpoint' => 'https://gateway.example.test/v1']],
    'credential' => [['api_key' => 'synthetic-rotated-key']],
    'upstream' => [['openrouter_provider' => 'google-vertex']],
]);

test('one resolver keeps A then B then A credentials and endpoints isolated', function (): void {
    $a = accountAiFoundationOwner();
    $b = accountAiFoundationOwner();
    accountAiFoundationActivate($a, ['model' => 'model-a', 'endpoint' => 'https://a.example.test/v1', 'api_key' => 'synthetic-key-a']);
    accountAiFoundationActivate($b, ['model' => 'model-b', 'endpoint' => 'https://b.example.test/v1', 'api_key' => 'synthetic-key-b']);
    $global = config('ai.providers.wayfindr');
    $resolver = app(AgentCopilotConnectionResolver::class);
    foreach ([$a, $b, $a] as $owner) {
        $suffix = $owner->id === $a->id ? 'a' : 'b';
        $connection = $resolver->forAccount((int) $owner->account_id);
        expect($connection->accountId)->toBe((int) $owner->account_id)
            ->and($connection->model)->toBe('model-'.$suffix)
            ->and($connection->endpoint)->toBe('https://'.$suffix.'.example.test/v1')
            ->and($connection->apiKey())->toBe('synthetic-key-'.$suffix);
    }
    expect(config('ai.providers.wayfindr'))->toBe($global);
});

test('an endpoint change never forwards a saved credential to the proposed destination', function (): void {
    $owner = accountAiFoundationOwner();
    accountAiFoundationActivate($owner);
    $original = app(AgentCopilotConnectionResolver::class)->forAccount((int) $owner->account_id);
    $changed = accountAiFoundationProposal($owner, ['endpoint' => 'https://different.example.test/v1', 'api_key' => '']);
    expect($changed->proposedConfiguration()['api_key'])->toBe('')
        ->and($changed->credentialStatus())->toBe('clear')
        ->and(app(AgentCopilotConnectionResolver::class)->forAccount((int) $owner->account_id)->apiKey())
        ->toBe($original->apiKey());
});

test('unreadable encrypted snapshots return an explicit credential status instead of throwing', function (array $columns): void {
    $owner = accountAiFoundationOwner();
    $record = accountAiFoundationActivate($owner);
    DB::table('account_ai_connections')->where('id', $record->id)->update(array_fill_keys($columns, 'invalid-snapshot-sensitive-marker'));

    try {
        $status = $record->fresh()->credentialStatus();
    } catch (Throwable) {
        $status = 'threw instead of returning a credential status';
    }

    expect($status)->toBe('unreadable', 'Unreadable encrypted credentials must return a safe status without throwing.');
})->with([
    'proposed snapshot' => [['proposed_configuration']],
    'active snapshot' => [['active_configuration']],
    'both snapshots' => [['proposed_configuration', 'active_configuration']],
]);

test('unreadable snapshots require an explicit replacement and recover only through a new tested revision', function (array $columns): void {
    $owner = accountAiFoundationOwner();
    $active = accountAiFoundationActivate($owner);
    $resolver = app(AgentCopilotConnectionResolver::class);
    $original = $resolver->forAccount((int) $owner->account_id);
    DB::table('account_ai_connections')->where('id', $active->id)->update(array_fill_keys($columns, 'invalid-snapshot-sensitive-marker'));
    $activeIsUnreadable = in_array('active_configuration', $columns, true);
    expect($resolver->forAccount((int) $owner->account_id)?->revision)->toBe($activeIsUnreadable ? null : $original->revision);
    $errors = null;
    try {
        accountAiFoundationProposal($owner, ['api_key' => '']);
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }
    expect($errors)->not->toBeNull('Unreadable snapshots must never inherit a credential without an explicit replacement or clear.')
        ->and(json_encode($errors))->not->toContain('invalid-snapshot-sensitive-marker', 'synthetic-account-key');

    $replacement = accountAiFoundationProposal($owner, ['api_key' => 'explicit-recovery-key']);
    expect($replacement->proposed_revision)->not->toBe($active->proposed_revision)
        ->and($replacement->tested_revision)->toBeNull()
        ->and($replacement->proposedConfiguration()['api_key'])->toBe('explicit-recovery-key')
        ->and($replacement->active_revision)->toBe($active->active_revision)
        ->and($resolver->forAccount((int) $owner->account_id)?->revision)->toBe($activeIsUnreadable ? null : $original->revision);

    $service = app(AccountAiConnections::class);
    $service->test($owner, (int) $owner->account_id, $replacement->proposed_revision);
    $recovered = $service->activate($owner, (int) $owner->account_id, $replacement->proposed_revision);
    $resolved = $resolver->forAccount((int) $owner->account_id);
    expect($recovered->credentialStatus())->toBe('saved')
        ->and($resolved->source)->toBe(AccountAiConnection::ACCOUNT)
        ->and($resolved->apiKey())->toBe('explicit-recovery-key')
        ->and($resolved->revision)->not->toBe($original->revision)
        ->and($recovered->toJson())->not->toContain('explicit-recovery-key', 'invalid-snapshot-sensitive-marker')
        ->and(AuditEvent::query()->get()->toJson())->not->toContain('explicit-recovery-key', 'invalid-snapshot-sensitive-marker');
})->with([
    'proposed snapshot' => [['proposed_configuration']],
    'active snapshot' => [['active_configuration']],
    'both snapshots' => [['proposed_configuration', 'active_configuration']],
]);

test('explicit clear can replace unreadable snapshots without inheriting a saved key', function (array $columns): void {
    $owner = accountAiFoundationOwner();
    $active = accountAiFoundationActivate($owner);
    DB::table('account_ai_connections')->where('id', $active->id)->update(array_fill_keys($columns, 'invalid-snapshot-sensitive-marker'));

    $cleared = accountAiFoundationProposal($owner, ['api_key' => '', 'clear_api_key' => true]);
    expect($cleared->proposedConfiguration()['api_key'])->toBe('')
        ->and($cleared->tested_revision)->toBeNull()
        ->and($cleared->active_revision)->toBe($active->active_revision);
    $service = app(AccountAiConnections::class);
    $service->test($owner, (int) $owner->account_id, $cleared->proposed_revision);
    $recovered = $service->activate($owner, (int) $owner->account_id, $cleared->proposed_revision);
    expect($recovered->credentialStatus())->toBe('missing')
        ->and(app(AgentCopilotConnectionResolver::class)->forAccount((int) $owner->account_id)->apiKey())->toBe('');
})->with([
    'proposed snapshot' => [['proposed_configuration']],
    'active snapshot' => [['active_configuration']],
    'both snapshots' => [['proposed_configuration', 'active_configuration']],
]);

test('probes and activation reject unreadable proposals with sanitized validation', function (string $operation): void {
    $owner = accountAiFoundationOwner();
    $active = accountAiFoundationActivate($owner);
    DB::table('account_ai_connections')->where('id', $active->id)->update(['proposed_configuration' => 'invalid-snapshot-sensitive-marker']);
    $probe = app(AccountAiConnectionProbe::class);
    $before = $probe->calls;
    $errors = null;
    try {
        app(AccountAiConnections::class)->{$operation}($owner, (int) $owner->account_id, $active->proposed_revision);
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }

    expect($errors)->not->toBeNull('Unreadable proposals must produce validation rather than a decryption error or activation.')
        ->and(json_encode($errors))->not->toContain('invalid-snapshot-sensitive-marker', 'synthetic-account-key')
        ->and($probe->calls)->toBe($before)
        ->and($active->fresh()->active_revision)->toBe($active->active_revision);
})->with(['probe' => 'test', 'activation' => 'activate']);

test('an explicit installation proposal can recover unreadable snapshots without switching the active processor early', function (): void {
    $owner = accountAiFoundationOwner();
    $active = accountAiFoundationActivate($owner);
    DB::table('account_ai_connections')->where('id', $active->id)->update([
        'proposed_configuration' => 'invalid-proposed-snapshot',
        'active_configuration' => 'invalid-active-snapshot',
    ]);
    $resolver = app(AgentCopilotConnectionResolver::class);
    $service = app(AccountAiConnections::class);

    $proposed = $service->propose($owner, (int) $owner->account_id, ['source' => AccountAiConnection::INSTALLATION]);
    expect($proposed->proposedConfiguration())->toHaveKeys(['source', 'installation_revision'])
        ->not->toHaveKey('api_key')
        ->and($proposed->active_revision)->toBe($active->active_revision)
        ->and($resolver->forAccount((int) $owner->account_id))->toBeNull();
    $service->test($owner, (int) $owner->account_id, $proposed->proposed_revision);
    $service->activate($owner, (int) $owner->account_id, $proposed->proposed_revision);
    expect($resolver->forAccount((int) $owner->account_id)->source)->toBe(AccountAiConnection::INSTALLATION);
});
