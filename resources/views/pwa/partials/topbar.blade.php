@php($pwaUser = auth()->user())
<header class="pwa-topbar {{ $class ?? '' }}">
    @include('pwa.partials.brand')
    <div class="pwa-topbar__right">
        <div class="pwa-user">
            <span class="pwa-user__name">{{ $pwaUser->name }}</span>
            <span class="pwa-user__role">{{ $pwaUser->getRoleNames()->first() }}</span>
        </div>
        <button type="button" class="pwa-icon-btn" data-refresh aria-label="Refresh">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
        </button>
        <form method="POST" action="{{ route('pwa.logout') }}">
            @csrf
            <button type="submit" class="pwa-btn pwa-btn--ghost">Sign out</button>
        </form>
    </div>
</header>
