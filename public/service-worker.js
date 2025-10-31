const CACHE_NAME = "wh-crm-cache-9326b1cv2"; // bump version
const urlsToCache = ["/manifest.json"]; // do NOT pre-cache "/"

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
            .then((cacheNames) =>
                Promise.all(
                    cacheNames.map((cacheName) => {
                        if (!cacheWhitelist.includes(cacheName)) {
                            return caches.delete(cacheName);
                        }
                    })
                )
            )
            .then(() => self.clients.claim())
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
                } catch (e) {
                    const cached = await caches.match("/");
                    return (
                        cached ||
                        new Response("Offline", {
                            status: 503,
                            headers: { "Content-Type": "text/plain" },
                        })
                    );
                }
            })()
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
                            cache.put(event.request, responseToCache)
                        );
                }
                return networkResponse;
            });
        })
    );
});
