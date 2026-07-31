const CACHE_NAME = "lavoro-cache-10c0329";
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

self.addEventListener("fetch", (event) => {
    // Only handle same-origin GETs
    if (event.request.method !== "GET") return;
    const url = new URL(event.request.url);
    if (url.origin !== self.location.origin) return;

    // Let the browser handle assets & API/Inertia calls
    if (
        url.pathname.startsWith("/build/") ||
        event.request.headers.get("X-Inertia") ||
        url.pathname.startsWith("/api/")
    ) {
        return;
    }

    // For top-level navigations: network-first (avoid cached redirects)
    if (event.request.mode === "navigate") {
        event.respondWith(
            (async () => {
                try {
                    const response = await fetch(event.request);
                    // Only cache clean 200 "basic" docs (not redirects/opaques)
                    if (response && response.ok && response.type === "basic") {
                        const cache = await caches.open(CACHE_NAME);
                        cache.put(event.request, response.clone());
                    }
                    return response;
                } catch (error) {
                    console.error(error);
                    const cached = await caches.match("/");
                    return (
                        cached ||
                        new Response("Offline", {
                            status: 503,
                            headers: { "Content-Type": "text/plain" },
                        })
                    );
                }
            })(),
        );
        return;
    }

    // Other GETs: cache-first, skip redirects/errors
    event.respondWith(
        caches.match(event.request).then((response) => {
            if (response) return response;

            return fetch(event.request).then((networkResponse) => {
                if (
                    networkResponse &&
                    networkResponse.ok &&
                    networkResponse.type === "basic"
                ) {
                    const responseToCache = networkResponse.clone();
                    caches
                        .open(CACHE_NAME)
                        .then((cache) =>
                            cache.put(event.request, responseToCache),
                        );
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
