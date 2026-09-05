<?php

namespace App\Support\Automation;

use App\Models\AutomationMacro;
use App\Models\AutomationRule;
use App\Models\ConversationBulkActionRun;
use App\Models\TicketBulkActionRun;
use App\Models\TicketLabel;
use App\Models\User;

final readonly class AutomationActionContext
{
    private function __construct(
        public int $accountId,
        public string $name,
        public string $kind,
        public int $id,
        public ?User $actor,
        public ?User $validatedAgent = null,
        public ?TicketLabel $validatedLabel = null,
        /** @var list<int> */
        public array $validatedSiteIds = [],
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

    /** @param list<int> $validatedSiteIds */
    public static function forTicketBulkAction(
        TicketBulkActionRun $run,
        User $actor,
        User|TicketLabel|null $validatedTarget,
        array $validatedSiteIds,
    ): self {
        return new self(
            accountId: (int) $run->account_id,
            name: 'Ticket bulk action',
            kind: 'ticket_bulk_action',
            id: (int) $run->id,
            actor: $actor,
            validatedAgent: $validatedTarget instanceof User ? $validatedTarget : null,
            validatedLabel: $validatedTarget instanceof TicketLabel ? $validatedTarget : null,
            validatedSiteIds: $validatedSiteIds,
        );
    }

    /** @param list<int> $validatedSiteIds */
    public static function forConversationBulkAction(
        ConversationBulkActionRun $run,
        User $actor,
        ?User $validatedAgent,
        array $validatedSiteIds,
    ): self {
        return new self(
            accountId: (int) $run->account_id,
            name: 'Conversation bulk action',
            kind: 'conversation_bulk_action',
            id: (int) $run->id,
            actor: $actor,
            validatedAgent: $validatedAgent,
            validatedSiteIds: $validatedSiteIds,
        );
    }

    public function source(): string
    {
        return match ($this->kind) {
            'rule' => 'automation',
            'macro' => 'macro',
            default => 'bulk_action',
        };
    }

    public function idKey(): string
    {
        return match ($this->kind) {
            'rule' => 'automation_rule_id',
            'macro' => 'automation_macro_id',
            'ticket_bulk_action' => 'ticket_bulk_action_run_id',
            'conversation_bulk_action' => 'conversation_bulk_action_run_id',
        };
    }

    public function description(): string
    {
        return match ($this->kind) {
            'rule' => 'automation rule',
            'macro' => 'automation macro',
            'ticket_bulk_action' => 'ticket bulk action',
            'conversation_bulk_action' => 'conversation bulk action',
        };
    }
}
