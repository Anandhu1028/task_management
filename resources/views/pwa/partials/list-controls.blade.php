<form method="GET" action="{{ $action }}" class="pwa-search" role="search">
    <input type="hidden" name="filter" value="{{ $filter }}">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    <input type="search" name="search" value="{{ $search }}" maxlength="100" placeholder="Search title, code, people…" aria-label="Search tasks">
</form>
<nav class="pwa-chips" aria-label="Filter tasks">
    @foreach ($filters as $key => $label)
        <a href="{{ $action }}?{{ http_build_query(array_filter(['filter' => $key === 'all' ? null : $key, 'search' => $search ?: null])) }}"
           class="pwa-chip {{ $filter === $key ? 'is-active' : '' }}" @if ($filter === $key) aria-current="true" @endif>{{ $label }}</a>
    @endforeach
</nav>
