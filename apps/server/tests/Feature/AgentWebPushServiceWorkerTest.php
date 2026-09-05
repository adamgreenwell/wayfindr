<?php

use Illuminate\Support\Str;

test('web push stays silent while an authenticated application client is visible', function (): void {
    $source = file_get_contents(public_path('wayfindr-sw.js'));
    $pushHandler = Str::before(
        Str::after($source, "self.addEventListener('push'"),
        "self.addEventListener('notificationclick'",
    );

    test()->assertStringContainsString(
        "client.visibilityState === 'visible'",
        $source,
        'only a visible authenticated application should suppress the OS notification',
    );

    expect($source)
        ->toContain("destination.pathname === '/dashboard'")
        ->toContain("destination.pathname === '/operator'")
        ->toContain('isDashboardUrl(client.url) || isOperatorUrl(client.url)');

    expect($pushHandler)
        ->toContain("self.clients.matchAll({ type: 'window', includeUncontrolled: true })")
        ->toContain('windows.some(isVisibleAuthenticatedClient)')
        ->toContain('return undefined;')
        ->toContain('self.registration.showNotification(title, options)');

    expect(strpos($pushHandler, 'return undefined;'))
        ->toBeLessThan(strpos($pushHandler, 'self.registration.showNotification(title, options)'));
});
