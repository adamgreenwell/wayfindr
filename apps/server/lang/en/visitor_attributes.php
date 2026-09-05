<?php

return [
    'document_title' => 'Visitor attributes',
    'heading' => 'Visitor attributes',
    'subtitle' => 'Turn selected host context into named, typed contact details agents can understand and filter.',
    'back' => 'Back to account',
    'boundary' => [
        'heading' => 'Data boundary',
        'lede' => 'Definitions do not collect new data',
        'body' => 'A definition only interprets a safe context key the host site already sends. Keep the list short, expected, and covered by the site’s privacy notice.',
        'delete' => 'Deleting a definition removes its label and filter. It does not silently erase the underlying host context value.',
    ],
    'fields' => [
        'key' => 'Host context key',
        'key_help' => 'Use lowercase letters, numbers, and underscores. The first character must be a letter.',
        'immutable_key' => 'The key stays fixed so existing visitor values remain attached to the same definition.',
        'label' => 'Agent-facing label',
        'label_placeholder' => 'Plan',
        'type' => 'Value type',
        'type_help' => 'Changing the type does not rewrite stored values. Values that do not match the new type appear as not set.',
    ],
    'types' => [
        'text' => 'Text',
        'number' => 'Number',
        'boolean' => 'Yes or no',
        'date' => 'Date',
    ],
    'create' => [
        'heading' => 'Define an attribute',
        'lede' => 'Up to :count definitions per account',
        'submit' => 'Define attribute',
    ],
    'existing' => [
        'heading' => 'Defined attributes',
        'count' => '{0} No definitions|{1} :count definition|[2,*] :count definitions',
        'empty' => 'No visitor attributes have been defined yet.',
        'save' => 'Save definition',
        'delete' => 'Delete definition',
    ],
    'flash' => [
        'created' => 'Visitor attribute defined.',
        'updated' => 'Visitor attribute definition updated.',
        'deleted' => 'Visitor attribute definition deleted. Stored host context was left unchanged.',
    ],
    'errors' => [
        'duplicate' => 'That host context key is already defined.',
        'limit' => 'An account can define up to :count visitor attributes.',
        'unsafe_key' => 'Choose a non-sensitive host context key. Identity, authentication, payment, and address fields are not accepted here.',
    ],

    'filters' => [
        'attribute' => 'Attribute',
        'any_attribute' => 'Any attribute',
        'value' => 'Exact value',
        'value_placeholder' => 'Value to match',
        'help' => 'Attribute values match exactly after their configured type is applied.',
        'invalid' => 'Enter a value that matches the selected attribute type.',
        'manage' => 'Manage visitor attributes',
    ],

    'profile' => [
        'heading' => 'Defined details',
        'lede' => 'Safe host context, named for this account',
        'attribute' => 'Attribute',
        'value' => 'Value',
        'not_set' => 'Not set',
        'yes' => 'Yes',
        'no' => 'No',
        'manage' => 'Manage definitions',
    ],
];
