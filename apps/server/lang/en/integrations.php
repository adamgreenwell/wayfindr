<?php

/*
 * The account integrations page.
 *
 * Connection and site names, URLs, project keys, webhook event names, and
 * provider-owned interface labels are data rather than Wayfindr copy. The view
 * marks them with an unknown language and passes escaped markup into the few
 * translated sentences that wrap them.
 *
 * Connection creation is also available from the still-English site page. Its
 * success sentence therefore stays English on that path; only the explicit
 * return to this page flashes the key below.
 */

return [
    'title' => 'Integrations',
    'subtitle' => 'Account-wide provider connections and where each site sends external issues.',
    'back' => 'Back to account',

    'flash' => [
        'connection_saved' => 'Provider connection saved.',
        'secret_cleared' => 'Inbound webhook secret cleared.',
        'secret_saved' => 'Inbound webhook secret saved.',
        'capabilities_updated' => 'Provider capabilities updated.',
    ],

    'connections' => [
        'heading' => 'Provider connections',
        'count' => '{1} :count connection|[2,*] :count connections',
        'account_owned' => 'account-owned',
        'admin_hint' => 'Provider connections are managed by an account admin. Ask an admin to add or change connections; every agent can use them from tickets once configured.',
        'empty' => 'No provider connections yet.',
        'empty_admin' => 'Connect :providers below with an API token to let agents hand tickets off as external issues.',
        'enabled' => 'Enabled',
        'disabled' => 'Disabled',

        'setup' => [
            'heading' => 'Connection setup order',
            'save_title' => '1. Save the provider connection first.',
            'save_body' => 'Wayfindr creates its unique inbound webhook URL only after the connection exists.',
            'copy_title' => '2. Copy the generated webhook URL.',
            'copy_body' => 'It appears with the saved connection above this form.',
            'configure_title' => '3. Configure the provider webhook.',
            'configure_body' => 'Paste that URL into :providers, reuse the same webhook secret, and select issue-state and issue-comment events.',
            'map_title' => '4. Map a site to a project.',
            'map_body' => 'Return here and open the site under Site project mappings so tickets know which repository or project should receive them.',
            'outbound_only' => 'Providers without an inbound webhook receiver can still be used for the outbound capabilities they support.',
        ],
    ],

    'capabilities' => [
        'heading' => 'Connection capabilities',
        'help' => 'Choose what agents may send through this saved connection. Inbound signed webhooks are authenticated separately by the shared secret.',
        'update' => 'Update capabilities',
        'labels' => [
            'create_issue' => 'Create issues',
            'add_comment' => 'Add comments',
            'sync_status' => 'Sync status',
        ],
        'permissions' => [
            'create_issue' => 'Provider can create issues',
            'add_comment' => 'Provider can add comments',
            'sync_status' => 'Provider can sync status',
        ],
    ],

    'webhook' => [
        'verified_title' => 'Inbound sync verified.',
        'verified_body' => 'Wayfindr accepted a signed provider delivery :elapsed.',
        'latest' => 'Latest verified event: :event · HTTP :status',
        'unknown' => 'unknown',
        'configured_title' => 'Inbound sync configured, not verified.',
        'configured_body' => 'A secret is saved, but Wayfindr has not accepted a signed provider delivery yet.',
        'missing_title' => 'Inbound sync not configured.',
        'missing_body' => 'Set a webhook secret on this connection and point the provider at the URL below to sync issue state back.',
        'generated_url' => 'Generated webhook URL',
        'settings_aria' => 'Inbound webhook settings',
        'provider_destination_title' => 'Provider destination:',
        'provider_destination_body' => 'Paste the generated URL into this connection’s webhook settings.',
        'github_title' => 'GitHub settings:',
        'github_body' => 'Use :content_type, keep SSL verification enabled, and select the individual :issues and :comments events.',
        'gitlab_title' => 'GitLab settings:',
        'gitlab_body' => 'Use the generated URL, place the same value in :secret_token, and enable :issues and :comments.',
        'jira_title' => 'Jira settings:',
        'jira_body' => 'Use the generated URL and the same secret, then subscribe to issue state changes and comment-created events.',
        'shared_secret_title' => 'Shared secret:',
        'shared_secret_body' => 'The secret must match in Wayfindr and the provider. If you replace it here, replace it there too.',
        'replace_secret' => 'Replace webhook secret',
        'set_secret' => 'Set webhook secret',
        'update_secret' => 'Update secret',
        'enable' => 'Enable inbound sync',
    ],

    'create' => [
        'heading' => 'Add provider connection',
        'available' => 'Available to every site in this account',
        'provider' => 'Provider',
        'name' => 'Connection name',
        'name_placeholder' => 'Engineering GitHub',
        'base_url' => 'Base URL',
        'credential' => 'Token or credential placeholder',
        'webhook_secret' => 'Inbound webhook secret',
        'webhook_help' => 'Optional now. Save this connection first to generate its webhook URL. You can enter a secret now and reuse it at the provider, or leave this blank and set both sides after the URL appears. :github signs it (:github_header), :jira signs it (:jira_header), and :gitlab sends it as an :gitlab_header header.',
        'submit' => 'Save provider connection',
    ],

    'mappings' => [
        'heading' => 'Site project mappings',
        'count' => '{1} :mapped of :total site mapped|[2,*] :mapped of :total sites mapped',
        'help' => 'Project mappings are site-scoped: each site chooses which external project its tickets hand off to. Map projects from the site’s own page.',
        'empty' => 'No sites yet.',
        'unmapped' => 'No external projects mapped yet.',
        'map' => 'Map a project',
        'manage' => 'Manage',
    ],

    'providers' => [
        'setup_list' => ':github, :gitlab, or :jira',
        'other' => 'Other',
        'external_tracker' => 'External tracker',
    ],
];
