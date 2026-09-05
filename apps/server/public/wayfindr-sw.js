'use strict';

self.addEventListener('push', function (event) {
    var payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch (error) {
        payload = {};
    }

    var title = typeof payload.title === 'string' ? payload.title : 'Wayfindr';
    var options = {
        body: typeof payload.body === 'string' ? payload.body : 'Open Wayfindr to review a new alert.',
        icon: typeof payload.icon === 'string' ? payload.icon : '/favicon.ico',
        badge: typeof payload.badge === 'string' ? payload.badge : '/favicon.ico',
        lang: typeof payload.lang === 'string' ? payload.lang : undefined,
        tag: typeof payload.tag === 'string' ? payload.tag : 'wayfindr-agent-alert',
        data: payload.data && typeof payload.data === 'object' ? payload.data : {},
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    var requested = event.notification.data && event.notification.data.url;
    var destination;

    try {
        destination = new URL(typeof requested === 'string' ? requested : '/dashboard/alerts', self.location.origin);
    } catch (error) {
        destination = new URL('/dashboard/alerts', self.location.origin);
    }

    if (destination.origin !== self.location.origin
        || (destination.pathname !== '/dashboard' && ! destination.pathname.startsWith('/dashboard/'))) {
        destination = new URL('/dashboard/alerts', self.location.origin);
    }

    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true })
        .then(function (windows) {
            var existing = windows.find(function (client) {
                return new URL(client.url).origin === self.location.origin;
            });

            if (existing) {
                return existing.navigate(destination.href).then(function () {
                    return existing.focus();
                });
            }

            return self.clients.openWindow(destination.href);
        }));
});
