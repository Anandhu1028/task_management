<form method="POST" action="{{ $formAction }}" class="tf-management__form tf-user-offcanvas__form">
    @csrf
    @if ($department)
        @method('PUT')
    @endif
    <div class="modal-header tf-user-offcanvas__header">
        <div>
            <h2 class="modal-title" id="departmentFormModalTitle">
                {{ $department ? 'Edit department' : 'Add department' }}</h2>
            <p>{{ $department ? 'Update the department details.' : 'Create a department and assign its company and designation.' }}
            </p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body tf-user-offcanvas__body">
        <section class="tf-user-offcanvas__section">
            <h3><i class="bi bi-diagram-3"></i> Department information</h3>
            <div class="tf-user-offcanvas__grid">
                <div class="tf-user-offcanvas__field">
                    <label for="departmentCompanyId">Company <span>*</span></label>
                    <select id="departmentCompanyId" name="company_id" class="form-select" required>
                        <option value="">Select company</option>
                        @foreach ($companies as $company)
                            <option value="{{ $company->id }}" @selected(old('company_id', $department?->company_id) == $company->id)>{{ $company->name }}</option>
                        @endforeach
                    </select>
                    @error('company_id')
                    <div class="tf-management__field-error">{{ $message }}</div> @enderror
                </div>
                <div class="tf-user-offcanvas__field">
                    <label for="departmentName">Department name <span>*</span></label>
                    <input id="departmentName" name="name" value="{{ old('name', $department?->name) }}"
                        class="form-control" required maxlength="255" autofocus>
                    @error('name')
                    <div class="tf-management__field-error">{{ $message }}</div> @enderror
                </div>
                <div class="tf-user-offcanvas__field tf-user-offcanvas__field--full">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label for="newDeptDesignationInput" class="mb-0">Department Designations <span
                                class="tf-required-mark">*</span></label>
                        <button type="button" class="btn btn-link btn-sm text-decoration-none p-0 text-muted"
                            id="clearAllDeptDesignations" style="font-size: 0.78rem;">Clear all</button>
                    </div>

                    <div class="input-group mb-2">
                        <input type="text" id="newDeptDesignationInput" class="form-control"
                            list="deptDesignationDatalist" placeholder="Add a designation" maxlength="255">
                        <datalist id="deptDesignationDatalist">
                            @foreach ($allDesignations ?? [] as $suggestion)
                                <option value="{{ $suggestion }}">
                            @endforeach
                        </datalist>
                        <button type="button" class="tf-btn tf-btn--primary" id="addDeptDesignationBtn">
                            Add
                        </button>
                    </div>

                    @php
                        $currentDesignations = old('designations');
                        if ($currentDesignations === null) {
                            $currentDesignations = $department
                                ? $department->designations->map(fn($designation): array => [
                                    'name' => $designation->name,
                                    'roles' => $designation->roles->pluck('id')->all(),
                                ])->all()
                                : [];
                            if (empty($currentDesignations) && !empty($department?->designation)) {
                                $currentDesignations = [['name' => $department->designation, 'roles' => []]];
                            }
                        }
                        $currentDesignations = array_values(array_map(
                            static fn($designation): array => is_array($designation)
                                ? [
                                    'name' => (string) ($designation['name'] ?? ''),
                                    'roles' => is_array($designation['roles'] ?? null) ? array_map('intval', $designation['roles']) : [],
                                ]
                                : ['name' => (string) $designation, 'roles' => []],
                            is_array($currentDesignations) ? $currentDesignations : [],
                        ));
                        $availableRoleOptions = $availableRoles
                            ->map(fn($role): array => ['id' => $role->id, 'name' => $role->name])
                            ->values()
                            ->all();
                    @endphp

                    <div id="deptDesignationRows" class="tf-department-designations"
                        data-next-index="{{ count($currentDesignations) }}" data-roles='@json($availableRoleOptions)'>
                        @foreach ($currentDesignations as $index => $designation)
                            <article class="tf-department-designation" data-index="{{ $index }}"
                                data-name="{{ mb_strtolower($designation['name']) }}">
                                <input type="hidden" name="designations[{{ $index }}][name]"
                                    value="{{ $designation['name'] }}">
                                <div class="tf-department-designation__main">
                                    <strong>{{ $designation['name'] }}</strong>
                                    <div class="tf-department-designation__roles">
                                        @foreach ($availableRoles->whereIn('id', $designation['roles']) as $role)
                                            <span class="tf-department-role" data-role-id="{{ $role->id }}">
                                                {{ $role->name }}
                                                <input type="hidden" name="designations[{{ $index }}][roles][]"
                                                    value="{{ $role->id }}">
                                                <button type="button" class="remove-dept-role-btn"
                                                    aria-label="Remove {{ $role->name }} role">×</button>
                                            </span>
                                        @endforeach
                                        <button type="button" class="tf-department-designation__add-role">+ Role</button>
                                    </div>
                                    <button type="button" class="remove-dept-desig-btn"
                                        aria-label="Remove {{ $designation['name'] }}">×</button>
                                </div>
                                <div class="tf-department-designation__role-editor" hidden>
                                    <select class="form-select dept-role-select" aria-label="Select role">
                                        <option value="">Select role from roles list</option>
                                        @foreach ($availableRoles as $role)
                                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" class="tf-btn tf-btn--primary add-dept-role-btn">Add role</button>
                                    <button type="button" class="tf-department-designation__cancel-role">Cancel</button>
                                </div>
                            </article>
                        @endforeach
                        <p id="noDeptDesigHint" class="tf-department-designations__empty"
                            @if(!empty($currentDesignations)) hidden @endif>
                            No designations added yet.
                        </p>
                    </div>
                    @error('designations')
                    <div class="tf-management__field-error">{{ $message }}</div> @enderror
                    @error('designation')
                    <div class="tf-management__field-error">{{ $message }}</div> @enderror
                    @foreach ($errors->getMessages() as $field => $messages)
                        @if (str_starts_with($field, 'designations.'))
                            @foreach ($messages as $message)
                                <div class="tf-management__field-error">{{ $message }}</div>
                            @endforeach
                        @endif
                    @endforeach
                </div>
                <div class="tf-user-offcanvas__field tf-user-offcanvas__field--full">
                    <label for="departmentDescription">Description</label>
                    <textarea id="departmentDescription" name="description" class="form-control" rows="4"
                        maxlength="2000"
                        placeholder="Optional department details">{{ old('description', $department?->description) }}</textarea>
                    @error('description')
                    <div class="tf-management__field-error">{{ $message }}</div> @enderror
                </div>
                <div class="tf-user-offcanvas__field">
                    <label for="departmentFormStatus">Status <span>*</span></label>
                    <select id="departmentFormStatus" name="status" class="form-select" required>
                        <option value="active" @selected(old('status', $department?->status ?? 'active') === 'active')>
                            Active</option>
                        <option value="inactive" @selected(old('status', $department?->status) === 'inactive')>Inactive
                        </option>
                    </select>
                    @error('status')
                    <div class="tf-management__field-error">{{ $message }}</div> @enderror
                </div>
            </div>
        </section>
    </div>
    <div class="modal-footer tf-user-offcanvas__footer">
        <button type="button" class="tf-btn tf-btn--ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="tf-btn tf-btn--primary">
            <i class="bi bi-check-lg"></i>
            {{ $submitLabel }}
        </button>
    </div>
</form>