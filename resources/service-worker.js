const CACHE_NAME = "lavoro-cache-12e76f16";
const urlsToCache = ["/manifest.json"]; // do NOT pre-cache "/"

self.addEventListener("install", (event) => {
    event.waitUntil(
        caches
            .open(CACHE_NAME)
            .then((cache) => cache.addAll(urlsToCache))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener("activate", (event) => {
    const cacheWhitelist = [CACHE_NAME];
    event.waitUntil(
        caches
            .keys()
            .then((cacheNames) =>
                Promise.all(
                    cacheNames.map((cacheName) => {
                        if (!cacheWhitelist.includes(cacheName)) {
                            return caches.delete(cacheName);
                        }
                    }),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

/**
 * Only what is the same for every customer may come out of this cache: the
 * app's own icons and images and the manifest. Everything else belongs to one
 * customer, under an address another customer uses too -- /files/companies/1/logo
 * is a different logo in each of them. That used to be cache-first as well, so
 * whoever worked in two customers saw the first one's logo, photos and search
 * results in the second, and within one customer a replaced logo or a new
 * notification stayed out of sight until the next deploy.
 */
const STATIC_FILES = ["/manifest.json", "/favicon.ico", "/favicon.svg", "/apple-touch-icon.png"];
const STATIC_FOLDERS = ["/icons/", "/img/"];

const isStatic = (pathname) =>
    STATIC_FILES.includes(pathname) || STATIC_FOLDERS.some((folder) => pathname.startsWith(folder));

self.addEventListener("fetch", (event) => {
    if (event.request.method !== "GET") return;
    const url = new URL(event.request.url);
    if (url.origin !== self.location.origin) return;

    /**
     * Pages are never kept: a page is one customer's data, and the one shown
     * offline could belong to another customer or to whoever logged out.
     */
    if (event.request.mode === "navigate") {
        event.respondWith(
            fetch(event.request).catch(
                () =>
                    new Response("Offline", {
                        status: 503,
                        headers: { "Content-Type": "text/plain" },
                    }),
            ),
        );
        return;
    }

    if (!isStatic(url.pathname)) return;

    event.respondWith(
        caches.match(event.request).then((response) => {
            if (response) return response;

            return fetch(event.request).then((networkResponse) => {
                if (networkResponse && networkResponse.ok && networkResponse.type === "basic") {
                    const responseToCache = networkResponse.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, responseToCache));
                }
                return networkResponse;
            });
        }),
    );
});

self.addEventListener('push', (event) => {
    if (!event.data) return;

    let payload;
    try {
        payload = event.data.json();
    } catch {
        payload = { notification: { title: 'Lavoro', body: event.data.text() } };
    }

    const title   = payload.notification?.title ?? 'Lavoro';
    const data    = payload.data ?? {};
    const options = {
        body:  payload.notification?.body ?? '',
        icon:  '/icons/icon-192.png',
        badge: '/icons/icon-192.png',
        data,

        // One banner per record: a second message about the same storing
        // replaces the first instead of stacking up behind it. The server names
        // the tag; the Firebase shape falls back to its own type and id.
        tag: data.tag ?? (data.type && data.id ? `${data.type}-${data.id}` : undefined),

        // Priority 3 is "hoog". Those stay on screen until they are dealt with;
        // the rest fade on their own like any other notification.
        requireInteraction: Number(data.priority) === 3,
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const data = event.notification.data;

    // A user notification brings its own destination, worked out by the server
    // that wrote it. The Firebase shape stays supported for the native app.
    const url = data?.url
        ? data.url
        : (data?.type === 'service_order_assigned' && data?.id)
            ? `/serviceorders/${data.id}`
            : '/';

    // Reuse the window that is already open rather than stacking up another one.
    event.waitUntil((async () => {
        const windows = await clients.matchAll({ type: 'window', includeUncontrolled: true });

        for (const client of windows) {
            if (new URL(client.url).origin !== self.location.origin) continue;

            await client.focus();

            // navigate() rejects for a window this worker does not control, in
            // which case a new one is still better than a focused window sitting
            // on the wrong page.
            try {
                if ('navigate' in client) {
                    await client.navigate(url);
                }

                return;
            } catch (error) {
                console.error('notification navigate failed:', error);
                break;
            }
        }

        await clients.openWindow(url);
    })());
});
