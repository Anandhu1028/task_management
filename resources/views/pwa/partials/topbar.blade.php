@php($pwaUser = auth()->user())
<header class="pwa-topbar {{ $class ?? '' }}">
    @include('pwa.partials.brand')
    <div class="pwa-topbar__right">
        <div class="pwa-user">
            <span class="pwa-user__name">{{ $pwaUser->name }}</span>
            <span class="pwa-user__role">{{ $pwaUser->getRoleNames()->first() }}</span>
        </div>
        <button type="button" class="pwa-icon-btn" hidden
                data-tf-desktop-notify data-mode="pwa" data-poll-url="{{ route('notifications.poll') }}"
                aria-label="Enable desktop notifications" title="Enable desktop notifications">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        </button>
        <button type="button" class="pwa-icon-btn" data-refresh aria-label="Refresh">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
        </button>
        <form method="POST" action="{{ route('pwa.logout') }}">
            @csrf
            <button type="submit" class="pwa-btn pwa-btn--ghost">Sign out</button>
        </form>
    </div>
</header>
