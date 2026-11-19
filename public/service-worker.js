// Service worker untuk push notification "Request Baru Masuk".
// Ditaruh di root /public supaya scope-nya mencakup seluruh situs.

self.addEventListener('push', function (event) {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        payload = { title: 'Notifikasi Baru', body: event.data ? event.data.text() : '' };
    }

    const title = payload.title || 'Request Baru Masuk';
    const options = {
        body: payload.body || '',
        icon: payload.icon || '/images/logo-akp.png',
        badge: payload.badge || '/images/logo-akp.png',
        tag: payload.tag || undefined,
        data: payload.data || {},
        actions: payload.actions || [],
        requireInteraction: false,
    };

    event.waitUntil(
        Promise.all([
            self.registration.showNotification(title, options),
            // Kalau ada tab dashboard yang sedang terbuka, minta dia mainkan suara notifikasi
            self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clients) {
                clients.forEach(function (client) {
                    client.postMessage({ type: 'PLAY_NOTIFICATION_SOUND' });
                });
            }),
        ])
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clients) {
            for (const client of clients) {
                if (client.url.includes(self.location.origin) && 'focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            return self.clients.openWindow(url);
        })
    );
});
