const CACHE_NAME = "wh-crm-cache-v19";
const urlsToCache = ["/", "/manifest.json"];

self.addEventListener("install", (event) => {
    event.waitUntil(
        caches
            .open(CACHE_NAME)
            .then((cache) => cache.addAll(urlsToCache))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener("activate", (event) => {
    const cacheWhitelist = [CACHE_NAME];
    event.waitUntil(
        caches
            .keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames.map((cacheName) => {
                        if (cacheWhitelist.indexOf(cacheName) === -1) {
                            return caches.delete(cacheName);
                        }
                    })
                );
            })
            .then(() => self.clients.claim())
    );
});

self.addEventListener("fetch", (event) => {
    // Let the browser handle requests for scripts and assets,
    // and always fetch Inertia and API requests from the network.
    if (
        event.request.url.includes("/build/") ||
        event.request.headers.get("X-Inertia") ||
        event.request.url.includes("/api/")
    ) {
        return; // This will fall back to the default network behavior.
    }

    // For other GET requests, use a cache-first strategy.
    if (event.request.method === "GET") {
        event.respondWith(
            caches.match(event.request).then((response) => {
                if (response) {
                    return response;
                }
                return fetch(event.request).then((networkResponse) => {
                    // Cache the new response, but don't block the response to the page.
                    if (networkResponse && networkResponse.status === 200) {
                        const responseToCache = networkResponse.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            cache.put(event.request, responseToCache);
                        });
                    }
                    return networkResponse;
                });
            })
        );
    }
});
