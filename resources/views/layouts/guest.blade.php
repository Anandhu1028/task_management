<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    {{-- Installed PWA launched into the admin app (old start_url) → send it to the PWA task list. --}}
    <script>
        if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) {
            window.location.replace('{{ route('pwa.tasks.index') }}');
        }
    </script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ config('pwa.theme_color') }}">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" href="{{ asset('icons/task-manager-192.png') }}" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('icons/task-manager-192.png') }}">
    <title>@yield('title', 'Account') · TaskFlow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="{{ asset('css/shared.css') }}?v={{ filemtime(public_path('css/shared.css')) }}" rel="stylesheet">
</head>
<body class="tf-guest-body">
    @hasSection('full_width_content')
        @yield('content')
    @else
        <main class="container min-vh-100 d-flex align-items-center justify-content-center py-5">
            <div class="w-100" style="max-width: 440px;">
                <a class="d-block text-center text-decoration-none fw-bold fs-3 mb-4 text-dark" href="{{ route('login') }}">TaskFlow</a>
                @yield('content')
            </div>
        </main>
    @endif
</body>
</html>
