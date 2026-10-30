@if ($tasks->hasPages())
    <nav class="pwa-pager" aria-label="Pagination">
        @if ($tasks->onFirstPage())<span class="pwa-btn pwa-btn--ghost is-disabled">Previous</span>@else<a class="pwa-btn pwa-btn--ghost" href="{{ $tasks->previousPageUrl() }}">Previous</a>@endif
        <span class="pwa-pager__info">Page {{ $tasks->currentPage() }} of {{ $tasks->lastPage() }}</span>
        @if ($tasks->hasMorePages())<a class="pwa-btn pwa-btn--ghost" href="{{ $tasks->nextPageUrl() }}">Next</a>@else<span class="pwa-btn pwa-btn--ghost is-disabled">Next</span>@endif
    </nav>
@endif
