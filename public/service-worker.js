const ASSET_CACHE = 'fitness-challenge-assets-v2';
const PAGE_CACHE = 'fitness-challenge-pages-v3';
const META_CACHE = 'fitness-challenge-meta-v1';
const ACTIVE_USER_KEY = '/__fitness_active_user__';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const cacheNames = await caches.keys();
        await Promise.all(cacheNames
            .filter((name) => (name.startsWith('fitness-challenge-assets-') && name !== ASSET_CACHE)
                || (name.startsWith('fitness-challenge-pages-') && name !== PAGE_CACHE))
            .map((name) => caches.delete(name)));
        await self.clients.claim();
    })());
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    if (event.data?.type === 'CLEAR_PRIVATE_CACHES') {
        event.waitUntil(Promise.all([caches.delete(PAGE_CACHE), caches.delete(META_CACHE)]));
    }
    if (event.data?.type === 'SET_ACTIVE_USER') {
        const userId = Math.max(0, Number(event.data.userId || 0));
        event.waitUntil(caches.open(META_CACHE).then((cache) =>
            cache.put(ACTIVE_USER_KEY, new Response(String(userId)))
        ));
    }
});

const activeUserId = async () => {
    const cache = await caches.open(META_CACHE);
    const response = await cache.match(ACTIVE_USER_KEY);
    return response ? Math.max(0, Number(await response.text()) || 0) : 0;
};

const privateCacheKey = (request, userId) => {
    const cacheUrl = new URL(request.url);
    cacheUrl.searchParams.set('__fitness_user_cache', String(userId));
    return new Request(cacheUrl.toString(), { method: 'GET', credentials: 'same-origin' });
};

const offlinePage = () => new Response(
    '<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sin conexión</title><style>body{font-family:system-ui;margin:0;min-height:100vh;display:grid;place-items:center;background:#0b1116;color:#fff}main{max-width:32rem;padding:2rem;text-align:center}button{padding:.8rem 1rem;border:0;border-radius:12px}</style><main><h1>Sin conexión</h1><p>Abre una sección visitada anteriormente o recupera la conexión para continuar.</p><button onclick="location.reload()">Reintentar</button></main></html>',
    { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' } }
);

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (url.pathname !== '/asset.php') {
        if (request.mode === 'navigate') {
            event.respondWith((async () => {
                const pageCache = await caches.open(PAGE_CACHE);
                try {
                    const response = await fetch(request);
                    const responseUserId = Math.max(0, Number(response.headers.get('X-Fitness-User-Id') || 0));
                    if (response.ok && responseUserId > 0 && !url.searchParams.get('page')?.startsWith('api_')) {
                        await pageCache.put(privateCacheKey(request, responseUserId), response.clone());
                    }
                    return response;
                } catch {
                    const userId = await activeUserId();
                    if (userId <= 0) return offlinePage();
                    return (await pageCache.match(privateCacheKey(request, userId)))
                        || (await pageCache.match(privateCacheKey(new Request(new URL('/?page=dashboard', self.location.origin)), userId)))
                        || offlinePage();
                }
            })());
        }
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
