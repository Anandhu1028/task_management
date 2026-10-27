@extends('layouts.app')

@section('title', 'Company Management')

@section('content')
    <header class="tf-page-head">
        <div>
            <h1>Company Management</h1>
            <p>Manage registered companies and their operational status.</p>
        </div>
        <div class="tf-page-head__actions tf-user-page-actions">
            @can('companies.delete')
                <form method="POST" action="{{ route('companies.bulk-destroy') }}" class="tf-user-bulk-delete-form" data-organization-selection-form data-selection-type="company" hidden>
                    @csrf
                    <span data-organization-selection-inputs></span>
                    <button type="submit" class="tf-btn tf-user-action-btn tf-user-action-btn--bulk-delete" data-organization-bulk-delete>
                        <i class="bi bi-trash3"></i>
                        Delete selected
                        <span class="tf-user-bulk-delete-count" data-organization-selection-count>0</span>
                    </button>
                </form>
            @endcan
            @can('companies.view')
                <a href="{{ route('companies.export', request()->except(['page', 'per_page'])) }}" class="tf-btn tf-btn--ghost tf-user-action-btn tf-user-action-btn--export">
                    <i class="bi bi-download"></i>
                    Export
                </a>
            @endcan
            @can('companies.create')
                <button type="button" class="tf-btn tf-btn--primary tf-user-action-btn tf-user-action-btn--add" data-bs-toggle="modal" data-bs-target="#companyFormModal">
                    <i class="bi bi-building-add"></i>
                    Add Company
                </button>
            @endcan
        </div>
    </header>

    @if (session('error'))
        <div class="tf-management__notice tf-management__notice--error" role="alert">{{ session('error') }}</div>
    @endif

    <section class="tf-user-summary" aria-label="Company summary">
        <article class="tf-user-summary__card tf-user-summary__card--total">
            <span class="tf-user-summary__icon"><i class="bi bi-buildings"></i></span>
            <div class="tf-user-summary__content">
                <span class="tf-user-summary__label">Total companies</span>
                <strong>{{ number_format($counts->all_count) }}</strong>
                <small>Organization directory</small>
            </div>
            <span class="tf-user-summary__trend"><i class="bi bi-building-check"></i></span>
        </article>
        <article class="tf-user-summary__card tf-user-summary__card--active">
            <span class="tf-user-summary__icon"><i class="bi bi-building-check"></i></span>
            <div class="tf-user-summary__content">
                <span class="tf-user-summary__label">Active companies</span>
                <strong>{{ number_format($counts->active_count ?? 0) }}</strong>
                <small>Currently enabled</small>
            </div>
            <span class="tf-user-summary__trend"><i class="bi bi-check2"></i></span>
        </article>
        <article class="tf-user-summary__card tf-user-summary__card--inactive">
            <span class="tf-user-summary__icon"><i class="bi bi-building-x"></i></span>
            <div class="tf-user-summary__content">
                <span class="tf-user-summary__label">Inactive companies</span>
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
                <small>Share of all companies</small>
            </div>
            <span class="tf-user-summary__trend"><i class="bi bi-graph-up-arrow"></i></span>
        </article>
    </section>

    <section class="tf-card tf-user-management">
        <nav class="tf-user-tabs" aria-label="Filter companies by status">
            @foreach ([
                'all' => ['label' => 'All companies', 'count' => $counts->all_count],
                'active' => ['label' => 'Active', 'count' => $counts->active_count],
                'inactive' => ['label' => 'Inactive', 'count' => $counts->inactive_count],
            ] as $key => $item)
                <a href="{{ route('companies.index', array_merge(request()->except(['status', 'page']), $key === 'all' ? [] : ['status' => $key])) }}"
                   class="tf-user-tabs__item tf-user-tabs__item--{{ $key }} {{ (request('status', 'all') === $key || ($key === 'all' && ! request('status'))) ? 'is-active' : '' }}"
                   @if (request('status', 'all') === $key || ($key === 'all' && ! request('status'))) aria-current="page" @endif>
                    {{ $item['label'] }}
                    <span class="tf-user-tabs__count">{{ $item['count'] ?? 0 }}</span>
                </a>
            @endforeach
        </nav>

        <div class="tf-user-toolbar">
            <form method="GET" action="{{ route('companies.index') }}" class="tf-user-toolbar__search-form tf-company-toolbar__search-form" data-company-filter-form>
                <input type="hidden" name="per_page" value="{{ request('per_page') }}">
                <input type="hidden" name="view" value="{{ request('view', 'list') }}">
                <label class="tf-user-search" for="companySearch">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input id="companySearch" type="search" name="search" value="{{ request('search') }}" placeholder="Search companies or descriptions" autocomplete="off">
                </label>
                <div class="tf-company-toolbar__filters">
                    <label class="tf-user-toolbar__select" for="companyNameFilter">
                        <select id="companyNameFilter" name="company_id" aria-label="Filter by company">
                            <option value="">All companies</option>
                            @foreach ($companyOptions as $companyOption)
                                <option value="{{ $companyOption->id }}" @selected(request('company_id') == $companyOption->id)>{{ $companyOption->name }}</option>
                            @endforeach
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                    <label class="tf-user-toolbar__select" for="companyDepartmentsFilter">
                        <select id="companyDepartmentsFilter" name="departments" aria-label="Filter by departments">
                            <option value="">All departments</option>
                            <option value="with" @selected(request('departments') === 'with')>Has departments</option>
                            <option value="without" @selected(request('departments') === 'without')>No departments</option>
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                    <label class="tf-user-toolbar__select" for="companyUsersFilter">
                        <select id="companyUsersFilter" name="users" aria-label="Filter by users">
                            <option value="">All users</option>
                            <option value="with" @selected(request('users') === 'with')>Has users</option>
                            <option value="without" @selected(request('users') === 'without')>No users</option>
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                    <label class="tf-user-toolbar__select" for="companyTasksFilter">
                        <select id="companyTasksFilter" name="tasks" aria-label="Filter by tasks">
                            <option value="">All tasks</option>
                            <option value="with" @selected(request('tasks') === 'with')>Has tasks</option>
                            <option value="without" @selected(request('tasks') === 'without')>No tasks</option>
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                    <label class="tf-user-toolbar__select" for="companyStatusFilter">
                        <select id="companyStatusFilter" name="status" aria-label="Filter by status">
                            <option value="">All statuses</option>
                            <option value="active" @selected(request('status') === 'active')>Active</option>
                            <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                </div>
            </form>
            <div class="tf-department-toolbar__actions">
                @if (request()->hasAny(['search', 'company_id', 'departments', 'users', 'tasks', 'status', 'per_page']))
                    <a href="{{ route('companies.index', ['view' => request('view', 'list')]) }}" class="tf-text-link tf-user-toolbar__reset">Reset</a>
                @endif
                <div class="tf-view-toggle" role="group" aria-label="Company view">
                    <a href="{{ route('companies.index', array_merge(request()->except('page'), ['view' => 'list'])) }}"
                       class="tf-view-toggle__btn {{ request('view', 'list') === 'list' ? 'is-active' : '' }}"
                       @if (request('view', 'list') === 'list') aria-current="page" @endif
                       aria-label="List view">
                        <i class="bi bi-list-ul" aria-hidden="true"></i>
                    </a>
                    <a href="{{ route('companies.index', array_merge(request()->except('page'), ['view' => 'kanban'])) }}"
                       class="tf-view-toggle__btn {{ request('view') === 'kanban' ? 'is-active' : '' }}"
                       @if (request('view') === 'kanban') aria-current="page" @endif
                       aria-label="Kanban view">
                        <i class="bi bi-kanban" aria-hidden="true"></i>
                    </a>
                </div>
            </div>
        </div>

        <div id="companyKanbanView" class="tf-company-kanban" @if (request('view') !== 'kanban') hidden @endif>
            @foreach ([
                'active' => ['label' => 'Active', 'icon' => 'bi-building-check'],
                'inactive' => ['label' => 'Inactive', 'icon' => 'bi-building-x'],
            ] as $statusKey => $column)
                @php
                    $columnCompanies = $kanbanCompanies->where('status', $statusKey);
                @endphp
                <section class="tf-company-kanban__column tf-company-kanban__column--{{ $statusKey }}" aria-label="{{ $column['label'] }} companies">
                    <header class="tf-company-kanban__header">
                        <span class="tf-company-kanban__title">
                            <i class="bi {{ $column['icon'] }}" aria-hidden="true"></i>
                            {{ $column['label'] }}
                        </span>
                        <span class="tf-company-kanban__count">{{ $columnCompanies->count() }}</span>
                    </header>
                    <div class="tf-company-kanban__cards">
                        @forelse ($columnCompanies as $company)
                            <article class="tf-company-kanban__card">
                                <header class="tf-company-kanban__card-header">
                                    @can('companies.delete')
                                        <input class="tf-check" type="checkbox" value="{{ $company->id }}" aria-label="Select {{ $company->name }}" data-organization-checkbox
                                               @disabled($company->has_any_users || $company->has_any_departments)
                                               @if ($company->has_any_users || $company->has_any_departments) title="Companies with users or departments cannot be deleted" @endif>
                                    @endcan
                                    <span class="tf-user-row__avatar" aria-hidden="true"><i class="bi bi-building"></i></span>
                                    <strong>{{ $company->name }}</strong>
                                    <div class="dropdown tf-user-row__actions">
                                        <button class="tf-icon-btn tf-icon-btn--sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions for {{ $company->name }}">
                                            <i class="bi bi-three-dots"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end tf-menu tf-user-action-menu">
                                            <li class="tf-user-action-menu__header">
                                                <strong>Company actions</strong>
                                                <small>{{ $company->name }}</small>
                                            </li>
                                            <li><a class="dropdown-item" href="{{ route('companies.show', $company) }}"><i class="bi bi-building"></i><span>View</span><i class="bi bi-arrow-up-right tf-user-action-menu__arrow"></i></a></li>
                                            @can('update', $company)
                                                <li><a class="dropdown-item" href="{{ route('companies.edit', $company) }}"><i class="bi bi-pencil-square"></i><span>Edit</span><i class="bi bi-arrow-up-right tf-user-action-menu__arrow"></i></a></li>
                                            @endcan
                                            @can('companies.change_status')
                                                <li class="tf-user-action-menu__status">
                                                    <form action="{{ route('companies.toggle-status', $company) }}" method="POST"
                                                          data-tf-operation-form
                                                          data-operation-kind="status"
                                                          data-operation-user="{{ $company->name }}"
                                                          data-operation-from="{{ $company->status }}"
                                                          data-operation-to="{{ $company->status === 'active' ? 'inactive' : 'active' }}">
                                                        @csrf
                                                        <div class="tf-user-action-menu__status-copy">
                                                            <span>Company status</span>
                                                            <small>{{ ucfirst($company->status) }}</small>
                                                        </div>
                                                        <button type="submit" class="tf-user-status-toggle" role="switch"
                                                                aria-checked="{{ $company->status === 'active' ? 'true' : 'false' }}"
                                                                aria-label="{{ $company->status === 'active' ? 'Deactivate' : 'Activate' }} {{ $company->name }}">
                                                            <span></span>
                                                        </button>
                                                    </form>
                                                </li>
                                            @endcan
                                            @can('delete', $company)
                                                <li class="tf-user-action-menu__divider"><hr class="dropdown-divider"></li>
                                                <li>
                                                    <form action="{{ route('companies.destroy', $company) }}" method="POST" onsubmit="return confirm('Delete this company?');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="dropdown-item tf-user-action-menu__delete" @disabled($company->has_any_users || $company->has_any_departments)><i class="bi bi-trash3"></i><span>Delete</span></button>
                                                    </form>
                                                </li>
                                            @endcan
                                        </ul>
                                    </div>
                                </header>
                                <p class="tf-company-kanban__description">{{ $company->description ?: 'No description provided.' }}</p>
                                <footer class="tf-company-kanban__stats">
                                    <span><i class="bi bi-diagram-3" aria-hidden="true"></i>{{ number_format($company->departments_count) }} departments</span>
                                    <span><i class="bi bi-people" aria-hidden="true"></i>{{ number_format($company->users_count) }} users</span>
                                    <a href="{{ route('tasks.index', ['company_id' => $company->id]) }}"><i class="bi bi-list-task" aria-hidden="true"></i>{{ number_format($company->tasks_count) }} tasks</a>
                                </footer>
                            </article>
                        @empty
                            <div class="tf-company-kanban__empty">No {{ strtolower($column['label']) }} companies.</div>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>

        <div id="companyListView" @if (request('view') === 'kanban') hidden @endif>
        <div class="tf-table-wrap tf-user-table-wrap">
            <table class="tf-table tf-user-table tf-organization-table">
                <thead>
                    <tr>
                        @can('companies.delete')
                            <th class="tf-user-table__check">
                                <input class="tf-check" type="checkbox" aria-label="Select all companies" data-organization-select-all>
                            </th>
                        @endcan
                        <th>Company</th>
                        <th>Departments</th>
                        <th>Users</th>
                        <th>Tasks</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($companies as $company)
                        <tr>
                            @can('companies.delete')
                                <td class="tf-user-table__check">
                                    <input class="tf-check" type="checkbox" value="{{ $company->id }}" aria-label="Select {{ $company->name }}" data-organization-checkbox
                                           @disabled($company->has_any_users || $company->has_any_departments)
                                           @if ($company->has_any_users || $company->has_any_departments) title="Companies with users or departments cannot be deleted" @endif>
                                </td>
                            @endcan
                            <td>
                                <a href="{{ route('companies.show', $company) }}" class="tf-user-row">
                                    <span class="tf-user-row__avatar" aria-hidden="true"><i class="bi bi-building"></i></span>
                                    <span class="tf-user-row__identity"><strong>{{ $company->name }}</strong></span>
                                </a>
                            </td>
                            <td><span class="tf-user-row__employee-id">{{ number_format($company->departments_count) }}</span></td>
                            <td><span class="tf-user-row__employee-id">{{ number_format($company->users_count) }}</span></td>
                            <td>
                                <a href="{{ route('tasks.index', ['company_id' => $company->id]) }}" class="text-decoration-none">
                                    <span class="badge {{ $company->tasks_count > 0 ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-light text-secondary border' }} fw-medium px-2 py-1">
                                        <i class="bi bi-list-task me-1"></i>
                                        {{ number_format($company->tasks_count) }}
                                    </span>
                                </a>
                            </td>
                            <td><span class="tf-user-row__designation">{{ $company->description ?: '—' }}</span></td>
                            <td><span class="tf-management__status tf-management__status--{{ $company->status }}">{{ ucfirst($company->status) }}</span></td>
                            <td>
                                <div class="dropdown tf-user-row__actions">
                                    <button class="tf-icon-btn tf-icon-btn--sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions for {{ $company->name }}">
                                        <i class="bi bi-three-dots"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end tf-menu tf-user-action-menu">
                                        <li class="tf-user-action-menu__header">
                                            <strong>Company actions</strong>
                                            <small>{{ $company->name }}</small>
                                        </li>
                                        <li><a class="dropdown-item" href="{{ route('companies.show', $company) }}"><i class="bi bi-building"></i><span>View</span><i class="bi bi-arrow-up-right tf-user-action-menu__arrow"></i></a></li>
                                        @can('update', $company)
                                            <li><a class="dropdown-item" href="{{ route('companies.edit', $company) }}"><i class="bi bi-pencil-square"></i><span>Edit</span><i class="bi bi-arrow-up-right tf-user-action-menu__arrow"></i></a></li>
                                        @endcan
                                        @can('companies.change_status')
                                            <li class="tf-user-action-menu__status">
                                                <form action="{{ route('companies.toggle-status', $company) }}" method="POST"
                                                      data-tf-operation-form
                                                      data-operation-kind="status"
                                                      data-operation-user="{{ $company->name }}"
                                                      data-operation-from="{{ $company->status }}"
                                                      data-operation-to="{{ $company->status === 'active' ? 'inactive' : 'active' }}">
                                                    @csrf
                                                    <div class="tf-user-action-menu__status-copy">
                                                        <span>Company status</span>
                                                        <small>{{ ucfirst($company->status) }}</small>
                                                    </div>
                                                    <button type="submit" class="tf-user-status-toggle" role="switch"
                                                            aria-checked="{{ $company->status === 'active' ? 'true' : 'false' }}"
                                                            aria-label="{{ $company->status === 'active' ? 'Deactivate' : 'Activate' }} {{ $company->name }}">
                                                        <span></span>
                                                    </button>
                                                </form>
                                            </li>
                                        @endcan
                                        @can('delete', $company)
                                            <li class="tf-user-action-menu__divider"><hr class="dropdown-divider"></li>
                                            <li>
                                                <form action="{{ route('companies.destroy', $company) }}" method="POST" onsubmit="return confirm('Delete this company?');">
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
                            <td colspan="{{ auth()->user()->can('companies.delete') ? 8 : 7 }}">
                                @include('components.empty-state', [
                                    'icon' => 'bi-building',
                                    'title' => 'No companies found',
                                    'message' => 'Try adjusting your filters or add a company.'
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <footer class="tf-management__table-footer">
            <span>Showing {{ $companies->firstItem() ?? 0 }}–{{ $companies->lastItem() ?? 0 }} of {{ $companies->total() }} companies</span>
            {{ $companies->links('pagination::bootstrap-5') }}
        </footer>
        </div>
    </section>

    @if (auth()->user()->can('companies.create') || auth()->user()->can('companies.update'))
        <div class="modal fade tf-user-modal" tabindex="-1" id="companyFormModal" aria-labelledby="companyFormModalTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    @include('companies._form', [
                        'company' => $editingCompany,
                        'formAction' => $editingCompany ? route('companies.update', $editingCompany) : route('companies.store'),
                        'submitLabel' => $editingCompany ? 'Save changes' : 'Create company',
                    ])
                </div>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    @if ($editingCompany || request()->boolean('create') || $errors->hasAny(['name', 'description', 'status']))
        <script>
            const companyFormModal = document.getElementById('companyFormModal');
            if (companyFormModal) {
                bootstrap.Modal.getOrCreateInstance(companyFormModal).show();
            }
        </script>
    @endif
@endpush

@push('scripts')
    <script>
        (() => {
            const companyFilterForm = document.querySelector('[data-company-filter-form]');
            companyFilterForm?.querySelectorAll('select').forEach((select) => {
                select.addEventListener('change', () => companyFilterForm.requestSubmit());
            });

            const selectAll = document.querySelector('#companyListView:not([hidden]) [data-organization-select-all]');
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
                        input.name = 'company_ids[]';
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
                if (selectedCount === 0 || !window.confirm(`Delete ${selectedCount} selected compan${selectedCount === 1 ? 'y' : 'ies'}? Companies with users or departments cannot be deleted.`)) {
                    event.preventDefault();
                }
            });
            syncSelection();
        })();
    </script>
@endpush
