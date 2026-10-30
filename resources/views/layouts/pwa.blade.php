<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
@php
    $pwaCssPath = public_path('css/pwa.css');
    $pwaJavaScriptPath = public_path('js/pwa.js');
@endphp
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ config('pwa.theme_color') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" href="{{ asset('icons/task-manager-192.png') }}" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('icons/task-manager-192.png') }}">
    <title>@yield('title', 'TaskFlow')</title>
    <link rel="stylesheet" href="{{ asset('css/pwa.css') }}?v={{ file_exists($pwaCssPath) ? filemtime($pwaCssPath) : 1 }}">
</head>
<body class="pwa @yield('body_class')">
@yield('content')
<button type="button" data-pwa-install hidden class="fixed bottom-4 right-4 z-50 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-lg">Install app</button>
<script src="{{ asset('js/pwa.js') }}?v={{ file_exists($pwaJavaScriptPath) ? filemtime($pwaJavaScriptPath) : 1 }}" defer></script>
<script src="{{ asset('js/pwa-install.js') }}"></script>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('{{ asset('sw.js') }}', { scope: '/' }));
}
</script>
</body>
</html>
