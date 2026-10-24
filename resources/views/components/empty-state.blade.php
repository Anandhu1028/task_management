@props([
    'title' => '',
    'icon' => 'bi-inbox',
    'message' => '',
    'action' => null,
    'actionAttributes' => []
])

<div class="tf-empty">

    <i class="bi {{ $icon }}"></i>

    <h4>
        {{ $title }}
    </h4>

    <p>
        {{ $message }}
    </p>

    @if ($action)

        <button
            class="tf-btn tf-btn--ghost"
            type="button"
            {{ new \Illuminate\View\ComponentAttributeBag($actionAttributes) }}
        >
            {{ $action }}
        </button>

    @endif

</div>