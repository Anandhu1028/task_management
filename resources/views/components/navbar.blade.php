<header class="tf-navbar">

    <button class="tf-icon-btn d-lg-none" type="button" data-tf-open-sidebar aria-label="Open menu">
        <i class="bi bi-list"></i>
    </button>

    {{-- Global Search --}}
    <form class="tf-search" role="search" onsubmit="return false;">
        <i class="bi bi-search"></i>
        <input type="search" id="tfGlobalSearch" placeholder="Search tasks, projects, people..." autocomplete="off">
    </form>

    <div class="tf-navbar__actions">

        {{-- Notifications --}}
        <div class="dropdown" data-tf-notification-poll="{{ route('notifications.poll') }}">
            <button class="tf-icon-btn tf-bell" type="button" data-bs-toggle="dropdown"
                    data-bs-auto-close="outside" aria-label="Notifications" data-tf-notification-bell>
                <i class="bi bi-bell"></i>
                @if (($unreadNotificationCount ?? 0) > 0)
                    <span class="tf-bell__dot" data-tf-notification-dot></span>
                @endif
            </button>

            <div class="dropdown-menu dropdown-menu-end tf-dropdown tf-dropdown--wide">
                <div class="tf-dropdown__head">
                    <div class="tf-dropdown__heading">
                        <strong>Notifications</strong>
                        <span
                            class="tf-notify__count"
                            data-tf-notification-count
                            aria-label="{{ $unreadNotificationCount ?? 0 }} unread"
                            @if (($unreadNotificationCount ?? 0) === 0) hidden @endif
                        >{{ $unreadNotificationCount ?? 0 }}</span>
                    </div>
                    <form method="POST" action="{{ route('notifications.read-all') }}"
                          data-tf-mark-read-form @if (($unreadNotificationCount ?? 0) === 0) hidden @endif>
                        @csrf
                        <button class="tf-link-btn tf-notify__mark-read" type="submit" data-tf-mark-read>
                            <i class="bi bi-check2-all" aria-hidden="true"></i>
                            Mark all as read
                        </button>
                    </form>
                </div>

                <div class="tf-notify-list" data-tf-notification-list>
                    @foreach ($notifications ?? [] as $note)
                        @include('notifications._item', ['item' => \App\Support\NotificationPresenter::present($note)])
                    @endforeach
                    @if (($notifications ?? collect())->isEmpty())
                        <p class="tf-notify__empty" data-tf-notification-empty>No notifications yet.</p>
                    @endif
                </div>

                <a class="tf-dropdown__foot" href="{{ route('notifications.index') }}">
                    <span>View all notifications</span>
                    <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
        </div>

        <div class="dropdown tf-account-menu">
            <button
                class="tf-icon-btn tf-account-menu__toggle"
                type="button"
                data-bs-toggle="dropdown"
                aria-expanded="false"
                aria-haspopup="true"
                aria-label="Account menu"
            >
                <span class="tf-account-menu__avatar" aria-hidden="true">
                    {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                </span>
            </button>

            <div class="dropdown-menu dropdown-menu-end tf-account-menu__menu">
                <div class="tf-account-menu__identity">
                    <span class="tf-account-menu__avatar tf-account-menu__avatar--menu" aria-hidden="true">
                        {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                    </span>
                    <div class="tf-account-menu__identity-details">
                        <strong>{{ auth()->user()->name }}</strong>
                        <span>{{ auth()->user()->getRoleNames()->first() ?? 'User' }}</span>
                    </div>
                </div>
                <div class="tf-account-menu__divider" role="separator"></div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="tf-account-menu__signout" type="submit">
                        <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                        Sign out
                    </button>
                </form>
            </div>
        </div>

    </div>

</header>
