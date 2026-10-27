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
            <a class="tf-nav__item {{ request()->routeIs('reports.*') ? 'is-active' : '' }}" href="{{ route('reports.index') }}">
                <i class="bi bi-bar-chart-line"></i><span>Reports</span>
            </a>
        @endif

        {{-- ChatGPT Assistant --}}
        <p class="tf-nav__section">AI TOOLS</p>
        <button
            class="tf-nav__item tf-nav__chatgpt-btn"
            type="button"
            id="tfChatGptNavBtn"
            aria-haspopup="dialog"
            aria-controls="tfChatGptModal"
            data-tf-chatgpt-open
        >
            <span class="tf-chatgpt-icon" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 41 41" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M37.532 16.87a9.963 9.963 0 0 0-.856-8.184 10.078 10.078 0 0 0-10.855-4.835 9.964 9.964 0 0 0-6.284-3.676 10.079 10.079 0 0 0-10.63 4.931 9.961 9.961 0 0 0-6.663 4.834 10.08 10.08 0 0 0 1.24 11.817 9.965 9.965 0 0 0 .856 8.185 10.079 10.079 0 0 0 10.855 4.835 9.965 9.965 0 0 0 6.284 3.675 10.079 10.079 0 0 0 10.63-4.931 9.96 9.96 0 0 0 6.663-4.834 10.079 10.079 0 0 0-1.24-11.817zm-14.54 20.282a7.474 7.474 0 0 1-4.798-1.735c.061-.033.168-.091.237-.134l7.964-4.6a1.294 1.294 0 0 0 .655-1.134V19.054l3.366 1.944a.12.12 0 0 1 .066.092v9.299a7.505 7.505 0 0 1-7.49 7.763zm-16.134-6.874a7.47 7.47 0 0 1-.894-5.023c.06.036.162.099.237.141l7.964 4.6a1.297 1.297 0 0 0 1.308 0l9.724-5.614v3.888a.12.12 0 0 1-.048.103l-8.051 4.649a7.504 7.504 0 0 1-10.24-2.744zm-2.09-17.49a7.47 7.47 0 0 1 3.897-3.286c0 .068-.004.19-.004.274v9.201a1.294 1.294 0 0 0 .654 1.132l9.723 5.614-3.366 1.944a.12.12 0 0 1-.114.012L7.544 23.86a7.505 7.505 0 0 1-2.776-10.071zm27.658 6.437l-9.724-5.615 3.367-1.943a.121.121 0 0 1 .114-.012l8.048 4.648a7.498 7.498 0 0 1-1.158 13.528v-9.476a1.293 1.293 0 0 0-.647-1.13zm3.35-5.043c-.059-.037-.162-.099-.236-.141l-7.965-4.6a1.298 1.298 0 0 0-1.308 0l-9.723 5.614v-3.888a.12.12 0 0 1 .048-.103l8.05-4.645a7.497 7.497 0 0 1 11.135 7.763zm-21.063 6.929l-3.367-1.944a.12.12 0 0 1-.065-.092v-9.299a7.497 7.497 0 0 1 12.293-5.756 6.94 6.94 0 0 0-.236.134l-7.965 4.6a1.294 1.294 0 0 0-.654 1.132l-.006 11.225zm1.829-3.943l4.33-2.501 4.332 2.5v4.999l-4.331 2.5-4.331-2.5V18.188z" fill="currentColor"/>
                </svg>
            </span>
            <span>ChatGPT</span>
            <span class="tf-chatgpt-badge" aria-label="AI">AI</span>
        </button>
    </nav>

    <div class="tf-sidebar__bottom">
        <a class="tf-nav__item" href="#"><i class="bi bi-gear"></i><span>Settings</span></a>
    </div>

</aside>