@props(['lines' => 4])

<div class="tf-skeleton" aria-hidden="true">

    @for ($i = 0; $i < $lines; $i++)

        <span
            class="tf-skeleton__line"
            style="width: {{ $i === $lines - 1 ? '62%' : '100%' }}"
        ></span>

    @endfor

</div>