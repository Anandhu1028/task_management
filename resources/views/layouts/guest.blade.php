<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
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
