<?php

namespace App\Support\Automation;

use App\Models\AutomationMacro;
use App\Models\AutomationRule;
use App\Models\User;

final readonly class AutomationActionContext
{
    private function __construct(
        public int $accountId,
        public string $name,
        public string $kind,
        public int $id,
        public ?User $actor,
    ) {}

    public static function forRule(AutomationRule $rule): self
    {
        return new self(
            accountId: (int) $rule->account_id,
            name: (string) $rule->name,
            kind: 'rule',
            id: (int) $rule->id,
            actor: null,
        );
    }

    public static function forMacro(AutomationMacro $macro, User $actor): self
    {
        return new self(
            accountId: (int) $macro->account_id,
            name: (string) $macro->name,
            kind: 'macro',
            id: (int) $macro->id,
            actor: $actor,
        );
    }

    public function source(): string
    {
        return $this->kind === 'rule' ? 'automation' : 'macro';
    }

    public function idKey(): string
    {
        return $this->kind === 'rule' ? 'automation_rule_id' : 'automation_macro_id';
    }

    public function description(): string
    {
        return $this->kind === 'rule' ? 'automation rule' : 'automation macro';
    }
}
