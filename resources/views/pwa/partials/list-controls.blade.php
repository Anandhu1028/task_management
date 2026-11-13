<div class="pwa-list-toolbar">
    <form method="GET" action="{{ $action }}" class="pwa-search" role="search">
        <input type="hidden" name="filter" value="{{ $filter }}">
        <input type="hidden" name="sort" value="{{ $sort ?? 'deadline_priority' }}">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search" name="search" value="{{ $search }}" maxlength="100" placeholder="Search title, code, people…" aria-label="Search tasks">
    </form>
    <nav class="pwa-chips" aria-label="Filter tasks">
        @foreach ($filters as $key => $label)
            <a href="{{ $action }}?{{ http_build_query(array_filter(['filter' => $key === 'all' ? null : $key, 'search' => $search ?: null, 'sort' => $sort ?? 'deadline_priority'])) }}"
               class="pwa-chip {{ $filter === $key ? 'is-active' : '' }}" @if ($filter === $key) aria-current="true" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    @if ($showSort ?? false)
        <form method="GET" action="{{ $action }}" class="pwa-sort-form">
            <input type="hidden" name="filter" value="{{ $filter }}">
            <input type="hidden" name="search" value="{{ $search }}">
            <label class="pwa-sort">
                <span>Sort by:</span>
                <select name="sort" aria-label="Sort tasks" data-pwa-sort>
                    @foreach ($sortOptions as $sortKey => $sortLabel)
                        <option value="{{ $sortKey }}" @selected(($sort ?? 'deadline_priority') === $sortKey)>{{ $sortLabel }}</option>
                    @endforeach
                </select>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </label>
        </form>
    @endif
</div>
