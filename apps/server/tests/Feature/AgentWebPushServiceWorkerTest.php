<?php

use Illuminate\Support\Str;

test('every received Web Push produces the notification required by user visible only', function (): void {
    $source = file_get_contents(public_path('wayfindr-sw.js'));
    $pushHandler = Str::before(
        Str::after($source, "self.addEventListener('push'"),
        "self.addEventListener('notificationclick'",
    );

    expect($source)
        ->toContain("destination.pathname === '/dashboard'")
        ->not->toContain("destination.pathname === '/operator'")
        ->not->toContain('isVisibleAuthenticatedClient');

    expect($pushHandler)
        ->not->toContain('self.clients.matchAll')
        ->toContain('event.waitUntil(self.registration.showNotification(title, options))');
});
