<?php

return [
    'flash' => [
        'created' => 'Draft macro created.',
        'updated' => 'Macro saved.',
        'deleted' => 'Macro deleted. Its execution history remains available.',
        'applied' => 'Macro applied.',
        'failed' => 'The macro could not be applied. No partial changes were kept.',
    ],
    'list' => [
        'heading' => 'Macros',
        'count' => '{0} No macros|{1} :count macro|[2,*] :count macros',
        'macro' => 'Macro',
        'work_type' => 'Work type',
        'actions' => 'Actions',
        'order' => 'Display order',
        'status' => 'Status',
        'manage' => 'Manage',
        'edit' => 'Edit',
    ],
    'empty' => [
        'heading' => 'No macros yet.',
        'body' => 'Create a reusable action sequence that an agent can apply to one ticket or conversation.',
        'action' => 'Create the first macro',
    ],
    'create' => [
        'title' => 'Create automation macro',
        'subtitle' => 'Bundle a small sequence of internal support actions for agents to run in one click.',
        'action' => 'Create macro',
        'submit' => 'Create draft',
    ],
    'edit' => [
        'title' => 'Edit automation macro',
        'title_named' => 'Edit :name',
        'subtitle' => 'Keep the sequence explicit, ordered, and safe for the selected work type.',
        'back' => 'Back to automations',
        'save' => 'Save macro',
    ],
    'fields' => [
        'name' => 'Macro name',
        'name_help' => 'Use a short outcome-focused name agents can recognize while working.',
        'subject_type' => 'Runs on',
        'subject_type_help' => 'Ticket-only actions such as labels and private notes are unavailable for conversations.',
        'position' => 'Display order',
        'position_help' => 'Lower numbers appear first on the support-work page.',
        'enabled' => 'Enable this macro',
        'enabled_help' => 'Enabled macros appear on compatible support work for agents with permission to run every action.',
    ],
    'builder' => [
        'heading' => 'Macro definition',
        'lede' => 'One click, then each listed action from top to bottom.',
        'actions_help' => 'Macros use the same bounded action vocabulary as automation rules and can contain up to ten actions.',
    ],
    'subject_types' => [
        'ticket' => 'Ticket',
        'conversation' => 'Conversation',
    ],
    'apply' => [
        'heading' => 'Macros',
        'lede' => 'Apply a pre-approved internal action sequence to this work item.',
        'run' => 'Apply',
        'action_count' => '{1} :count action|[2,*] :count actions',
    ],
    'execution' => [
        'kind' => 'Manual :type macro',
        'trigger' => 'Manual trigger',
        'triggered_by' => 'Applied by',
    ],
    'delete' => [
        'heading' => 'Delete macro',
        'lede' => 'The macro disappears from support work, but prior execution snapshots stay in the log.',
        'action' => 'Delete macro',
    ],
    'validation' => [
        'heading' => 'Review the macro definition',
        'definition' => 'This macro definition is not valid: :detail',
        'duplicate' => 'A macro with this name already exists.',
    ],
];
