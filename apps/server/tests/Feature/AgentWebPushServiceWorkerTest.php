<?php

use Illuminate\Support\Str;

test('web push stays silent while a dashboard client is visible', function (): void {
    $source = file_get_contents(public_path('wayfindr-sw.js'));
    $pushHandler = Str::before(
        Str::after($source, "self.addEventListener('push'"),
        "self.addEventListener('notificationclick'",
    );

    test()->assertStringContainsString(
        "client.visibilityState === 'visible' && isDashboardUrl(client.url)",
        $source,
        'only a visible, same-origin dashboard should suppress the OS notification',
    );

    expect($pushHandler)
        ->toContain("self.clients.matchAll({ type: 'window', includeUncontrolled: true })")
        ->toContain('windows.some(isVisibleDashboardClient)')
        ->toContain('return undefined;')
        ->toContain('self.registration.showNotification(title, options)');

    expect(strpos($pushHandler, 'return undefined;'))
        ->toBeLessThan(strpos($pushHandler, 'self.registration.showNotification(title, options)'));
});
