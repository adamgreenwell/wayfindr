<?php

test('account escalation policy waypoints document timing scope and guardrails', function (): void {
    $path = base_path('../../docs/product/account-escalation-policies.md');

    expect($path)->toBeFile();

    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('account-level escalation defaults')
        ->toContain('working hours')
        ->toContain('account timezone')
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
