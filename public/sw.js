const CACHE_NAME = 'task-manager-public-v1';
const ASSETS = ['/manifest.webmanifest', '/icons/task-manager-192.png', '/icons/task-manager-512.png'];

self.addEventListener('install', (event) => event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(ASSETS))));
self.addEventListener('activate', (event) => event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key.startsWith('task-manager-public-') && key !== CACHE_NAME).map((key) => caches.delete(key))))));
self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET' || request.mode === 'navigate' || new URL(request.url).origin !== self.location.origin || !ASSETS.includes(new URL(request.url).pathname)) return;
    event.respondWith(caches.match(request).then((response) => response || fetch(request)));
});
