<?php

namespace App\Support\Automation;

use App\Enums\AccountPermission;
use App\Enums\AutomationRuleActionType;
use App\Models\AutomationMacro;
use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class AutomationMacroAuthorization
{
    public function allows(User $agent, AutomationMacro $macro, Ticket|Conversation $subject): bool
    {
        if ($agent->isDeactivated()
            || ! $macro->is_enabled
            || ! $macro->subjectTypeEnum()->matches($subject)
            || (int) $macro->account_id !== $this->accountId($subject)
            || (int) $agent->account_id !== (int) $macro->account_id
            || ! Gate::forUser($agent)->allows('view', $subject)) {
            return false;
        }

        $types = collect($macro->actions)
            ->map(fn (array $action): AutomationRuleActionType => AutomationRuleActionType::from($action['type']));

        if ($subject instanceof Ticket) {
            return Gate::forUser($agent)->allows('update', $subject)
                && (! $types->contains(AutomationRuleActionType::AssignAgent)
                    || $agent->hasAccountPermission(AccountPermission::AssignTickets));
        }

        $changesConversationState = $types->contains(fn (AutomationRuleActionType $type): bool => in_array($type, [
            AutomationRuleActionType::AssignAgent,
            AutomationRuleActionType::SetPriority,
            AutomationRuleActionType::SetStatus,
        ], true));

        return ! $changesConversationState
            || $agent->hasAccountPermission(AccountPermission::ManageConversations);
    }

    private function accountId(Ticket|Conversation $subject): int
    {
        if ($subject instanceof Ticket) {
            return (int) $subject->account_id;
        }

        $subject->loadMissing('site');

        return (int) $subject->site?->account_id;
    }
}
