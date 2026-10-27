@extends('layouts.app')

@section('title', 'Department Management')

@section('content')
    <header class="tf-page-head">
        <div>
            <h1>Department Management</h1>
            <p>Manage each department, its designation, company, and status.</p>
        </div>
        <div class="tf-page-head__actions tf-user-page-actions">
            @can('departments.delete')
                <form method="POST" action="{{ route('departments.bulk-destroy') }}" class="tf-user-bulk-delete-form" data-organization-selection-form data-selection-type="department" hidden>
                    @csrf
                    <span data-organization-selection-inputs></span>
                    <button type="submit" class="tf-btn tf-user-action-btn tf-user-action-btn--bulk-delete" data-organization-bulk-delete>
                        <i class="bi bi-trash3"></i>
                        Delete selected
                        <span class="tf-user-bulk-delete-count" data-organization-selection-count>0</span>
                    </button>
                </form>
            @endcan
            @can('departments.view')
                <a href="{{ route('departments.export', request()->except(['page', 'per_page'])) }}" class="tf-btn tf-btn--ghost tf-user-action-btn tf-user-action-btn--export">
                    <i class="bi bi-download"></i>
                    Export
                </a>
            @endcan
            @can('departments.create')
                <button type="button" class="tf-btn tf-btn--primary tf-user-action-btn tf-user-action-btn--add" data-bs-toggle="modal" data-bs-target="#departmentFormModal">
                    <i class="bi bi-diagram-3"></i>
                    Add Department
                </button>
            @endcan
        </div>
    </header>

    @if (session('error'))
        <div class="tf-management__notice tf-management__notice--error" role="alert">{{ session('error') }}</div>
    @endif

    <section class="tf-user-summary" aria-label="Department summary">
        <article class="tf-user-summary__card tf-user-summary__card--total">
            <span class="tf-user-summary__icon"><i class="bi bi-diagram-3"></i></span>
            <div class="tf-user-summary__content">
                <span class="tf-user-summary__label">Total departments</span>
                <strong>{{ number_format($counts->all_count) }}</strong>
                <small>Across all companies</small>
            </div>
            <span class="tf-user-summary__trend"><i class="bi bi-diagram-2"></i></span>
        </article>
        <article class="tf-user-summary__card tf-user-summary__card--active">
            <span class="tf-user-summary__icon"><i class="bi bi-check2-circle"></i></span>
            <div class="tf-user-summary__content">
                <span class="tf-user-summary__label">Active departments</span>
                <strong>{{ number_format($counts->active_count ?? 0) }}</strong>
                <small>Currently enabled</small>
            </div>
            <span class="tf-user-summary__trend"><i class="bi bi-check2"></i></span>
        </article>
        <article class="tf-user-summary__card tf-user-summary__card--inactive">
            <span class="tf-user-summary__icon"><i class="bi bi-pause-circle"></i></span>
            <div class="tf-user-summary__content">
                <span class="tf-user-summary__label">Inactive departments</span>
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
                <small>Share of all departments</small>
            </div>
            <span class="tf-user-summary__trend"><i class="bi bi-graph-up-arrow"></i></span>
        </article>
    </section>

    <section class="tf-card tf-user-management">
        <nav class="tf-user-tabs" aria-label="Filter departments by status">
            @foreach ([
                'all' => ['label' => 'All departments', 'count' => $counts->all_count],
                'active' => ['label' => 'Active', 'count' => $counts->active_count],
                'inactive' => ['label' => 'Inactive', 'count' => $counts->inactive_count],
            ] as $key => $item)
                <a href="{{ route('departments.index', array_merge(request()->except(['status', 'page']), $key === 'all' ? [] : ['status' => $key])) }}"
                   class="tf-user-tabs__item tf-user-tabs__item--{{ $key }} {{ (request('status', 'all') === $key || ($key === 'all' && ! request('status'))) ? 'is-active' : '' }}"
                   @if (request('status', 'all') === $key || ($key === 'all' && ! request('status'))) aria-current="page" @endif>
                    {{ $item['label'] }}
                    <span class="tf-user-tabs__count">{{ $item['count'] ?? 0 }}</span>
                </a>
            @endforeach
        </nav>

        <div class="tf-user-toolbar">
            <form method="GET" action="{{ route('departments.index') }}" class="tf-user-toolbar__search-form tf-department-toolbar__search-form" data-department-filter-form>
                <input type="hidden" name="status" value="{{ request('status') }}">
                <input type="hidden" name="per_page" value="{{ request('per_page') }}">
                <input type="hidden" name="view" value="{{ request('view', 'list') }}">
                <label class="tf-user-search" for="departmentSearch">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input id="departmentSearch" type="search" name="search" value="{{ request('search') }}" placeholder="Search departments, company, designations, or descriptions" autocomplete="off">
                </label>
                <div class="tf-department-toolbar__filters">
                    <label class="tf-user-toolbar__select" for="departmentNameFilter">
                        <select id="departmentNameFilter" name="department_id" aria-label="Filter by department">
                            <option value="">All departments</option>
                            @foreach ($departmentOptions as $departmentOption)
                                <option value="{{ $departmentOption->id }}" data-company-id="{{ $departmentOption->company_id }}" @selected(request('department_id') == $departmentOption->id)>{{ $departmentOption->name }}</option>
                            @endforeach
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                    <label class="tf-user-toolbar__select" for="departmentCompanyFilter">
                        <select id="departmentCompanyFilter" name="company_id" aria-label="Filter by company">
                            <option value="">All companies</option>
                            @foreach ($companies as $company)
                                <option value="{{ $company->id }}" @selected(request('company_id') == $company->id)>{{ $company->name }}</option>
                            @endforeach
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                    <label class="tf-user-toolbar__select" for="departmentDesignationFilter">
                        <select id="departmentDesignationFilter" name="designation" aria-label="Filter by designation">
                            <option value="">All designations</option>
                            @foreach ($designationFilters as $designation)
                                <option value="{{ $designation }}" @selected(request('designation') === $designation)>{{ $designation }}</option>
                            @endforeach
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                    <label class="tf-user-toolbar__select" for="departmentUsersFilter">
                        <select id="departmentUsersFilter" name="users" aria-label="Filter by assigned users">
                            <option value="">All users</option>
                            <option value="with" @selected(request('users') === 'with')>Has users</option>
                            <option value="without" @selected(request('users') === 'without')>No users</option>
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                    <label class="tf-user-toolbar__select" for="departmentTasksFilter">
                        <select id="departmentTasksFilter" name="tasks" aria-label="Filter by tasks">
                            <option value="">All tasks</option>
                            <option value="with" @selected(request('tasks') === 'with')>Has tasks</option>
                            <option value="without" @selected(request('tasks') === 'without')>No tasks</option>
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                </div>
            </form>
            <div class="tf-department-toolbar__actions">
                @if (request()->hasAny(['search', 'department_id', 'company_id', 'designation', 'users', 'tasks', 'status', 'per_page']))
                    <a href="{{ route('departments.index', ['view' => request('view', 'list')]) }}" class="tf-text-link tf-user-toolbar__reset">Reset</a>
                @endif
                <div class="tf-view-toggle" role="group" aria-label="Department view">
                    <a href="{{ route('departments.index', array_merge(request()->except('page'), ['view' => 'list'])) }}"
                       class="tf-view-toggle__btn {{ request('view', 'list') === 'list' ? 'is-active' : '' }}"
                       @if (request('view', 'list') === 'list') aria-current="page" @endif
                       aria-label="List view">
                        <i class="bi bi-list-ul" aria-hidden="true"></i>
                    </a>
                    <a href="{{ route('departments.index', array_merge(request()->except('page'), ['view' => 'kanban'])) }}"
                       class="tf-view-toggle__btn {{ request('view') === 'kanban' ? 'is-active' : '' }}"
                       @if (request('view') === 'kanban') aria-current="page" @endif
                       aria-label="Kanban view">
                        <i class="bi bi-kanban" aria-hidden="true"></i>
                    </a>
                </div>
            </div>
        </div>

        <div id="departmentKanbanView" class="tf-department-kanban" @if (request('view') !== 'kanban') hidden @endif>
            @foreach ([
                'active' => ['label' => 'Active', 'icon' => 'bi-check-circle'],
                'inactive' => ['label' => 'Inactive', 'icon' => 'bi-pause-circle'],
            ] as $statusKey => $column)
                @php
                    $columnDepartments = $kanbanDepartments->where('status', $statusKey);
                @endphp
                <section class="tf-department-kanban__column tf-department-kanban__column--{{ $statusKey }}" aria-label="{{ $column['label'] }} departments">
                    <header class="tf-department-kanban__header">
                        <span class="tf-department-kanban__title">
                            <i class="bi {{ $column['icon'] }}" aria-hidden="true"></i>
                            {{ $column['label'] }}
                        </span>
                        <span class="tf-department-kanban__count">{{ $columnDepartments->count() }}</span>
                    </header>
                    <div class="tf-department-kanban__cards">
                        @forelse ($columnDepartments as $department)
                            <article class="tf-department-kanban__card">
                                <header class="tf-department-kanban__card-header">
                                    @can('departments.delete')
                                        <input class="tf-check" type="checkbox" value="{{ $department->id }}" aria-label="Select {{ $department->name }}" data-organization-checkbox
                                               @disabled($department->has_any_users)
                                               @if ($department->has_any_users) title="Departments with assigned users cannot be deleted" @endif>
                                    @endcan
                                    <span class="tf-user-row__avatar tf-user-row__avatar--department" aria-hidden="true"><i class="bi bi-diagram-3"></i></span>
                                    <strong>{{ $department->name }}</strong>
                                    <div class="dropdown tf-user-row__actions">
                                        <button class="tf-icon-btn tf-icon-btn--sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions for {{ $department->name }}">
                                            <i class="bi bi-three-dots"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end tf-menu tf-user-action-menu">
                                            <li class="tf-user-action-menu__header">
                                                <strong>Department actions</strong>
                                                <small>{{ $department->company?->name ?? 'No company' }}</small>
                                            </li>
                                            @can('departments.update')
                                                <li><a class="dropdown-item" href="{{ route('departments.edit', $department) }}"><i class="bi bi-pencil-square"></i><span>Edit</span><i class="bi bi-arrow-up-right tf-user-action-menu__arrow"></i></a></li>
                                            @endcan
                                            @can('departments.change_status')
                                                <li class="tf-user-action-menu__status">
                                                    <form action="{{ route('departments.toggle-status', $department) }}" method="POST"
                                                          data-tf-operation-form
                                                          data-operation-kind="status"
                                                          data-operation-user="{{ $department->name }}"
                                                          data-operation-from="{{ $department->status }}"
                                                          data-operation-to="{{ $department->status === 'active' ? 'inactive' : 'active' }}">
                                                        @csrf
                                                        <div class="tf-user-action-menu__status-copy">
                                                            <span>Department status</span>
                                                            <small>{{ ucfirst($department->status) }}</small>
                                                        </div>
                                                        <button type="submit" class="tf-user-status-toggle" role="switch"
                                                                aria-checked="{{ $department->status === 'active' ? 'true' : 'false' }}"
                                                                aria-label="{{ $department->status === 'active' ? 'Deactivate' : 'Activate' }} {{ $department->name }}">
                                                            <span></span>
                                                        </button>
                                                    </form>
                                                </li>
                                            @endcan
                                            @can('departments.delete')
                                                <li class="tf-user-action-menu__divider"><hr class="dropdown-divider"></li>
                                                <li>
                                                    <form action="{{ route('departments.destroy', $department) }}" method="POST" onsubmit="return confirm('Delete this department?');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="dropdown-item tf-user-action-menu__delete" @disabled($department->has_any_users)><i class="bi bi-trash3"></i><span>Delete</span></button>
                                                    </form>
                                                </li>
                                            @endcan
                                        </ul>
                                    </div>
                                </header>
                                <p class="tf-department-kanban__company"><i class="bi bi-building" aria-hidden="true"></i>{{ $department->company?->name ?? 'No company' }}</p>
                                <div class="tf-department-kanban__designations">
                                    @forelse ($department->designations as $designation)
                                        <span>{{ $designation->name }}</span>
                                    @empty
                                        <span>{{ $department->designation ?: 'No designations' }}</span>
                                    @endforelse
                                </div>
                                <p class="tf-department-kanban__description">{{ $department->description ?: 'No description provided.' }}</p>
                                <footer class="tf-department-kanban__stats">
                                    <span><i class="bi bi-people" aria-hidden="true"></i>{{ number_format($department->users_count) }} users</span>
                                    <a href="{{ route('tasks.index', ['department_id' => $department->id]) }}"><i class="bi bi-list-task" aria-hidden="true"></i>{{ number_format($department->tasks_count) }} tasks</a>
                                </footer>
                            </article>
                        @empty
                            <div class="tf-department-kanban__empty">No {{ strtolower($column['label']) }} departments.</div>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>

        <div id="departmentListView" @if (request('view') === 'kanban') hidden @endif>
        <div class="tf-table-wrap tf-user-table-wrap">
            <table class="tf-table tf-user-table tf-organization-table">
                <thead>
                    <tr>
                        @can('departments.delete')
                            <th class="tf-user-table__check">
                                <input class="tf-check" type="checkbox" aria-label="Select all departments" data-organization-select-all>
                            </th>
                        @endcan
                        <th>Department</th>
                        <th>Company</th>
                        <th>Designations</th>
                        <th>Users</th>
                        <th>Tasks</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($departments as $department)
                        <tr>
                            @can('departments.delete')
                                <td class="tf-user-table__check">
                                    <input class="tf-check" type="checkbox" value="{{ $department->id }}" aria-label="Select {{ $department->name }}" data-organization-checkbox
                                           @disabled($department->has_any_users)
                                           @if ($department->has_any_users) title="Departments with assigned users cannot be deleted" @endif>
                                </td>
                            @endcan
                            <td>
                                <span class="tf-user-row">
                                    <span class="tf-user-row__avatar tf-user-row__avatar--department" aria-hidden="true"><i class="bi bi-diagram-3"></i></span>
                                    <span class="tf-user-row__identity"><strong>{{ $department->name }}</strong></span>
                                </span>
                            </td>
                            <td>{{ $department->company?->name ?? '—' }}</td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    @if ($department->designations->isNotEmpty())
                                        @foreach ($department->designations as $desig)
                                            <span class="tf-department-designation-summary">
                                                <strong>{{ $desig->name }}</strong>
                                                @foreach ($desig->roles as $role)
                                                    <small>{{ $role->name }}</small>
                                                @endforeach
                                            </span>
                                        @endforeach
                                    @else
                                        <span class="tf-user-row__designation">{{ $department->designation ?: '—' }}</span>
                                    @endif
                                </div>
                            </td>
                            <td><span class="tf-user-row__employee-id">{{ number_format($department->users_count) }}</span></td>
                            <td>
                                <a href="{{ route('tasks.index', ['department_id' => $department->id]) }}" class="text-decoration-none">
                                    <span class="badge {{ $department->tasks_count > 0 ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-light text-secondary border' }} fw-medium px-2 py-1">
                                        <i class="bi bi-list-task me-1"></i>
                                        {{ number_format($department->tasks_count) }}
                                    </span>
                                </a>
                            </td>
                            <td><span class="tf-user-row__designation">{{ $department->description ?: '—' }}</span></td>
                            <td><span class="tf-management__status tf-management__status--{{ $department->status }}">{{ ucfirst($department->status) }}</span></td>
                            <td>
                                <div class="dropdown tf-user-row__actions">
                                    <button class="tf-icon-btn tf-icon-btn--sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions for {{ $department->name }}">
                                        <i class="bi bi-three-dots"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end tf-menu tf-user-action-menu">
                                        <li class="tf-user-action-menu__header">
                                            <strong>Department actions</strong>
                                            <small>{{ $department->company?->name ?? 'No company' }}</small>
                                        </li>
                                        @can('departments.update')
                                            <li><a class="dropdown-item" href="{{ route('departments.edit', $department) }}"><i class="bi bi-pencil-square"></i><span>Edit</span><i class="bi bi-arrow-up-right tf-user-action-menu__arrow"></i></a></li>
                                        @endcan
                                        @can('departments.change_status')
                                            <li class="tf-user-action-menu__status">
                                                <form action="{{ route('departments.toggle-status', $department) }}" method="POST"
                                                      data-tf-operation-form
                                                      data-operation-kind="status"
                                                      data-operation-user="{{ $department->name }}"
                                                      data-operation-from="{{ $department->status }}"
                                                      data-operation-to="{{ $department->status === 'active' ? 'inactive' : 'active' }}">
                                                    @csrf
                                                    <div class="tf-user-action-menu__status-copy">
                                                        <span>Department status</span>
                                                        <small>{{ ucfirst($department->status) }}</small>
                                                    </div>
                                                    <button type="submit" class="tf-user-status-toggle" role="switch"
                                                            aria-checked="{{ $department->status === 'active' ? 'true' : 'false' }}"
                                                            aria-label="{{ $department->status === 'active' ? 'Deactivate' : 'Activate' }} {{ $department->name }}">
                                                        <span></span>
                                                    </button>
                                                </form>
                                            </li>
                                        @endcan
                                        @can('departments.delete')
                                            <li class="tf-user-action-menu__divider"><hr class="dropdown-divider"></li>
                                            <li>
                                                <form action="{{ route('departments.destroy', $department) }}" method="POST" onsubmit="return confirm('Delete this department?');">
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
                            <td colspan="{{ auth()->user()->can('departments.delete') ? 9 : 8 }}">
                                @include('components.empty-state', [
                                    'icon' => 'bi-diagram-3',
                                    'title' => 'No departments found',
                                    'message' => 'Try adjusting your filters or add a department.'
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <footer class="tf-management__table-footer">
            <span>Showing {{ $departments->firstItem() ?? 0 }}–{{ $departments->lastItem() ?? 0 }} of {{ $departments->total() }} departments</span>
            {{ $departments->links('pagination::bootstrap-5') }}
        </footer>
        </div>
    </section>

    @if (auth()->user()->can('departments.create') || auth()->user()->can('departments.update'))
        <div class="modal fade tf-user-modal" tabindex="-1" id="departmentFormModal" aria-labelledby="departmentFormModalTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    @include('departments._form', [
                        'department' => $editingDepartment,
                        'companies' => $companies,
                        'allDesignations' => $allDesignations ?? [],
                        'formAction' => $editingDepartment ? route('departments.update', $editingDepartment) : route('departments.store'),
                        'submitLabel' => $editingDepartment ? 'Save changes' : 'Create department',
                    ])
                </div>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    @if ($editingDepartment || request()->boolean('create') || $errors->hasAny(['company_id', 'name', 'designation', 'designations', 'description', 'status']))
        <script>
            const departmentFormModal = document.getElementById('departmentFormModal');
            if (departmentFormModal) {
                bootstrap.Modal.getOrCreateInstance(departmentFormModal).show();
            }
        </script>
    @endif
@endpush

@push('scripts')
    <script>
        (() => {
            const departmentFilterForm = document.querySelector('[data-department-filter-form]');
            const departmentCompanyFilter = departmentFilterForm?.querySelector('[name="company_id"]');
            const departmentNameFilter = departmentFilterForm?.querySelector('[name="department_id"]');
            const departmentToolbarFilters = departmentFilterForm?.querySelectorAll('select');

            const updateDepartmentFilterOptions = (clearMismatch = false) => {
                if (!departmentCompanyFilter || !departmentNameFilter) {
                    return;
                }

                const selectedDepartment = departmentNameFilter.selectedOptions[0];
                if (clearMismatch && departmentCompanyFilter.value && selectedDepartment?.dataset.companyId
                    && selectedDepartment.dataset.companyId !== departmentCompanyFilter.value) {
                    departmentNameFilter.value = '';
                }

                [...departmentNameFilter.options].forEach((option) => {
                    option.hidden = Boolean(departmentCompanyFilter.value
                        && option.value
                        && option.dataset.companyId !== departmentCompanyFilter.value);
                });
            };

            updateDepartmentFilterOptions();
            departmentCompanyFilter?.addEventListener('change', () => updateDepartmentFilterOptions(true));
            departmentToolbarFilters?.forEach((select) => {
                select.addEventListener('change', () => departmentFilterForm.requestSubmit());
            });

            const selectAll = document.querySelector('#departmentListView:not([hidden]) [data-organization-select-all]');
            const checkboxes = [...document.querySelectorAll('[data-organization-checkbox]')];
            const selectable = checkboxes.filter((checkbox) => !checkbox.disabled && !checkbox.closest('[hidden]'));
            const form = document.querySelector('[data-organization-selection-form]');
            const count = document.querySelector('[data-organization-selection-count]');
            const inputs = document.querySelector('[data-organization-selection-inputs]');
            const syncSelection = () => {
                const selected = selectable.filter((checkbox) => checkbox.checked);

                if (form && count && inputs) {
                    form.hidden = selected.length === 0;
                    count.textContent = String(selected.length);
                    inputs.replaceChildren(...selected.map((checkbox) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'department_ids[]';
                        input.value = checkbox.value;

                        return input;
                    }));
                }

                if (selectAll) {
                    selectAll.checked = selected.length > 0 && selected.length === selectable.length;
                    selectAll.indeterminate = selected.length > 0 && selected.length < selectable.length;
                    selectAll.disabled = selectable.length === 0;
                }
            };

            selectAll?.addEventListener('change', () => {
                selectable.forEach((checkbox) => {
                    checkbox.checked = selectAll.checked;
                });
                syncSelection();
            });

            checkboxes.forEach((checkbox) => checkbox.addEventListener('change', syncSelection));
            form?.addEventListener('submit', (event) => {
                const selectedCount = selectable.filter((checkbox) => checkbox.checked).length;
                if (selectedCount === 0 || !window.confirm(`Delete ${selectedCount} selected department(s)? Departments with assigned users cannot be deleted.`)) {
                    event.preventDefault();
                }
            });
            syncSelection();
        })();

        // Department designations and their role tags
        (() => {
            const input = document.getElementById('newDeptDesignationInput');
            const addBtn = document.getElementById('addDeptDesignationBtn');
            const clearBtn = document.getElementById('clearAllDeptDesignations');
            const container = document.getElementById('deptDesignationRows');
            const hint = document.getElementById('noDeptDesigHint');

            if (!container || !input) return;
            const availableRoles = JSON.parse(container.dataset.roles || '[]');

            const updateHint = () => {
                hint.hidden = container.querySelectorAll('.tf-department-designation').length > 0;
            };

            const addRole = (row, roleId) => {
                const role = availableRoles.find(item => String(item.id) === String(roleId));
                if (!role) return false;

                const roles = row.querySelector('.tf-department-designation__roles');
                const duplicate = [...roles.querySelectorAll('.tf-department-role')]
                    .some(selectedRole => selectedRole.dataset.roleId === String(role.id));
                if (duplicate) {
                    row.querySelector('.dept-role-select').classList.add('is-invalid');
                    window.setTimeout(() => row.querySelector('.dept-role-select')?.classList.remove('is-invalid'), 2000);
                    return false;
                }

                const rowIndex = row.dataset.index;
                const roleChip = document.createElement('span');
                roleChip.className = 'tf-department-role';
                roleChip.dataset.roleId = String(role.id);
                roleChip.append(document.createTextNode(role.name));

                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = `designations[${rowIndex}][roles][]`;
                hidden.value = String(role.id);

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'remove-dept-role-btn';
                remove.setAttribute('aria-label', `Remove ${role.name} role`);
                remove.textContent = '×';
                roleChip.append(hidden, remove);
                roles.insertBefore(roleChip, roles.querySelector('.tf-department-designation__add-role'));
                row.querySelector('.dept-role-select').value = '';
                row.querySelector('.tf-department-designation__role-editor').hidden = true;
                return true;
            };

            const createDesignationRow = (name, roles = []) => {
                const clean = name.trim();
                if (!clean) return false;

                const existing = [...container.querySelectorAll('.tf-department-designation')].some(
                    row => row.querySelector('input[name$="[name]"]').value.toLocaleLowerCase() === clean.toLocaleLowerCase()
                );
                if (existing) {
                    input.classList.add('is-invalid');
                    window.setTimeout(() => input.classList.remove('is-invalid'), 2000);
                    return false;
                }

                const rowIndex = Number(container.dataset.nextIndex);
                container.dataset.nextIndex = String(rowIndex + 1);

                const row = document.createElement('article');
                row.className = 'tf-department-designation';
                row.dataset.index = String(rowIndex);

                const nameInput = document.createElement('input');
                nameInput.type = 'hidden';
                nameInput.name = `designations[${rowIndex}][name]`;
                nameInput.value = clean;

                const main = document.createElement('div');
                main.className = 'tf-department-designation__main';
                const title = document.createElement('strong');
                title.textContent = clean;

                const rolesContainer = document.createElement('div');
                rolesContainer.className = 'tf-department-designation__roles';
                const addRoleButton = document.createElement('button');
                addRoleButton.type = 'button';
                addRoleButton.className = 'tf-department-designation__add-role';
                addRoleButton.textContent = '+ Role';
                rolesContainer.append(addRoleButton);

                const removeDesignation = document.createElement('button');
                removeDesignation.type = 'button';
                removeDesignation.className = 'remove-dept-desig-btn';
                removeDesignation.setAttribute('aria-label', `Remove ${clean}`);
                removeDesignation.textContent = '×';

                const editor = document.createElement('div');
                editor.className = 'tf-department-designation__role-editor';
                editor.hidden = true;
                const roleSelect = document.createElement('select');
                roleSelect.className = 'form-select dept-role-select';
                roleSelect.setAttribute('aria-label', 'Select role');
                roleSelect.add(new Option('Select role from roles list', ''));
                availableRoles.forEach(role => roleSelect.add(new Option(role.name, String(role.id))));
                const saveRole = document.createElement('button');
                saveRole.type = 'button';
                saveRole.className = 'tf-btn tf-btn--primary add-dept-role-btn';
                saveRole.textContent = 'Add role';
                const cancelRole = document.createElement('button');
                cancelRole.type = 'button';
                cancelRole.className = 'tf-department-designation__cancel-role';
                cancelRole.textContent = 'Cancel';
                editor.append(roleSelect, saveRole, cancelRole);

                main.append(title, rolesContainer, removeDesignation);
                row.append(nameInput, main, editor);
                container.insertBefore(row, hint);
                roles.forEach(role => addRole(row, role));
                input.value = '';
                updateHint();
                return true;
            };

            addBtn?.addEventListener('click', (e) => {
                e.preventDefault();
                createDesignationRow(input.value);
            });

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    createDesignationRow(input.value);
                }
            });

            container.addEventListener('click', (e) => {
                const removeRole = e.target.closest('.remove-dept-role-btn');
                if (removeRole) {
                    e.preventDefault();
                    removeRole.closest('.tf-department-role')?.remove();
                    updateHint();
                    return;
                }

                const showRoleEditor = e.target.closest('.tf-department-designation__add-role');
                if (showRoleEditor) {
                    const row = showRoleEditor.closest('.tf-department-designation');
                    const editor = row.querySelector('.tf-department-designation__role-editor');
                    editor.hidden = false;
                    editor.querySelector('.dept-role-select').focus();
                    return;
                }

                const saveRole = e.target.closest('.add-dept-role-btn');
                if (saveRole) {
                    const row = saveRole.closest('.tf-department-designation');
                    addRole(row, row.querySelector('.dept-role-select').value);
                    return;
                }

                const cancelRole = e.target.closest('.tf-department-designation__cancel-role');
                if (cancelRole) {
                    const row = cancelRole.closest('.tf-department-designation');
                    const editor = cancelRole.closest('.tf-department-designation__role-editor');
                    editor.querySelector('.dept-role-select').value = '';
                    editor.hidden = true;
                    return;
                }

                const removeDesignation = e.target.closest('.remove-dept-desig-btn');
                if (removeDesignation) {
                    e.preventDefault();
                    removeDesignation.closest('.tf-department-designation')?.remove();
                    updateHint();
                }
            });

            clearBtn?.addEventListener('click', (e) => {
                e.preventDefault();
                container.querySelectorAll('.tf-department-designation').forEach((row) => row.remove());
                updateHint();
            });

            updateHint();
        })();
    </script>
@endpush
