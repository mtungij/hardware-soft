const CACHE_VERSION = 'hardex-pwa-v5';
const SHELL_CACHE = `${CACHE_VERSION}-shell`;
const RUNTIME_CACHE = `${CACHE_VERSION}-runtime`;
const SHELL_ASSETS = [
    '/offline',
    '/images/hardex.png',
    '/icons/icon-192x192.png',
    '/icons/icon-512x512.png'
];

const PRIVATE_PATHS = [
    '/api/',
    '/livewire/',
    '/customer/',
    '/storage/',
    '/pwa/brand-icon/',
    '/pwa/manifest.json',
    '/manifest.webmanifest',
    '/manifest.json'
];

const isPrivateRequest = (url) => PRIVATE_PATHS.some((path) => url.pathname === path || url.pathname.startsWith(path));
const isGenericAsset = (url) => url.pathname.startsWith('/icons/')
    || url.pathname.startsWith('/images/')
    || url.pathname === '/offline';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(SHELL_CACHE)
        .then((cache) => Promise.allSettled(SHELL_ASSETS.map((url) => cache.add(url))))
        .then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys()
        .then((keys) => Promise.all(keys
            .filter((key) => key.startsWith('hardex-') && ![SHELL_CACHE, RUNTIME_CACHE].includes(key))
            .map((key) => caches.delete(key))))
        .then(() => self.clients.claim()));
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match('/offline')));
        return;
    }

    // Never store a tenant manifest, generated icon, logo, or authenticated response.
    if (isPrivateRequest(url)) {
        event.respondWith(fetch(request, { cache: 'no-store' }));
        return;
    }

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(fetch(request));
        return;
    }

    if (isGenericAsset(url)) {
        event.respondWith(fetch(request)
            .then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(RUNTIME_CACHE).then((cache) => cache.put(request, copy));
                }
                return response;
            })
            .catch(() => caches.match(request)));
    }
});
