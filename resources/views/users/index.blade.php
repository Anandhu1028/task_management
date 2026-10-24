@extends('layouts.app')

@section('title', 'User Management')

@section('content')
    <header class="tf-page-head">
        <div>
            <h1>User Management</h1>
            <p>Manage employees, user accounts, departments, and access status.</p>
        </div>
        <div class="tf-page-head__actions tf-user-page-actions">
            @can('users.delete')
                <form method="POST" action="{{ route('users.bulk-destroy') }}" class="tf-user-bulk-delete-form" data-user-bulk-delete-form hidden>
                    @csrf
                    <span data-user-selection-inputs></span>
                    <button type="submit" class="tf-btn tf-user-action-btn tf-user-action-btn--bulk-delete" data-user-bulk-delete-button>
                        <i class="bi bi-trash3"></i>
                        Delete selected
                        <span class="tf-user-bulk-delete-count" data-user-selection-count>0</span>
                    </button>
                </form>
            @endcan
            @can('users.view')
                <a href="{{ route('users.export', request()->except(['page', 'per_page', 'create', 'edit'])) }}" class="tf-btn tf-btn--ghost tf-user-action-btn tf-user-action-btn--export">
                    <i class="bi bi-download"></i>
                    Export
                </a>
            @endcan
            @can('users.create')
                <button type="button" class="tf-btn tf-btn--primary tf-user-action-btn tf-user-action-btn--add" data-tf-add-user data-bs-toggle="modal" data-bs-target="#addUserPanel" aria-controls="addUserPanel">
                    <i class="bi bi-person-plus"></i>
                    Add User
                </button>
            @endcan
        </div>
    </header>


    @if (session('error'))
        <div class="tf-management__notice tf-management__notice--error" role="alert">{{ session('error') }}</div>
    @endif

    <section class="tf-user-summary" aria-label="User account summary">
        <article class="tf-user-summary__card tf-user-summary__card--total">
            <span class="tf-user-summary__icon"><i class="bi bi-people"></i></span>
            <div class="tf-user-summary__content">
                <span class="tf-user-summary__label">Total users</span>
                <strong>{{ number_format($counts->all_count) }}</strong>
                <small>Directory accounts</small>
            </div>
            <span class="tf-user-summary__trend"><i class="bi bi-person-check"></i></span>
        </article>
        <article class="tf-user-summary__card tf-user-summary__card--active">
            <span class="tf-user-summary__icon"><i class="bi bi-person-check"></i></span>
            <div class="tf-user-summary__content">
                <span class="tf-user-summary__label">Active users</span>
                <strong>{{ number_format($counts->active_count ?? 0) }}</strong>
                <small>Currently enabled</small>
            </div>
            <span class="tf-user-summary__trend"><i class="bi bi-check2"></i></span>
        </article>
        <article class="tf-user-summary__card tf-user-summary__card--inactive">
            <span class="tf-user-summary__icon"><i class="bi bi-person-dash"></i></span>
            <div class="tf-user-summary__content">
                <span class="tf-user-summary__label">Inactive users</span>
                <strong>{{ number_format($counts->inactive_count ?? 0) }}</strong>
                <small>Currently disabled</small>
            </div>
            <span class="tf-user-summary__trend"><i class="bi bi-pause-circle"></i></span>
        </article>
        <article class="tf-user-summary__card tf-user-summary__card--rate">
            <span class="tf-user-summary__icon"><i class="bi bi-activity"></i></span>
            <div class="tf-user-summary__content">
                <span class="tf-user-summary__label">Active rate</span>
                <strong>{{ $activeRate }}<small>%</small></strong>
                <small>Share of all users</small>
            </div>
            <span class="tf-user-summary__trend"><i class="bi bi-graph-up-arrow"></i></span>
        </article>
    </section>

    <section class="tf-card tf-user-management">
        <nav class="tf-user-tabs" aria-label="Filter users by status">
            @foreach ([
                'all' => ['label' => 'All users', 'count' => $counts->all_count],
                'active' => ['label' => 'Active', 'count' => $counts->active_count],
                'inactive' => ['label' => 'Inactive', 'count' => $counts->inactive_count],
            ] as $key => $item)
                <a href="{{ route('users.index', array_merge(request()->except(['tab', 'page', 'status']), ['tab' => $key])) }}"
                   class="tf-user-tabs__item tf-user-tabs__item--{{ $key }} {{ $tab === $key ? 'is-active' : '' }}"
                   @if ($tab === $key) aria-current="page" @endif>
                    {{ $item['label'] }}
                    <span class="tf-user-tabs__count">{{ $item['count'] }}</span>
                </a>
            @endforeach
        </nav>

        <div class="tf-user-toolbar">
            <form method="GET" action="{{ route('users.index') }}" class="tf-user-toolbar__search-form tf-user-directory__search-form" data-user-filter-form>
                <input type="hidden" name="tab" value="{{ $tab }}" data-user-tab-filter>
                <input type="hidden" name="per_page" value="{{ request('per_page') }}">
                <input type="hidden" name="employee_id" value="{{ request('employee_id') }}">
                <input type="hidden" name="email" value="{{ request('email') }}">
                <input type="hidden" name="phone" value="{{ request('phone') }}">
                <input type="hidden" name="designation" value="{{ request('designation') }}">
                <label class="tf-user-search" for="userSearch">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input id="userSearch" type="search" name="search" value="{{ request('search') }}" placeholder="Search by name, email or ID" autocomplete="off">
                </label>
                <div class="tf-user-directory__filters">
                    <label class="tf-user-toolbar__select" for="userCompanyFilter">
                        <select id="userCompanyFilter" name="company_id" aria-label="Filter by company" data-user-company-filter>
                            <option value="">All companies</option>
                            @foreach ($companies as $company)
                                <option value="{{ $company->id }}" @selected(request('company_id') == $company->id)>{{ $company->name }}</option>
                            @endforeach
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                    <label class="tf-user-toolbar__select" for="userDepartmentFilter">
                        <select id="userDepartmentFilter" name="department_id" aria-label="Filter by department">
                            <option value="">All departments</option>
                            @foreach ($departmentFilterOptions as $department)
                                <option value="{{ $department->id }}" data-company-id="{{ $department->company_id }}" @selected(request('department_id') == $department->id)>{{ $department->name }}</option>
                            @endforeach
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                    <label class="tf-user-toolbar__select" for="userTasksFilter">
                        <select id="userTasksFilter" name="tasks" aria-label="Filter by tasks">
                            <option value="">All tasks</option>
                            <option value="with" @selected(request('tasks') === 'with')>Has tasks</option>
                            <option value="without" @selected(request('tasks') === 'without')>No tasks</option>
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                    <label class="tf-user-toolbar__select" for="userPhoneFilter">
                        <select id="userPhoneFilter" name="phone_presence" aria-label="Filter by phone">
                            <option value="">All phone numbers</option>
                            <option value="with" @selected(request('phone_presence') === 'with')>Has phone</option>
                            <option value="without" @selected(request('phone_presence') === 'without')>No phone</option>
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                    <label class="tf-user-toolbar__select" for="userStatusFilter">
                        <select id="userStatusFilter" name="status" aria-label="Filter by status" data-user-status-filter>
                            <option value="">All statuses</option>
                            <option value="active" @selected((request('status') ?: ($tab === 'all' ? '' : $tab)) === 'active')>Active</option>
                            <option value="inactive" @selected((request('status') ?: ($tab === 'all' ? '' : $tab)) === 'inactive')>Inactive</option>
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                </div>
            </form>
            @if (request()->hasAny(['search', 'employee_id', 'email', 'phone', 'phone_presence', 'designation', 'status', 'company_id', 'department_id', 'tasks']))
                <a href="{{ route('users.index', ['tab' => $tab]) }}" class="tf-text-link tf-user-toolbar__reset">Reset</a>
            @endif
        </div>

        <div class="tf-table-wrap tf-user-table-wrap">
            <table class="tf-table tf-user-table">
                <thead>
                    <tr>
                        <th class="tf-user-table__check">
                            <input class="tf-check" type="checkbox" aria-label="Select all users" data-select-all>
                        </th>
                        <th>User</th>
                        <th>Employee ID</th>
                        <th>Company</th>
                        <th>Department</th>
                        <th>Designation</th>
                        <th>Tasks</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        @php
                            $initials = collect(explode(' ', trim($user->name)))
                                ->filter()
                                ->take(2)
                                ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
                                ->implode('');
                        @endphp
                        <tr>
                            <td class="tf-user-table__check">
                                <input class="tf-check" type="checkbox" value="{{ $user->id }}" aria-label="Select {{ $user->name }}" data-user-checkbox
                                       @disabled($user->id === auth()->id() || $user->hasRole('Admin'))
                                       @if ($user->id === auth()->id() || $user->hasRole('Admin')) title="This account is protected from deletion" @endif>
                            </td>
                            <td>
                                <a href="{{ route('users.show', $user) }}" class="tf-user-row">
                                    <span class="tf-user-row__avatar" aria-hidden="true">{{ $initials }}</span>
                                    <span class="tf-user-row__identity">
                                        <strong>{{ $user->name }}</strong>
                                    </span>
                                </a>
                            </td>
                            <td><span class="tf-user-row__employee-id">{{ $user->employee_id ?: '—' }}</span></td>
                            <td>
                                @if ($user->company)
                                    <span class="tf-user-row__company" title="{{ $user->company->name }}">
                                        {{ $user->company->name }}
                                    </span>
                                @else
                                    <span class="tf-management__secondary">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($user->department)
                                    <span class="tf-user-row__department">{{ $user->department->name }}</span>
                                @else
                                    <span class="tf-management__secondary">—</span>
                                @endif
                            </td>
                            <td>
                                <span class="tf-user-row__designation">{{ $user->designation ?: '—' }}</span>
                            </td>
                            <td>
                                <a href="{{ route('tasks.index', ['search' => $user->name]) }}" class="text-decoration-none">
                                    <span class="badge {{ $user->assigned_tasks_count > 0 ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-light text-secondary border' }} fw-medium px-2 py-1">
                                        <i class="bi bi-list-task me-1"></i>
                                        {{ number_format($user->assigned_tasks_count) }}
                                    </span>
                                </a>
                            </td>
                            <td class="tf-user-row__phone">
                                @if ($user->phone)
                                    <i class="bi bi-telephone" aria-hidden="true"></i>
                                    {{ $user->phone }}
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $user->email }}</td>
                            <td><span class="tf-management__status tf-management__status--{{ $user->status }}">{{ ucfirst($user->status) }}</span></td>
                            <td>
                                <div class="dropdown tf-user-row__actions">
                                    <button class="tf-icon-btn tf-icon-btn--sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions for {{ $user->name }}">
                                        <i class="bi bi-three-dots"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end tf-menu tf-user-action-menu">
                                        <li class="tf-user-action-menu__header">
                                            <strong>Account actions</strong>
                                            <small>{{ $user->employee_id }}</small>
                                        </li>
                                        <li><a class="dropdown-item" href="{{ route('users.show', $user) }}"><i class="bi bi-person-lines-fill"></i><span>View</span><i class="bi bi-arrow-up-right tf-user-action-menu__arrow"></i></a></li>
                                        @can('update', $user)
                                            <li>
                                                <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#addUserPanel" aria-controls="addUserPanel"
                                                        data-tf-edit-user
                                                        data-user-id="{{ $user->id }}"
                                                        data-user-name="{{ $user->name }}"
                                                        data-user-email="{{ $user->email }}"
                                                        data-user-phone="{{ $user->phone }}"
                                                        data-user-employee-id="{{ $user->employee_id }}"
                                                        data-user-company-id="{{ $user->company_id }}"
                                                        data-user-department-id="{{ $user->department_id }}"
                                                        data-user-designation="{{ $user->designation }}"
                                                        data-user-role-id="{{ $user->role_id }}"
                                                        data-user-status="{{ $user->status }}"
                                                        >
                                                    <i class="bi bi-pencil-square"></i><span>Edit</span><i class="bi bi-arrow-up-right tf-user-action-menu__arrow"></i>
                                                </button>
                                            </li>
                                        @endcan
                                        @can('changeStatus', $user)
                                            <li class="tf-user-action-menu__status">
                                                <form action="{{ route('users.toggle-status', $user) }}" method="POST"
                                                      data-tf-operation-form
                                                      data-operation-kind="status"
                                                      data-operation-user="{{ $user->name }}"
                                                      data-operation-from="{{ $user->status }}"
                                                      data-operation-to="{{ $user->status === 'active' ? 'inactive' : 'active' }}">
                                                    @csrf
                                                    <div class="tf-user-action-menu__status-copy">
                                                        <span>Account status</span>
                                                        <small>{{ ucfirst($user->status) }}</small>
                                                    </div>
                                                    <button type="submit" class="tf-user-status-toggle" role="switch"
                                                            aria-checked="{{ $user->status === 'active' ? 'true' : 'false' }}"
                                                            aria-label="{{ $user->status === 'active' ? 'Deactivate' : 'Activate' }} {{ $user->name }} account"
                                                            @disabled($user->id === auth()->id() || $user->hasRole('Admin'))
                                                            @if ($user->id === auth()->id() || $user->hasRole('Admin')) title="Protected accounts cannot change status" @endif>
                                                        <span></span>
                                                    </button>
                                                </form>
                                            </li>
                                        @endcan
                                        @can('delete', $user)
                                            <li class="tf-user-action-menu__divider"><hr class="dropdown-divider"></li>
                                            <li>
                                                <form action="{{ route('users.destroy', $user) }}" method="POST" onsubmit="return confirm('Delete this user?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="dropdown-item tf-user-action-menu__delete"><i class="bi bi-trash3"></i><span>Delete</span></button>
                                                </form>
                                            </li>
                                        @endcan
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11">
                                @include('components.empty-state', [
                                    'icon' => 'bi-people',
                                    'title' => 'No users found',
                                    'message' => 'Try adjusting your filters or create a user account.'
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <footer class="tf-management__table-footer">
            <span>Showing {{ $users->firstItem() ?? 0 }}–{{ $users->lastItem() ?? 0 }} of {{ $users->total() }} users</span>
            {{ $users->links('pagination::bootstrap-5') }}
        </footer>
    </section>

    @if (auth()->user()->can('users.create') || auth()->user()->can('users.update'))
        <div class="modal fade tf-user-modal" tabindex="-1" id="addUserPanel" aria-labelledby="addUserPanelLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form method="POST" action="{{ route('users.store') }}" class="tf-user-offcanvas__form" data-update-url-template="{{ route('users.update', ['user' => '__USER__']) }}">
                        @csrf
                        <input type="hidden" id="userFormMethod" value="PUT">
                        <input type="hidden" id="userFormMode" name="form_mode" value="{{ old('form_mode', $editingUser ? 'edit' : 'create') }}">
                        <input type="hidden" id="editingUserId" name="editing_user_id" value="{{ old('editing_user_id', $editingUser?->id) }}">
                        <div class="modal-header tf-user-offcanvas__header">
                            <div>
                                <span class="tf-user-offcanvas__eyebrow">USER MANAGEMENT</span>
                                <h2 class="modal-title" id="addUserPanelLabel">{{ old('form_mode', $editingUser ? 'edit' : 'create') === 'edit' ? 'Edit user' : 'Add new user' }}</h2>
                                <p id="userFormDescription">{{ old('form_mode', $editingUser ? 'edit' : 'create') === 'edit' ? 'Update employee details and account access.' : 'Create an employee account and set their access.' }}</p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>

                        <div class="modal-body tf-user-offcanvas__body">
                            <section class="tf-user-offcanvas__section">
                                <h3><i class="bi bi-person-vcard"></i> Personal information</h3>
                                <div class="tf-user-offcanvas__grid">
                                    <div class="tf-user-offcanvas__field">
                                        <label for="newUserName">Full name <span>*</span></label>
                                        <input id="newUserName" name="name" value="{{ old('name', $editingUser?->name) }}" class="form-control" required maxlength="255" placeholder="e.g. Alex Johnson">
                                        @error('name') <div class="tf-management__field-error">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="tf-user-offcanvas__field">
                                        <label for="newUserEmployeeId">Employee ID <span>*</span></label>
                                        <input id="newUserEmployeeId" name="employee_id" value="{{ old('employee_id', $editingUser?->employee_id) }}" class="form-control" required maxlength="50" placeholder="e.g. EMP-1042">
                                        @error('employee_id') <div class="tf-management__field-error">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="tf-user-offcanvas__field">
                                        <label for="newUserPhone">Phone number <span>*</span></label>
                                        <input id="newUserPhone" name="phone" value="{{ old('phone', $editingUser?->phone) }}" class="form-control" required placeholder="+1 (555) 000-0000">
                                        @error('phone') <div class="tf-management__field-error">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="tf-user-offcanvas__field">
                                        <label for="newUserEmail">Email <span>*</span></label>
                                        <input id="newUserEmail" type="email" name="email" value="{{ old('email', $editingUser?->email) }}" class="form-control" required placeholder="alex@company.com">
                                        @error('email') <div class="tf-management__field-error">{{ $message }}</div> @enderror
                                    </div>
                                </div>
                            </section>

                            <section class="tf-user-offcanvas__section">
                                <h3><i class="bi bi-briefcase"></i> Work information</h3>
                                <div class="tf-user-offcanvas__grid">
                                    <div class="tf-user-offcanvas__field">
                                        <label for="newUserCompany">Company <span>*</span></label>
                                        <select id="newUserCompany" name="company_id" class="form-select" required>
                                            <option value="">Select company</option>
                                            @foreach ($companies as $company)
                                                <option value="{{ $company->id }}" @selected(old('company_id', $editingUser?->company_id) == $company->id)>{{ $company->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('company_id') <div class="tf-management__field-error">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="tf-user-offcanvas__field" id="newUserDepartmentField" @if (! old('company_id', $editingUser?->company_id)) hidden @endif>
                                        <label for="newUserDepartment">Department</label>
                                        <select id="newUserDepartment" name="department_id" class="form-select" data-department-url="{{ route('departments.by-company', ['company' => '__COMPANY__']) }}" @required((bool) old('company_id', $editingUser?->company_id))>
                                            <option value="">Select department</option>
                                            @foreach ($departments as $department)
                                                <option value="{{ $department->id }}" data-company-id="{{ $department->company_id }}" data-designation="{{ $department->designation }}" data-designations="{{ $department->designations->map(fn ($designation): array => ['name' => $designation->name, 'roles' => $designation->roles->map(fn ($role): array => ['id' => $role->id, 'name' => $role->name])->values()->all()])->values()->toJson() }}" @selected(old('department_id', $editingUser?->department_id) == $department->id)>{{ $department->name }}</option>
                                            @endforeach
                                        </select>
                                        <div class="tf-management__field-error" id="departmentLoadError" role="alert" hidden></div>
                                        @error('department_id') <div class="tf-management__field-error">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="tf-user-offcanvas__field" id="newUserDesignationField" @if (! old('department_id', $editingUser?->department_id)) hidden @endif>
                                        <label for="newUserDesignation">Designation</label>
                                        <select id="newUserDesignation" name="designation" class="form-select" required disabled>
                                            <option value="">Select designation</option>
                                        </select>
                                        @error('designation') <div class="tf-management__field-error">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="tf-user-offcanvas__field" id="newUserRoleField" hidden>
                                        <label for="newUserRole">Role <span>*</span></label>
                                        <select id="newUserRole" name="role_id" class="form-select" required disabled>
                                            <option value="">Select role</option>
                                        </select>
                                        <small id="newUserRoleHint" class="tf-management__secondary" hidden>No roles are linked to this designation.</small>
                                        @error('role_id') <div class="tf-management__field-error">{{ $message }}</div> @enderror
                                    </div>
                                </div>
                            </section>

                    <section class="tf-user-offcanvas__section">
                        <h3><i class="bi bi-shield-lock"></i> Access and security</h3>
                        <div class="tf-user-offcanvas__grid">
                            <div class="tf-user-offcanvas__field">
                                <label for="newUserPassword" id="newUserPasswordLabel">Password <span>*</span></label>
                                <input id="newUserPassword" type="password" name="password" class="form-control" @required(old('form_mode', $editingUser ? 'edit' : 'create') !== 'edit') autocomplete="new-password">
                                <small id="newUserPasswordHint" class="tf-management__secondary">
                                    @if (old('form_mode', $editingUser ? 'edit' : 'create') === 'edit')
                                        For security, the current password is never shown. Leave blank to keep it unchanged.
                                    @else
                                        Set a password for this account.
                                    @endif
                                </small>
                                @error('password') <div class="tf-management__field-error">{{ $message }}</div> @enderror
                            </div>
                            <div class="tf-user-offcanvas__field">
                                <label for="newUserPasswordConfirmation" id="newUserPasswordConfirmationLabel">Confirm password <span>*</span></label>
                                <input id="newUserPasswordConfirmation" type="password" name="password_confirmation" class="form-control" @required(old('form_mode', $editingUser ? 'edit' : 'create') !== 'edit') autocomplete="new-password">
                            </div>
                        </div>
                        <div class="tf-user-offcanvas__status">
                            <div>
                                <strong>Account status</strong>
                                <small>Set whether this account is active.</small>
                            </div>
                            <input type="hidden" name="status" id="newUserStatus" value="{{ old('status', $editingUser?->status ?? 'active') }}">
                            <button type="button" class="tf-user-status-toggle" role="switch" aria-checked="{{ old('status', $editingUser?->status ?? 'active') === 'active' ? 'true' : 'false' }}" aria-label="Active account">
                                <span></span>
                            </button>
                        </div>
                        @error('status') <div class="tf-management__field-error">{{ $message }}</div> @enderror
                    </section>
                        </div>

                        <div class="modal-footer tf-user-offcanvas__footer">
                            <button type="button" class="tf-btn tf-btn--ghost" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="tf-btn tf-btn--primary">
                                <i class="bi bi-person-plus" id="userFormSubmitIcon"></i>
                                <span id="userFormSubmitLabel">Create user</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @push('scripts')
        <script>
            const userFilterForm = document.querySelector('[data-user-filter-form]');
            const userCompanyFilter = userFilterForm?.querySelector('[data-user-company-filter]');
            const userDepartmentFilter = userFilterForm?.querySelector('[name="department_id"]');
            const userStatusFilter = userFilterForm?.querySelector('[data-user-status-filter]');
            const userTabFilter = userFilterForm?.querySelector('[data-user-tab-filter]');

            const updateUserDepartmentOptions = (clearMismatch = false) => {
                if (!userCompanyFilter || !userDepartmentFilter) {
                    return;
                }

                const selectedDepartment = userDepartmentFilter.selectedOptions[0];
                if (clearMismatch && userCompanyFilter.value && selectedDepartment?.dataset.companyId
                    && selectedDepartment.dataset.companyId !== userCompanyFilter.value) {
                    userDepartmentFilter.value = '';
                }

                [...userDepartmentFilter.options].forEach((option) => {
                    option.hidden = Boolean(userCompanyFilter.value
                        && option.value
                        && option.dataset.companyId !== userCompanyFilter.value);
                });
            };

            updateUserDepartmentOptions();
            userCompanyFilter?.addEventListener('change', () => {
                updateUserDepartmentOptions(true);
                userFilterForm.requestSubmit();
            });
            userFilterForm?.querySelectorAll('select:not([data-user-company-filter]):not([data-user-status-filter])').forEach((select) => {
                select.addEventListener('change', () => userFilterForm.requestSubmit());
            });
            userStatusFilter?.addEventListener('change', () => {
                if (userTabFilter) {
                    userTabFilter.value = userStatusFilter.value || 'all';
                }
                userFilterForm.requestSubmit();
            });

            const selectAllUsers = document.querySelector('[data-select-all]');
            const userCheckboxes = [...document.querySelectorAll('[data-user-checkbox]')];
            const selectableUserCheckboxes = userCheckboxes.filter((checkbox) => !checkbox.disabled);
            const bulkDeleteForm = document.querySelector('[data-user-bulk-delete-form]');
            const bulkDeleteButton = document.querySelector('[data-user-bulk-delete-button]');
            const selectionCount = document.querySelector('[data-user-selection-count]');
            const selectionInputs = document.querySelector('[data-user-selection-inputs]');

            const syncUserSelection = () => {
                const selectedUsers = selectableUserCheckboxes.filter((checkbox) => checkbox.checked);
                const selectedCount = selectedUsers.length;

                if (bulkDeleteForm && bulkDeleteButton && selectionCount && selectionInputs) {
                    bulkDeleteForm.hidden = selectedCount === 0;
                    selectionCount.textContent = String(selectedCount);
                    selectionInputs.replaceChildren(...selectedUsers.map((checkbox) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'user_ids[]';
                        input.value = checkbox.value;

                        return input;
                    }));
                }

                if (selectAllUsers) {
                    selectAllUsers.checked = selectedCount > 0 && selectedCount === selectableUserCheckboxes.length;
                    selectAllUsers.indeterminate = selectedCount > 0 && selectedCount < selectableUserCheckboxes.length;
                }
            };

            selectAllUsers?.addEventListener('change', () => {
                selectableUserCheckboxes.forEach((checkbox) => {
                    checkbox.checked = selectAllUsers.checked;
                });

                syncUserSelection();
            });

            userCheckboxes.forEach((checkbox) => {
                checkbox.addEventListener('change', () => {
                    syncUserSelection();
                });
            });

            bulkDeleteForm?.addEventListener('submit', (event) => {
                const selectedCount = userCheckboxes.filter((checkbox) => checkbox.checked).length;

                if (selectedCount === 0 || !window.confirm(`Delete ${selectedCount} selected user account(s)? This action cannot be undone.`)) {
                    event.preventDefault();
                }
            });

            const newUserCompany = document.getElementById('newUserCompany');
            const newUserDepartment = document.getElementById('newUserDepartment');
            const departmentField = document.getElementById('newUserDepartmentField');
            const designationField = document.getElementById('newUserDesignationField');
            const newUserDesignation = document.getElementById('newUserDesignation');
            const roleField = document.getElementById('newUserRoleField');
            const newUserRole = document.getElementById('newUserRole');
            const roleHint = document.getElementById('newUserRoleHint');
            const userForm = document.querySelector('.tf-user-offcanvas__form');
            const userFormMode = document.getElementById('userFormMode');
            const editingUserId = document.getElementById('editingUserId');
            const userFormTitle = document.getElementById('addUserPanelLabel');
            const userFormDescription = document.getElementById('userFormDescription');
            const userFormMethod = document.getElementById('userFormMethod');
            const newUserPassword = document.getElementById('newUserPassword');
            const newUserPasswordConfirmation = document.getElementById('newUserPasswordConfirmation');
            let selectedDepartment = @json((string) old('department_id', $editingUser?->department_id));
            let selectedDesignation = @json((string) old('designation', $editingUser?->designation));
            let selectedRole = @json((string) old('role_id', $editingUser?->role_id));
            let loadDepartments = () => {};

            const updateDepartmentDesignation = () => {
                const selectedOption = newUserDepartment.selectedOptions[0];
                const designations = JSON.parse(selectedOption?.dataset.designations || '[]').map((designation) =>
                    typeof designation === 'string' ? { name: designation, roles: [] } : designation
                );
                const availableDesignations = designations.length > 0
                    ? designations
                    : (selectedOption?.dataset.designation ? [{ name: selectedOption.dataset.designation, roles: [] }] : []);

                newUserDesignation.replaceChildren(new Option('Select designation', ''));
                availableDesignations.forEach((designation) => {
                    if (designation.name) {
                        newUserDesignation.add(new Option(designation.name, designation.name));
                    }
                });

                newUserDesignation.disabled = !newUserDepartment.value || availableDesignations.length === 0;
                newUserDesignation.required = Boolean(newUserDepartment.value);
                newUserDesignation.value = availableDesignations.some((designation) =>
                    designation.name === selectedDesignation
                ) ? selectedDesignation : '';
                selectedDesignation = newUserDesignation.value;
                designationField.hidden = !newUserDepartment.value;

                const selectedDesignationData = availableDesignations.find(
                    (designation) => designation.name === newUserDesignation.value
                );
                const availableRoles = selectedDesignationData?.roles ?? [];
                newUserRole.replaceChildren(new Option('Select role', ''));
                availableRoles.forEach((role) => {
                    newUserRole.add(new Option(role.name, String(role.id)));
                });
                newUserRole.disabled = !newUserDesignation.value || availableRoles.length === 0;
                newUserRole.required = Boolean(newUserDesignation.value);
                newUserRole.value = availableRoles.some((role) => String(role.id) === selectedRole)
                    ? selectedRole
                    : '';
                selectedRole = newUserRole.value;
                roleField.hidden = !newUserDesignation.value;
                roleHint.hidden = !newUserDesignation.value || availableRoles.length > 0;
            };

            if (newUserCompany && newUserDepartment) {
                const departmentUrl = newUserDepartment.dataset.departmentUrl;
                const departmentLoadError = document.getElementById('departmentLoadError');
                let departmentRequestController;

                loadDepartments = async () => {
                    departmentRequestController?.abort();
                    departmentRequestController = new AbortController();
                    newUserDepartment.innerHTML = '<option value="">Select department</option>';
                    departmentLoadError.hidden = true;
                    newUserDesignation.replaceChildren(new Option('Select designation', ''));
                    newUserDesignation.disabled = true;
                    newUserRole.replaceChildren(new Option('Select role', ''));
                    newUserRole.disabled = true;
                    roleField.hidden = true;
                    roleHint.hidden = true;
                    designationField.hidden = true;
                    departmentField.hidden = !newUserCompany.value;
                    newUserDepartment.required = Boolean(newUserCompany.value);
                    newUserDepartment.disabled = !newUserCompany.value;

                    if (!newUserCompany.value) {
                        return;
                    }

                    try {
                        const response = await fetch(departmentUrl.replace('__COMPANY__', newUserCompany.value), {
                            headers: { 'Accept': 'application/json' },
                            signal: departmentRequestController.signal,
                        });

                        if (!response.ok) {
                            throw new Error('Unable to load departments for the selected company.');
                        }

                        const departments = await response.json();
                        departments.forEach((department) => {
                            const option = document.createElement('option');
                            option.value = department.id;
                            option.textContent = department.name;
                            option.dataset.designation = department.designation ?? '';
                            option.dataset.designations = JSON.stringify(
                                (department.designations ?? []).map((designation) => ({
                                    name: designation.name,
                                    roles: designation.roles ?? [],
                                }))
                            );
                            option.disabled = !department.designations?.length
                                || (department.status !== 'active' && String(department.id) !== selectedDepartment);
                            option.selected = String(department.id) === selectedDepartment;
                            newUserDepartment.append(option);
                        });

                        newUserDepartment.disabled = false;
                        updateDepartmentDesignation();
                    } catch (error) {
                        if (error.name === 'AbortError') {
                            return;
                        }

                        console.error(error);
                        departmentLoadError.textContent = 'Departments could not be loaded. Please refresh the page and try again.';
                        departmentLoadError.hidden = false;
                    }
                };

                newUserCompany.addEventListener('change', () => {
                    selectedDepartment = '';
                    selectedDesignation = '';
                    loadDepartments();
                });

                newUserDepartment.addEventListener('change', () => {
                    selectedDesignation = '';
                    selectedRole = '';
                    updateDepartmentDesignation();
                });
                newUserDesignation.addEventListener('change', () => {
                    selectedDesignation = newUserDesignation.value;
                    selectedRole = '';
                    updateDepartmentDesignation();
                });
                loadDepartments();
            }

            const newUserStatusToggle = document.querySelector('.tf-user-status-toggle');
            const newUserStatus = document.getElementById('newUserStatus');

            const setUserStatus = (status) => {
                if (!newUserStatus || !newUserStatusToggle) {
                    return;
                }

                newUserStatus.value = status;
                newUserStatusToggle.setAttribute('aria-checked', String(status === 'active'));
            };

            newUserStatusToggle?.addEventListener('click', () => {
                setUserStatus(newUserStatusToggle.getAttribute('aria-checked') === 'true' ? 'inactive' : 'active');
            });

            const setEditMode = (userId) => {
                userForm.action = userForm.dataset.updateUrlTemplate.replace('__USER__', userId);
                userFormMethod.name = '_method';
                userFormMethod.value = 'PUT';
                userFormMode.value = 'edit';
                editingUserId.value = userId;
                userFormTitle.textContent = 'Edit user';
                userFormDescription.textContent = 'Update employee details and account access.';
                newUserPassword.value = '';
                newUserPasswordConfirmation.value = '';
                newUserPassword.required = false;
                newUserPasswordConfirmation.required = false;
                document.getElementById('newUserPasswordLabel').textContent = 'New password (optional)';
                document.getElementById('newUserPasswordConfirmationLabel').textContent = 'Confirm new password';
                document.getElementById('newUserPasswordHint').textContent = 'For security, the current password is never shown. Leave blank to keep it unchanged.';
                document.getElementById('userFormSubmitIcon').className = 'bi bi-check-lg';
                document.getElementById('userFormSubmitLabel').textContent = 'Save changes';
            };

            const setCreateMode = () => {
                userForm.reset();
                userForm.action = '{{ route('users.store') }}';
                userFormMethod.removeAttribute('name');
                userFormMethod.value = 'POST';
                userFormMode.value = 'create';
                editingUserId.value = '';
                userFormTitle.textContent = 'Add new user';
                userFormDescription.textContent = 'Create an employee account and set their access.';
                document.getElementById('newUserName').value = '';
                document.getElementById('newUserEmail').value = '';
                document.getElementById('newUserPhone').value = '';
                document.getElementById('newUserEmployeeId').value = '';
                document.getElementById('newUserCompany').value = '';
                document.getElementById('newUserDepartment').innerHTML = '<option value="">Select department</option>';
                newUserDesignation.replaceChildren(new Option('Select designation', ''));
                newUserDesignation.disabled = true;
                newUserDesignation.required = false;
                newUserRole.replaceChildren(new Option('Select role', ''));
                newUserRole.disabled = true;
                newUserRole.required = false;
                roleField.hidden = true;
                roleHint.hidden = true;
                document.getElementById('departmentLoadError').hidden = true;
                departmentField.hidden = true;
                designationField.hidden = true;
                newUserDepartment.disabled = true;
                newUserDepartment.required = false;
                newUserPassword.value = '';
                newUserPasswordConfirmation.value = '';
                newUserPassword.required = true;
                newUserPasswordConfirmation.required = true;
                document.getElementById('newUserPasswordLabel').textContent = 'Password *';
                document.getElementById('newUserPasswordConfirmationLabel').textContent = 'Confirm password *';
                document.getElementById('newUserPasswordHint').textContent = 'Set a password for this account.';
                document.getElementById('userFormSubmitIcon').className = 'bi bi-person-plus';
                document.getElementById('userFormSubmitLabel').textContent = 'Create user';
                selectedDepartment = '';
                selectedDesignation = '';
                selectedRole = '';
                setUserStatus('active');
            };

            document.querySelector('[data-tf-add-user]')?.addEventListener('click', setCreateMode);

            document.querySelectorAll('[data-tf-edit-user]').forEach((button) => {
                button.addEventListener('click', () => {
                    const user = button.dataset;
                    setEditMode(user.userId);
                    document.getElementById('newUserName').value = user.userName;
                    document.getElementById('newUserEmail').value = user.userEmail;
                    document.getElementById('newUserPhone').value = user.userPhone;
                    document.getElementById('newUserEmployeeId').value = user.userEmployeeId;
                    document.getElementById('newUserCompany').value = user.userCompanyId;
                    selectedDepartment = user.userDepartmentId;
                    selectedDesignation = user.userDesignation;
                    selectedRole = user.userRoleId;
                    loadDepartments();
                    setUserStatus(user.userStatus);
                });
            });

            const editUserFromQuery = @json($editingUserData);

            if (userFormMode?.value === 'edit') {
                setEditMode(editingUserId.value);
            }

            if (editUserFromQuery) {
                setEditMode(editUserFromQuery.id);
                document.getElementById('newUserName').value = editUserFromQuery.name;
                document.getElementById('newUserEmail').value = editUserFromQuery.email;
                document.getElementById('newUserPhone').value = editUserFromQuery.phone;
                document.getElementById('newUserEmployeeId').value = editUserFromQuery.employee_id;
                document.getElementById('newUserCompany').value = editUserFromQuery.company_id;
                selectedDepartment = String(editUserFromQuery.department_id ?? '');
                loadDepartments();
                setUserStatus(editUserFromQuery.status);
            }

            @if ($errors->hasAny(['name', 'employee_id', 'phone', 'email', 'company_id', 'department_id', 'password', 'status']) || (auth()->user()->can('users.create') && request()->boolean('create')) || request()->filled('edit') || old('form_mode') === 'edit')
                const addUserPanel = document.getElementById('addUserPanel');
                if (addUserPanel) {
                    bootstrap.Modal.getOrCreateInstance(addUserPanel).show();
                }
            @endif

        </script>
    @endpush
@endsection
