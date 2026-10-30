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
    <meta name="viewport" content="width=device-width, initial-scale=1" >
    <meta name="csrf-token"content="{{ csrf_token() }}" >
    <meta name="theme-color" content="{{ config('pwa.theme_color') }}">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" href="{{ asset('icons/task-manager-192.png') }}" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('icons/task-manager-192.png') }}">
    <title>
        @yield('title', 'Dashboard') · TaskFlow
    </title>
    {{-- Font --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" >
    {{-- Bootstrap --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" >
    {{-- Bootstrap Icons --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" >
    {{-- Shared and route-specific styles --}}
    <link href="{{ asset('css/shared.css') }}?v={{ filemtime(public_path('css/shared.css')) }}" rel="stylesheet" >
    @if (request()->routeIs('dashboard', 'dashboard.*'))
        <link href="{{ asset('css/dashboard.css') }}?v={{ filemtime(public_path('css/dashboard.css')) }}" rel="stylesheet">
    @endif
    @if (request()->routeIs('tasks.*', 'daily-tasks.*'))
        <link href="{{ asset('css/tasks.css') }}?v={{ filemtime(public_path('css/tasks.css')) }}" rel="stylesheet">
    @endif
    @if (request()->routeIs('users.*'))
        <link href="{{ asset('css/users.css') }}?v={{ filemtime(public_path('css/users.css')) }}" rel="stylesheet">
    @endif
    @if (request()->routeIs('companies.*'))
        <link href="{{ asset('css/companies.css') }}?v={{ filemtime(public_path('css/companies.css')) }}" rel="stylesheet">
    @endif
    @if (request()->routeIs('departments.*'))
        <link href="{{ asset('css/departments.css') }}?v={{ filemtime(public_path('css/departments.css')) }}" rel="stylesheet">
    @endif
    @if (request()->routeIs('reports.*'))
        <link href="{{ asset('css/reports.css') }}?v={{ filemtime(public_path('css/reports.css')) }}" rel="stylesheet">
    @endif
    @if (request()->routeIs('roles.*'))
        <link href="{{ asset('css/roles.css') }}?v={{ filemtime(public_path('css/roles.css')) }}" rel="stylesheet">
    @endif
    
</head>


<body class="tf-body">

    @include('components.sidebar')

    <div class="tf-overlay" data-tf-overlay ></div>

    <div class="tf-shell">
        @include('components.navbar')
        <main class="tf-main">
            @yield('content')
        </main>
    </div>


    @if (auth()->user()->can('tasks.create') || auth()->user()->can('tasks.update'))
        @include('components.create-task-modal')
    @endif

    {{-- ChatGPT Launcher Modal --}}
    @include('components.chatgpt-modal')


    <div class="tf-operation" id="tfOperation" role="dialog" aria-modal="true" aria-labelledby="tfOperationTitle" aria-describedby="tfOperationMessage" hidden>
        <section class="tf-operation__card">
            
            <span class="tf-operation__indicator" data-tf-operation-indicator>
                <span class="tf-operation__spinner" data-tf-operation-spinner></span>
                <i class="bi bi-check-lg" data-tf-operation-complete-icon hidden></i>
            </span>
            <h2 id="tfOperationTitle" data-tf-operation-title>Updating status...</h2>
            <p id="tfOperationMessage" data-tf-operation-message>Please wait a moment.</p>
            <div class="tf-operation__transition" data-tf-operation-transition hidden>
                <span data-tf-operation-from></span>
                <i class="bi bi-arrow-right"></i>
                <span data-tf-operation-to></span>
            </div>
        </section>
    </div>

    @if (session('success') && ! session('operation_status'))
        <div class="tf-toast tf-toast--success" id="tfToast" role="status" aria-live="polite" data-tf-initial-toast>
            <span class="tf-toast__icon"><i class="bi bi-check-lg"></i></span>
            <span class="tf-toast__message" data-tf-toast-message>{{ session('success') }}</span>
            
        </div>
    @endif


    {{-- Bootstrap --}}
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    {{-- Chart.js --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
    <script>
        window.tfCompletedOperation = @json(session('auto_open_reassign') ? null : session('operation_status'));
    </script>
    {{-- Dashboard JS --}}
    <script src="{{ asset('js/dashboard.js') }}?v={{ filemtime(public_path('js/dashboard.js')) }}"></script>
    @stack('scripts')
    <button type="button" data-pwa-install hidden class="tf-btn tf-btn--ghost" style="position:fixed;right:1rem;bottom:1rem;z-index:1100">Install app</button>
    <script src="{{ asset('js/pwa-install.js') }}"></script>

</body>

</html>
