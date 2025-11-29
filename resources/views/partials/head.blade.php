<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $title ?? \App\Models\Setting::get('app.name', config('app.name')) }}</title>

<link rel="icon" href="/images/kopdes-logo.png" type="image/png">
<link rel="apple-touch-icon" href="/images/kopdes-logo.png">

<!-- PWA Manifest -->
<link rel="manifest" href="/manifest.json">

<!-- PWA Meta Tags -->
<meta name="theme-color" content="#ffffff">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="{{ \App\Models\Setting::get('app.name', config('app.name')) }}">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

<!-- Leaflet CSS - must be loaded before app CSS to avoid conflicts -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous" referrerpolicy="no-referrer" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance

<!-- PWA Service Worker Registration -->
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js')
                .then(registration => {
                    console.log('ServiceWorker registered:', registration);
                })
                .catch(error => {
                    console.log('ServiceWorker registration failed:', error);
                });
        });
    }
</script>
