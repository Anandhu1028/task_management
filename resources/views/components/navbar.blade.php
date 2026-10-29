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
                    <strong>Notifications</strong>
                    <form method="POST" action="{{ route('notifications.read-all') }}"
                          data-tf-mark-read-form @if (($unreadNotificationCount ?? 0) === 0) hidden @endif>
                            @csrf
                            <button class="tf-link-btn" type="submit" data-tf-mark-read>Mark all as read</button>
                    </form>
                </div>

                <div class="tf-notify-list" data-tf-notification-list>
                    @foreach ($notifications ?? [] as $note)
                        <a class="tf-notify {{ $note->read_at === null ? 'is-unread' : '' }}" href="{{ route('notifications.open', $note->id) }}">
                            <span class="tf-notify__dot"></span>
                            <span>
                                <span class="tf-notify__title">{{ $note->data['title'] ?? 'Task update' }}</span>
                                @if (!empty($note->data['actor_name']))
                                    <span class="tf-notify__actor text-muted" style="font-size: 11px; display: block; margin-top: 1px;">
                                        <i class="bi bi-person"></i> {{ $note->data['actor_name'] }}{{ !empty($note->data['actor_role']) ? ' (' . $note->data['actor_role'] . ')' : '' }}
                                    </span>
                                @endif
                                @if (!empty($note->data['task_title']))
                                    <span class="tf-notify__body" style="font-weight: 600;">{{ $note->data['task_title'] }}</span>
                                @endif
                                @if (!empty($note->data['body']))
                                    <span class="tf-notify__body">{{ $note->data['body'] }}</span>
                                @endif
                                <span class="tf-notify__time">{{ $note->created_at?->diffForHumans() }}</span>
                            </span>
                        </a>
                    @endforeach
                    @if (($notifications ?? collect())->isEmpty())
                        <p class="tf-notify__empty" data-tf-notification-empty>No notifications yet.</p>
                    @endif
                </div>

                <span class="tf-dropdown__foot">Showing your latest notifications</span>
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
