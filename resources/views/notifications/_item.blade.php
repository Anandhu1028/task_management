@php($tag = $item['url'] ? 'a' : 'div')
<{{ $tag }} class="tf-notify {{ $item['is_unread'] ? 'is-unread' : '' }}" @if ($item['url']) href="{{ $item['url'] }}" @endif>
    <span class="tf-notify__dot"></span>
    <span class="tf-notify__icon" aria-hidden="true"><i class="bi {{ $item['icon'] }}"></i></span>
    <span class="tf-notify__content">
        <span class="tf-notify__title">{{ $item['title'] }}</span>
        @if ($item['message'])
            <span class="tf-notify__message">{{ $item['message'] }}</span>
        @endif
        @if ($item['task_label'])
            <span class="tf-notify__task">{{ $item['task_label'] }}</span>
        @endif
        @if ($item['subtask_title'])
            <span class="tf-notify__subtask">Subtask: {{ $item['subtask_title'] }}</span>
        @endif
        <span class="tf-notify__time" title="{{ $item['created_at_exact'] }}">{{ $item['created_at'] }}</span>
    </span>
</{{ $tag }}>
