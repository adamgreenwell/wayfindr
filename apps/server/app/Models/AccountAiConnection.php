<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/** Only the lifecycle service writes these server-owned snapshots. */
#[Hidden(['proposed_configuration', 'active_configuration', 'tested_fingerprint'])]
final class AccountAiConnection extends Model
{
    public const INSTALLATION = 'installation';

    public const ACCOUNT = 'account';

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'uses_installation_default' => 'boolean',
            'proposed_configuration' => 'encrypted:array',
            'active_configuration' => 'encrypted:array',
            'tested_at' => 'immutable_datetime',
            'test_started_at' => 'immutable_datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function proposedConfiguration(): ?array
    {
        return $this->readConfiguration('proposed_configuration');
    }

    public function activeConfiguration(): ?array
    {
        return $this->readConfiguration('active_configuration');
    }

    public function hasUnreadableConfiguration(): bool
    {
        foreach (['proposed_configuration', 'active_configuration'] as $attribute) {
            if (($this->attributes[$attribute] ?? null) !== null && $this->readConfiguration($attribute) === null) {
                return true;
            }
        }

        return false;
    }

    /** Eloquent must be able to replace an unreadable original ciphertext. */
    public function originalIsEquivalent($key)
    {
        try {
            return parent::originalIsEquivalent($key);
        } catch (DecryptException $exception) {
            if (in_array($key, ['proposed_configuration', 'active_configuration'], true)) {
                return false;
            }

            throw $exception;
        }
    }

    /** Safe status for a future write-only form; never return a saved key. */
    public function credentialStatus(): string
    {
        if ($this->hasUnreadableConfiguration()) {
            return 'unreadable';
        }

        $proposed = $this->proposedConfiguration() ?? [];
        $active = $this->activeConfiguration() ?? [];

        if (($proposed['source'] ?? $active['source'] ?? null) === self::INSTALLATION) {
            return 'installation';
        }

        $candidate = (string) ($proposed['api_key'] ?? '');
        $saved = (string) ($active['api_key'] ?? '');

        return match (true) {
            $candidate === '' && $saved !== '' => 'clear',
            $candidate !== '' && $candidate !== $saved => 'replacement',
            $saved !== '' => 'saved',
            default => 'missing',
        };
    }

    /** Missing or unreadable snapshots never supply an inherited credential. */
    private function readConfiguration(string $attribute): ?array
    {
        try {
            $values = $this->getAttribute($attribute);

            return is_array($values) ? $values : null;
        } catch (Throwable) {
            return null;
        }
    }
}
