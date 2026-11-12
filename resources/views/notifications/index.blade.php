@extends('layouts.app')

@section('title', 'Notifications')

@section('content')
    <div class="tf-page-head">
        <div>
            <h1>Notifications</h1>
            <p>{{ $unreadCount }} unread</p>
        </div>
        <div class="tf-notify-page__actions">
            <a class="tf-link-btn {{ $unreadOnly ? '' : 'is-active' }}" href="{{ route('notifications.index') }}">All</a>
            <a class="tf-link-btn {{ $unreadOnly ? 'is-active' : '' }}" href="{{ route('notifications.index', ['filter' => 'unread']) }}">Unread</a>
            @if ($unreadCount > 0)
                <form method="POST" action="{{ route('notifications.read-all') }}" class="d-inline">
                    @csrf
                    <button class="tf-link-btn" type="submit">Mark all as read</button>
                </form>
            @endif
        </div>
    </div>

    <div class="tf-notify-page">
        @forelse ($items as $item)
            @include('notifications._item', ['item' => $item])
        @empty
            <p class="tf-notify__empty">{{ $unreadOnly ? 'No unread notifications.' : 'No notifications yet.' }}</p>
        @endforelse
    </div>

    <div class="mt-3">{{ $paginator->links() }}</div>
@endsection
