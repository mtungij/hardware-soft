const CACHE_VERSION = 'hardex-customer-pwa-v4';
const APP_SHELL_CACHE = `${CACHE_VERSION}-shell`;
const RUNTIME_CACHE = `${CACHE_VERSION}-runtime`;

const APP_SHELL = [
    '/offline',
    '/pwa/manifest.json',
    '/images/hardex.png',
    '/pwa/icons/icon-72x72.png',
    '/pwa/icons/icon-96x96.png',
    '/pwa/icons/icon-128x128.png',
    '/pwa/icons/icon-144x144.png',
    '/pwa/icons/icon-152x152.png',
    '/pwa/icons/icon-192x192.png',
    '/pwa/icons/icon-384x384.png',
    '/pwa/icons/icon-512x512.png'
];

const SENSITIVE_PATHS = [
    '/api/',
    '/customer/debts',
    '/customer/deposits',
    '/customer/receipts',
    '/customer/statements',
    '/customer/statement',
    '/customer/profile',
    '/customer/notifications',
    '/livewire/'
];

const isSensitiveRequest = (url) => SENSITIVE_PATHS.some((path) => url.pathname.startsWith(path));
const isCacheableAsset = (url) => (
    url.pathname.startsWith('/icons/')
    || url.pathname.startsWith('/pwa/icons/')
    || url.pathname.startsWith('/images/')
    || url.pathname === '/pwa/manifest.json'
    || url.pathname === '/offline'
);

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(APP_SHELL_CACHE)
            .then((cache) => Promise.allSettled(
                APP_SHELL.map((url) => cache.add(url))
            ))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => ![APP_SHELL_CACHE, RUNTIME_CACHE].includes(key))
                    .map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
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
        event.respondWith(
            fetch(request)
                .catch(() => caches.match('/offline'))
        );
        return;
    }

    if (isSensitiveRequest(url)) {
        return;
    }

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            fetch(request).catch(() => caches.match(request))
        );
        return;
    }

    if (isCacheableAsset(url)) {
        event.respondWith(
            caches.match(request).then((cached) => {
                const fetchPromise = fetch(request)
                    .then((response) => {
                        if (response.ok) {
                            const clone = response.clone();
                            caches.open(RUNTIME_CACHE).then((cache) => cache.put(request, clone));
                        }

                        return response;
                    })
                    .catch(() => cached);

                return cached || fetchPromise;
            })
        );
    }
});
