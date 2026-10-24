<aside class="tf-sidebar" id="tfSidebar" aria-label="Primary">

    <div class="tf-sidebar__top">
        <a href="{{ route('dashboard') }}" class="tf-brand">
            <span class="tf-brand__mark">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="12 2 2 7 12 12 22 7 12 2"></polygon>
                    <polyline points="2 17 12 22 22 17"></polyline>
                    <polyline points="2 12 12 17 22 12"></polyline>
                </svg>
            </span>
            <span class="tf-brand__text">TaskFlow</span>
        </a>
    </div>

    <div class="tf-workspace-wrap">
        <div class="tf-workspace" aria-label="Signed-in user">
            <span class="tf-workspace__icon-box">
                <i class="bi bi-person"></i>
            </span>
            <span class="tf-workspace__copy">
                <span class="tf-workspace__name">{{ auth()->user()->name }}</span>
                <span class="tf-workspace__role">{{ auth()->user()->getRoleNames()->first() ?? auth()->user()->primaryRole?->name ?? 'No role assigned' }}</span>
            </span>
        </div>
    </div>

    @php
        $canViewUsers = auth()->user()->can('users.view');
        $canViewCompanies = auth()->user()->can('companies.view');
        $canViewDepartments = auth()->user()->can('departments.view');
        $canViewTasks = auth()->user()->can('tasks.view');
        $usersNavigationIsActive = request()->routeIs('users.*');
        $companyNavigationIsActive = request()->routeIs('companies.*', 'departments.*');
    @endphp

    <nav class="tf-nav">
        <p class="tf-nav__section">MAIN</p>

        <a class="tf-nav__item {{ request()->routeIs('dashboard') ? 'is-active' : '' }}" href="{{ route('dashboard') }}">
            <i class="bi bi-grid"></i><span>Dashboard</span>
        </a>

        @if ($canViewUsers)
            <div class="tf-nav__group {{ $usersNavigationIsActive ? 'is-open' : '' }}">
                <button
                    class="tf-nav__item tf-nav__toggle {{ request()->routeIs('users.*') ? 'is-active' : '' }}"
                    type="button"
                    data-tf-submenu-toggle
                    aria-expanded="{{ $usersNavigationIsActive ? 'true' : 'false' }}"
                    aria-controls="usersSubmenu"
                >
                    <i class="bi bi-people"></i>
                    <span>Users</span>
                    <i class="bi bi-chevron-down tf-nav__caret" aria-hidden="true"></i>
                </button>
                <div class="tf-nav__subitems" id="usersSubmenu" @if (! $usersNavigationIsActive) hidden @endif>
                    <a class="tf-nav__subitem {{ request()->routeIs('users.*') ? 'is-active' : '' }}" href="{{ route('users.index') }}">
                        <span class="tf-nav__subitem-dot" aria-hidden="true"></span>
                        <span>User Management</span>
                    </a>
                </div>
            </div>
        @endif

        @if ($canViewCompanies || $canViewDepartments)
            <div class="tf-nav__group {{ $companyNavigationIsActive ? 'is-open' : '' }}">
                <button
                    class="tf-nav__item tf-nav__toggle {{ request()->routeIs('companies.*', 'departments.*') ? 'is-active' : '' }}"
                    type="button"
                    data-tf-submenu-toggle
                    aria-expanded="{{ $companyNavigationIsActive ? 'true' : 'false' }}"
                    aria-controls="companiesSubmenu"
                >
                    <i class="bi bi-building"></i>
                    <span>Company</span>
                    <i class="bi bi-chevron-down tf-nav__caret" aria-hidden="true"></i>
                </button>
                <div class="tf-nav__subitems" id="companiesSubmenu" @if (! $companyNavigationIsActive) hidden @endif>
                    @if ($canViewCompanies)
                        <a class="tf-nav__subitem {{ request()->routeIs('companies.*') ? 'is-active' : '' }}" href="{{ route('companies.index') }}">
                            <span class="tf-nav__subitem-dot" aria-hidden="true"></span>
                            <span>Company Management</span>
                        </a>
                    @endif
                    @if ($canViewDepartments)
                        <a class="tf-nav__subitem {{ request()->routeIs('departments.*') ? 'is-active' : '' }}" href="{{ route('departments.index') }}">
                            <span class="tf-nav__subitem-dot" aria-hidden="true"></span>
                            <span>Department Management</span>
                        </a>
                    @endif
                </div>
            </div>
        @endif

        @if ($canViewTasks)
            <a class="tf-nav__item {{ request()->routeIs('tasks.*') ? 'is-active' : '' }}" href="{{ route('tasks.index') }}">
                <i class="bi bi-check2-square"></i><span>All Tasks</span>
            </a>
        @endif
    </nav>

    <div class="tf-sidebar__bottom">
        <a class="tf-nav__item" href="#"><i class="bi bi-gear"></i><span>Settings</span></a>
    </div>

</aside>