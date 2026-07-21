const ASSET_CACHE = 'fitness-challenge-assets-v1';

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const cacheNames = await caches.keys();
        await Promise.all(cacheNames
            .filter((name) => name.startsWith('fitness-challenge-assets-') && name !== ASSET_CACHE)
            .map((name) => caches.delete(name)));
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin || url.pathname !== '/asset.php') {
        return;
    }

    event.respondWith((async () => {
        const cache = await caches.open(ASSET_CACHE);
        const cached = await cache.match(request);
        if (cached) {
            return cached;
        }

        const response = await fetch(request);
        if (response.ok) {
            await cache.put(request, response.clone());
            const requestedFile = url.searchParams.get('file');
            if (requestedFile) {
                const keys = await cache.keys();
                await Promise.all(keys.map((key) => {
                    const cachedUrl = new URL(key.url);
                    return cachedUrl.searchParams.get('file') === requestedFile && key.url !== request.url
                        ? cache.delete(key)
                        : Promise.resolve(false);
                }));
            }
        }
        return response;
    })());
});
