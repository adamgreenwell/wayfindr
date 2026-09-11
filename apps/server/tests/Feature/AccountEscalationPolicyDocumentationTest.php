<?php

test('account escalation policy waypoints document timing scope and guardrails', function (): void {
    $path = base_path('../../docs/product/account-escalation-policies.md');

    expect($path)->toBeFile();

    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('account-level escalation defaults')
        ->toContain('working hours')
        // Business time moved to the site. This assertion used to require
        // 'account timezone', which pinned a setting the product dropped once
        // SLA clocks began pausing against each site's support hours — so the
        // test would have failed the document for describing the decision
        // correctly. It guards the same thing: that the document still says
        // where business time comes from.
        ->toContain('site support-hours')
        ->toContain('priority thresholds')
        ->toContain('fallback behavior')
        ->toContain('quiet mode')
        ->toContain('site access')
        ->toContain('deactivated agents')
        ->toContain('opt-in')
        ->toContain('auditable')
        ->toContain('easy to disable')
        ->toContain('metadata-first')
        ->toContain('No automatic escalation should ship until');
});
