<?php

return [
    'settings' => [
        'heading' => 'OpenID Connect single sign-on',
        'enabled' => 'Enabled',
        'disabled' => 'Not enabled',
        'help' => 'Connect one identity provider for existing agents. SSO does not create users or change their roles.',
        'callback_help' => 'Register this callback URL with the identity provider:',
        'name' => 'Provider name',
        'issuer_url' => 'Issuer URL',
        'client_id' => 'Client ID',
        'client_secret' => 'Client secret',
        'secret_help' => 'Wayfindr encrypts this secret and never displays it again.',
        'secret_keep_help' => 'Leave blank to keep the saved secret.',
        'enable_checkbox' => 'Allow agents to sign in with this provider',
        'save' => 'Save single sign-on',
        'secret_required' => 'Enter the client secret when connecting a provider.',
        'public_https_required' => 'The issuer must be a publicly resolvable HTTPS URL.',
    ],
    'sign_in' => [
        'failed' => 'Single sign-on could not be completed. Check the account slug or contact your administrator.',
    ],
    'flash' => [
        'connection_updated' => 'Single sign-on settings updated.',
    ],
];
