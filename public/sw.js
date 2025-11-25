// Minimal Service Worker for PWA Installation
// No caching or offline functionality - just satisfies PWA requirements

self.addEventListener('install', (event) => {
  // Skip waiting to activate immediately
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  // Claim all clients immediately
  event.waitUntil(clients.claim());
});

// Fetch event - just pass through to network (no caching)
self.addEventListener('fetch', (event) => {
  // No caching, just fetch from network
  event.respondWith(fetch(event.request));
});
