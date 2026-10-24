@php
    $isEditingTask = isset($editingTask) && $editingTask instanceof \App\Models\Task;
    $hasAttachmentErrors = collect($errors->keys())->contains(
        fn (string $key): bool => $key === 'attachments' || str_starts_with($key, 'attachments.'),
    );
    $modalCompanies = \Illuminate\Support\Facades\Schema::hasTable('companies')
        ? \App\Models\Company::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])
        : collect();
    $initialPocIds = old('poc_ids', $isEditingTask ? $editingTask->pocs->modelKeys() : []);
    $initialPocIds = is_array($initialPocIds) ? array_values(array_map('strval', $initialPocIds)) : [];
    $initialCompanyId = old('company_id', $isEditingTask ? $editingTask->company_id : '');
    $initialDepartmentId = old('department_id', $isEditingTask ? $editingTask->department_id : '');
    $initialAssigneeIds = old('assigned_to', $isEditingTask
        ? ($editingTask->activeAssignees->isNotEmpty()
            ? $editingTask->activeAssignees->modelKeys()
            : ($editingTask->assigned_to ? [$editingTask->assigned_to] : []))
        : []);
    $initialAssigneeIds = is_array($initialAssigneeIds) ? array_values(array_map('strval', $initialAssigneeIds)) : [];
    $initialTask = $isEditingTask ? [
        'id' => $editingTask->id,
        'title' => $editingTask->title,
        'company_id' => $editingTask->company_id,
        'department_id' => $editingTask->department_id,
        'poc_ids' => $initialPocIds,
        'assigned_to' => $initialAssigneeIds,
        'priority' => $editingTask->priority,
        'task_type' => $editingTask->task_type,
        'original_deadline' => $editingTask->original_deadline?->format('Y-m-d'),
        'estimated_hours' => $editingTask->estimated_hours,
        'expected_output' => $editingTask->expected_output,
    ] : null;
@endphp

<div
    class="modal fade tf-create-task-modal"
    id="createTaskModal"
    tabindex="-1"
    aria-labelledby="createTaskLabel"
    aria-hidden="true"
    data-departments-url="{{ route('departments.by-company', ['company' => '__COMPANY__']) }}"
    data-users-url="{{ route('tasks.users-by-department', ['department' => '__DEPARTMENT__']) }}"
    data-create-action="{{ route('tasks.store') }}"
    data-update-url="{{ route('tasks.update', ['task' => '__TASK_ID__']) }}"
    data-form-mode="{{ $isEditingTask ? 'edit' : 'create' }}"
    data-task-id="{{ $isEditingTask ? $editingTask->id : '' }}"
>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable tf-create-task-dialog">
        <div class="modal-content">
            <form id="createTaskForm" method="POST" enctype="multipart/form-data" action="{{ $isEditingTask ? route('tasks.update', $editingTask) : route('tasks.store') }}">
                @csrf
                @if ($isEditingTask)
                    @method('PUT')
                @endif
                <header class="tf-create-task__header">
                    <div class="tf-create-task__heading">
                        <span class="tf-create-task__icon" aria-hidden="true">
                            <i class="bi bi-clipboard2-check"></i>
                        </span>
                        <div>
                            <span class="tf-create-task__eyebrow">Task workspace</span>
                            <h2 class="modal-title" id="createTaskLabel">{{ $isEditingTask ? 'Edit Task' : 'Create Task' }}</h2>
                            <p id="createTaskDescription">{{ $isEditingTask ? 'Update the task details below.' : 'Fill in the task details below.' }}</p>
                        </div>
                    </div>
                    <button type="button" class="tf-create-task__close" data-bs-dismiss="modal" aria-label="Close">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="modal-body tf-create-task__body">
                    @if ($errors->any())
                        <div class="tf-create-task__error-summary" role="alert" data-task-error-summary>
                            <strong>Please review the task details.</strong>
                            <ul>
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="tf-create-task__grid">
                        <div class="tf-create-task__field tf-create-task__field--full">
                            <label for="createTaskTitle">Task title <span>*</span></label>
                            <input
                                class="form-control @error('title') is-invalid @enderror"
                                id="createTaskTitle"
                                name="title"
                                value="{{ old('title', $isEditingTask ? $editingTask->title : '') }}"
                                maxlength="255"
                                placeholder="Describe the expected work"
                                required
                            >
                            @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="tf-create-task__field">
                            <label for="createTaskCompany">Company <span>*</span></label>
                            <select
                                class="form-select @error('company_id') is-invalid @enderror"
                                id="createTaskCompany"
                                name="company_id"
                                required
                            >
                                <option value="">Select company</option>
                                @foreach ($modalCompanies as $company)
                                    <option value="{{ $company->id }}" @selected($initialCompanyId == $company->id)>{{ $company->name }}</option>
                                @endforeach
                            </select>
                            @error('company_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="tf-create-task__field">
                            <label for="createTaskDepartment">Department <span>*</span></label>
                            <input type="hidden" name="department_id" id="createTaskDepartmentHidden" value="{{ $initialDepartmentId }}">
                            <select
                                class="form-select @error('department_id') is-invalid @enderror"
                                id="createTaskDepartment"
                                aria-required="true"
                                disabled
                            >
                                <option value="">Select company first</option>
                            </select>
                            @error('department_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="tf-create-task__field">
                            <label for="createTaskPocToggle">Department POC(s) <span>*</span></label>
                            <div class="tf-create-task__picker">
                                <button
                                    class="tf-create-task__picker-toggle"
                                    id="createTaskPocToggle"
                                    type="button"
                                    aria-expanded="false"
                                    aria-controls="createTaskPocMenu"
                                    disabled
                                >
                                    <span id="createTaskPocSummary">Select one or more POCs</span>
                                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                </button>
                                <div class="tf-create-task__picker-menu" id="createTaskPocMenu" hidden>
                                    <div class="tf-create-task__picker-search">
                                        <i class="bi bi-search" aria-hidden="true"></i>
                                        <input id="createTaskPocSearch" type="search" placeholder="Search POCs..." aria-label="Search department POCs">
                                    </div>
                                    <div class="tf-create-task__picker-options" id="createTaskPocOptions" role="group" aria-label="Department POCs"></div>
                                    <footer class="tf-create-task__picker-footer">
                                        <span id="createTaskPocCount">0 selected</span>
                                        <button type="button" id="clearAllPocsBtn">Clear all</button>
                                    </footer>
                                </div>
                            </div>
                            <div class="tf-create-task__selected-pocs" id="pocChipsContainer" aria-live="polite"></div>
                            <div class="tf-create-task__field-error" id="createTaskPocError" @if (! $errors->has('poc_ids') && ! $errors->has('poc_ids.*')) hidden @endif>
                                @error('poc_ids'){{ $message }}@enderror
                                @error('poc_ids.*'){{ $message }}@enderror
                            </div>
                        </div>

                        <div class="tf-create-task__field">
                            <label for="createTaskAssigneeToggle">Assigned Employees <span class="tf-create-task__optional">(optional)</span></label>
                            <input type="hidden" name="assigned_to_present" value="1">
                            <div class="tf-create-task__picker">
                                <button
                                    class="tf-create-task__picker-toggle"
                                    id="createTaskAssigneeToggle"
                                    type="button"
                                    aria-expanded="false"
                                    aria-controls="createTaskAssigneeMenu"
                                    disabled
                                >
                                    <span id="createTaskAssigneeSummary">Select one or more employees</span>
                                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                </button>
                                <div class="tf-create-task__picker-menu" id="createTaskAssigneeMenu" hidden>
                                    <div class="tf-create-task__picker-search">
                                        <i class="bi bi-search" aria-hidden="true"></i>
                                        <input id="createTaskAssigneeSearch" type="search" placeholder="Search employees..." aria-label="Search assigned employees">
                                    </div>
                                    <div class="tf-create-task__picker-options" id="createTaskAssigneeOptions" role="group" aria-label="Assigned employees"></div>
                                    <footer class="tf-create-task__picker-footer">
                                        <span id="createTaskAssigneeCount">0 selected</span>
                                        <button type="button" id="clearAllAssigneesBtn">Clear all</button>
                                    </footer>
                                </div>
                            </div>
                            <div class="tf-create-task__selected-pocs" id="assigneeChipsContainer" aria-live="polite"></div>
                            <div class="tf-create-task__field-error" id="createTaskAssigneeError" @if (! $errors->has('assigned_to') && ! $errors->has('assigned_to.*')) hidden @endif>
                                @error('assigned_to'){{ $message }}@enderror
                                @error('assigned_to.*'){{ $message }}@enderror
                            </div>
                        </div>

                        @unless ($isEditingTask)
                            <div class="tf-create-task__field tf-create-task__field--full">
                                <p class="tf-create-task__hint mb-0">Tasks without an employee start as Notified and require POC acceptance. Tasks created with an employee start as Assigned.</p>
                            </div>
                        @endunless

                        <div class="tf-create-task__field">
                            <label for="createTaskPriority">Priority <span>*</span></label>
                            <select class="form-select @error('priority') is-invalid @enderror" id="createTaskPriority" name="priority" required>
                                <option value="">Select priority</option>
                                <option value="low" @selected(old('priority', $isEditingTask ? $editingTask->priority : '') === 'low')>Low</option>
                                <option value="medium" @selected(old('priority', $isEditingTask ? $editingTask->priority : '') === 'medium')>Medium</option>
                                <option value="high" @selected(old('priority', $isEditingTask ? $editingTask->priority : '') === 'high')>High</option>
                                <option value="critical" @selected(old('priority', $isEditingTask ? $editingTask->priority : '') === 'critical')>Critical</option>
                            </select>
                            @error('priority') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="tf-create-task__field">
                            <label for="createTaskType">Task type <span>*</span></label>
                            <select class="form-select @error('task_type') is-invalid @enderror" id="createTaskType" name="task_type" required>
                                <option value="">Select task type</option>
                                <option value="process" @selected(old('task_type', $isEditingTask ? $editingTask->task_type : '') === 'process')>Process</option>
                                <option value="volume" @selected(old('task_type', $isEditingTask ? $editingTask->task_type : '') === 'volume')>Volume</option>
                                <option value="recurring" @selected(old('task_type', $isEditingTask ? $editingTask->task_type : '') === 'recurring')>Recurring</option>
                                <option value="project" @selected(old('task_type', $isEditingTask ? $editingTask->task_type : '') === 'project')>Project</option>
                            </select>
                            @error('task_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="tf-create-task__field">
                            <label for="createTaskDeadline">Original deadline <span id="createTaskDeadlineRequired" @if ($isEditingTask) hidden @endif>*</span></label>
                            <input
                                class="form-control @error('original_deadline') is-invalid @enderror"
                                id="createTaskDeadline"
                                name="{{ $isEditingTask ? '' : 'original_deadline' }}"
                                type="date"
                                value="{{ old('original_deadline', $isEditingTask ? $editingTask->original_deadline?->format('Y-m-d') : '') }}"
                                min="{{ $isEditingTask ? '' : now()->toDateString() }}"
                                data-create-min-date="{{ now()->toDateString() }}"
                                @readonly($isEditingTask)
                                @disabled($isEditingTask)
                                @required(! $isEditingTask)
                            >
                            <small class="tf-create-task__hint" id="createTaskDeadlineHint" @if (! $isEditingTask) hidden @endif>Original deadline cannot be changed after creation.</small>
                            @error('original_deadline') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="tf-create-task__field">
                            <label for="createTaskEffort">Estimated effort (hours) <span>*</span></label>
                            <input
                                class="form-control @error('estimated_hours') is-invalid @enderror"
                                id="createTaskEffort"
                                name="estimated_hours"
                                type="number"
                                step="0.1"
                                min="0.1"
                                max="9999.99"
                                value="{{ old('estimated_hours', $isEditingTask ? $editingTask->estimated_hours : '') }}"
                                placeholder="e.g. 4"
                                required
                            >
                            @error('estimated_hours') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="tf-create-task__field tf-create-task__field--full">
                            <label for="createTaskExpectedOutput">Expected output <span>*</span></label>
                            <textarea
                                class="form-control @error('expected_output') is-invalid @enderror"
                                id="createTaskExpectedOutput"
                                name="expected_output"
                                rows="2"
                                maxlength="5000"
                                placeholder="What does successful completion look like?"
                                required
                            >{{ old('expected_output', $isEditingTask ? $editingTask->expected_output : '') }}</textarea>
                            @error('expected_output') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="tf-create-task__field tf-create-task__field--full">
                            <label for="createTaskAttachments">Attachments <span class="tf-create-task__optional">(optional)</span></label>
                            <input
                                class="form-control @error('attachments') is-invalid @enderror @error('attachments.*') is-invalid @enderror"
                                id="createTaskAttachments"
                                name="attachments[]"
                                type="file"
                                accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.jpg,.jpeg,.png,.webp"
                                multiple
                                aria-describedby="createTaskAttachmentsHint createTaskAttachmentsSummary"
                            >
                            <small class="tf-create-task__hint" id="createTaskAttachmentsHint">Choose up to 10 files. PDF, Office, CSV, text, and image files are supported (10 MB max per file).</small>
                            <small class="tf-create-task__attachments-summary" id="createTaskAttachmentsSummary" aria-live="polite">No files selected</small>
                            @error('attachments') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            @error('attachments.*') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                    </div>

                    <p class="tf-create-task__deadline-note">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        The original deadline is preserved in the task history. Internal delegation does not reset it.
                    </p>
                </div>

                <footer class="tf-create-task__footer">
                    <button class="tf-create-task__cancel" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="tf-create-task__submit" type="submit" id="submitCreateTaskBtn">
                        <i class="bi {{ $isEditingTask ? 'bi-check-lg' : 'bi-plus-lg' }}" aria-hidden="true"></i>
                        <span id="submitCreateTaskLabel">{{ $isEditingTask ? 'Save changes' : 'Create task' }}</span>
                    </button>
                </footer>
            </form>
        </div>
    </div>
</div>

<script>
(() => {
    const modal = document.getElementById('createTaskModal');
    const form = document.getElementById('createTaskForm');
    if (!modal || !form) {
        return;
    }

    const companySelect = document.getElementById('createTaskCompany');
    const departmentSelect = document.getElementById('createTaskDepartment');
    const departmentHidden = document.getElementById('createTaskDepartmentHidden');
    const assigneeToggle = document.getElementById('createTaskAssigneeToggle');
    const assigneeMenu = document.getElementById('createTaskAssigneeMenu');
    const assigneeOptions = document.getElementById('createTaskAssigneeOptions');
    const assigneeSearch = document.getElementById('createTaskAssigneeSearch');
    const assigneeSummary = document.getElementById('createTaskAssigneeSummary');
    const assigneeCount = document.getElementById('createTaskAssigneeCount');
    const assigneeChips = document.getElementById('assigneeChipsContainer');
    const assigneeError = document.getElementById('createTaskAssigneeError');
    const pocToggle = document.getElementById('createTaskPocToggle');
    const pocMenu = document.getElementById('createTaskPocMenu');
    const pocOptions = document.getElementById('createTaskPocOptions');
    const pocSearch = document.getElementById('createTaskPocSearch');
    const pocSummary = document.getElementById('createTaskPocSummary');
    const pocCount = document.getElementById('createTaskPocCount');
    const pocChips = document.getElementById('pocChipsContainer');
    const pocError = document.getElementById('createTaskPocError');
    const taskTitle = document.getElementById('createTaskTitle');
    const prioritySelect = document.getElementById('createTaskPriority');
    const taskTypeSelect = document.getElementById('createTaskType');
    const deadlineInput = document.getElementById('createTaskDeadline');
    const deadlineHint = document.getElementById('createTaskDeadlineHint');
    const deadlineRequired = document.getElementById('createTaskDeadlineRequired');
    const estimatedHoursInput = document.getElementById('createTaskEffort');
    const expectedOutputInput = document.getElementById('createTaskExpectedOutput');
    const attachmentsInput = document.getElementById('createTaskAttachments');
    const attachmentsSummary = document.getElementById('createTaskAttachmentsSummary');
    const modalTitle = document.getElementById('createTaskLabel');
    const modalDescription = document.getElementById('createTaskDescription');
    const submitButton = document.getElementById('submitCreateTaskBtn');
    const submitIcon = submitButton.querySelector('i');
    const submitLabel = document.getElementById('submitCreateTaskLabel');
    const departmentsUrl = modal.dataset.departmentsUrl;
    const usersUrl = modal.dataset.usersUrl;
    const selectedPocIds = new Set(@json($initialPocIds));
    const selectedAssigneeIds = new Set(@json($initialAssigneeIds));
    const initialDepartmentId = @json((string) $initialDepartmentId);
    const initialAssigneeIds = @json($initialAssigneeIds);
    const initialTask = @json($initialTask);
    let stateRequestId = 0;
    let currentPocs = [];
    let departmentUsers = [];

    attachmentsInput.addEventListener('change', () => {
        const selectedFiles = Array.from(attachmentsInput.files ?? []);
        attachmentsSummary.textContent = selectedFiles.length
            ? selectedFiles.map(file => file.name).join(', ')
            : 'No files selected';
    });

    const syncDepartmentHidden = () => {
        if (!departmentSelect.disabled) {
            departmentHidden.value = departmentSelect.value || '';
        }
    };

    // Container for hidden poc_ids[] inputs that always travel with the form
    const pocHiddenContainer = document.createElement('div');
    pocHiddenContainer.id = 'pocHiddenInputs';
    form.appendChild(pocHiddenContainer);
    const syncPocHiddenInputs = () => {
        pocHiddenContainer.replaceChildren();
        selectedPocIds.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'poc_ids[]';
            input.value = id;
            pocHiddenContainer.appendChild(input);
        });
    };

    const assigneeHiddenContainer = document.createElement('div');
    assigneeHiddenContainer.id = 'assigneeHiddenInputs';
    form.appendChild(assigneeHiddenContainer);
    const syncAssigneeHiddenInputs = () => {
        assigneeHiddenContainer.replaceChildren();
        selectedAssigneeIds.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'assigned_to[]';
            input.value = id;
            assigneeHiddenContainer.appendChild(input);
        });
    };

    const setSelectMessage = (select, message, disabled = true) => {
        select.replaceChildren(new Option(message, ''));
        select.disabled = disabled;
    };

    const renderPocs = () => {
        const selectedUsers = currentPocs.filter(user => selectedPocIds.has(String(user.id)));
        pocOptions.replaceChildren();

        if (currentPocs.length === 0) {
            const emptyMessage = document.createElement('p');
            emptyMessage.className = 'tf-create-task__picker-empty';
            emptyMessage.textContent = 'No eligible POCs in this department.';
            pocOptions.append(emptyMessage);
        } else {
            currentPocs.forEach(user => {
                const option = document.createElement('label');
                option.className = 'tf-create-task__picker-option';

                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.value = String(user.id);
                checkbox.checked = selectedPocIds.has(String(user.id));
                checkbox.addEventListener('change', () => {
                    if (checkbox.checked) {
                        selectedPocIds.add(checkbox.value);
                    } else {
                        selectedPocIds.delete(checkbox.value);
                    }
                    syncPocHiddenInputs();
                    renderPocs();
                    pocError.hidden = selectedPocIds.size > 0;
                });

                const details = document.createElement('span');
                details.className = 'tf-create-task__picker-option-details';
                const name = document.createElement('strong');
                name.textContent = user.name;
                details.append(name);
                if (user.designation) {
                    const designation = document.createElement('small');
                    designation.textContent = user.designation;
                    details.append(designation);
                }

                option.append(checkbox, details);
                option.hidden = !`${user.name} ${user.designation || ''}`.toLowerCase().includes(pocSearch.value.trim().toLowerCase());
                pocOptions.append(option);
            });
        }

        pocSummary.textContent = selectedUsers.length
            ? `${selectedUsers.length} POC${selectedUsers.length === 1 ? '' : 's'} selected`
            : 'Select one or more POCs';
        pocCount.textContent = `${selectedUsers.length} selected`;
        pocChips.replaceChildren();
        selectedUsers.forEach(user => {
            const chip = document.createElement('span');
            chip.className = 'tf-create-task__poc-chip';
            chip.textContent = user.name;

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.setAttribute('aria-label', `Remove ${user.name}`);
            remove.innerHTML = '&times;';
            remove.addEventListener('click', () => {
                selectedPocIds.delete(String(user.id));
                syncPocHiddenInputs();
                renderPocs();
                pocError.hidden = selectedPocIds.size > 0;
            });

            chip.append(remove);
            pocChips.append(chip);
        });
    };

    const renderAssignees = () => {
        const selectedUsers = departmentUsers.filter(user => selectedAssigneeIds.has(String(user.id)));
        assigneeOptions.replaceChildren();

        if (departmentUsers.length === 0) {
            const emptyMessage = document.createElement('p');
            emptyMessage.className = 'tf-create-task__picker-empty';
            emptyMessage.textContent = 'No active employees in this department.';
            assigneeOptions.append(emptyMessage);
        } else {
            departmentUsers.forEach(user => {
                const option = document.createElement('label');
                option.className = 'tf-create-task__picker-option';

                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.value = String(user.id);
                checkbox.checked = selectedAssigneeIds.has(String(user.id));
                checkbox.addEventListener('change', () => {
                    if (checkbox.checked) {
                        selectedAssigneeIds.add(checkbox.value);
                    } else {
                        selectedAssigneeIds.delete(checkbox.value);
                    }
                    syncAssigneeHiddenInputs();
                    renderAssignees();
                    assigneeError.hidden = true;
                });

                const details = document.createElement('span');
                details.className = 'tf-create-task__picker-option-details';
                const name = document.createElement('strong');
                name.textContent = user.name;
                details.append(name);
                if (user.designation) {
                    const designation = document.createElement('small');
                    designation.textContent = user.designation;
                    details.append(designation);
                }

                option.append(checkbox, details);
                option.hidden = !`${user.name} ${user.designation || ''}`.toLowerCase().includes(assigneeSearch.value.trim().toLowerCase());
                assigneeOptions.append(option);
            });
        }

        assigneeSummary.textContent = selectedUsers.length
            ? `${selectedUsers.length} employee${selectedUsers.length === 1 ? '' : 's'} selected`
            : 'Select one or more employees';
        assigneeCount.textContent = `${selectedUsers.length} selected`;
        assigneeChips.replaceChildren();
        selectedUsers.forEach(user => {
            const chip = document.createElement('span');
            chip.className = 'tf-create-task__poc-chip';
            chip.textContent = user.name;

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.setAttribute('aria-label', `Remove ${user.name}`);
            remove.innerHTML = '&times;';
            remove.addEventListener('click', () => {
                selectedAssigneeIds.delete(String(user.id));
                syncAssigneeHiddenInputs();
                renderAssignees();
            });

            chip.append(remove);
            assigneeChips.append(chip);
        });
    };

    const loadUsers = async (departmentId, { pocIds = [], assigneeIds = [] } = {}, requestId = stateRequestId) => {
        currentPocs = [];
        departmentUsers = [];
        if (departmentId) {
            selectedPocIds.clear();
            pocIds.forEach(id => selectedPocIds.add(String(id)));
            selectedAssigneeIds.clear();
            assigneeIds.forEach(id => selectedAssigneeIds.add(String(id)));
        }
        renderPocs();
        renderAssignees();
        pocToggle.disabled = true;
        assigneeToggle.disabled = true;

        if (!departmentId) {
            return;
        }

        try {
            const response = await fetch(usersUrl.replace('__DEPARTMENT__', encodeURIComponent(departmentId)), {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) {
                throw new Error(`Could not load department users (HTTP ${response.status}).`);
            }
            const users = await response.json();
            if (!users || !Array.isArray(users.pocs) || !Array.isArray(users.employees)) {
                throw new Error('The department users response was invalid.');
            }
            if (requestId !== stateRequestId) {
                return;
            }
            currentPocs = users.pocs;
            departmentUsers = users.employees;
            [...currentPocs, ...departmentUsers].forEach(user => {
                if (!user || !user.id || typeof user.name !== 'string') {
                    throw new Error('The department users response was invalid.');
                }
            });
            const eligiblePocIds = new Set(currentPocs.map(user => String(user.id)));
            selectedPocIds.forEach(id => {
                if (!eligiblePocIds.has(id)) {
                    selectedPocIds.delete(id);
                }
            });
            syncPocHiddenInputs();
            renderPocs();
            pocToggle.disabled = currentPocs.length === 0;

            const eligibleAssigneeIds = new Set(departmentUsers.map(user => String(user.id)));
            selectedAssigneeIds.forEach(id => {
                if (!eligibleAssigneeIds.has(id)) {
                    selectedAssigneeIds.delete(id);
                }
            });
            syncAssigneeHiddenInputs();
            renderAssignees();
            assigneeToggle.disabled = departmentUsers.length === 0;
        } catch (error) {
            if (requestId !== stateRequestId) {
                return;
            }
            currentPocs = [];
            departmentUsers = [];
            selectedPocIds.clear();
            selectedAssigneeIds.clear();
            renderPocs();
            renderAssignees();
            syncAssigneeHiddenInputs();
            pocOptions.replaceChildren();
            const errorMessage = document.createElement('p');
            errorMessage.className = 'tf-create-task__picker-empty';
            errorMessage.setAttribute('role', 'alert');
            errorMessage.textContent = 'Unable to load department users. Please try selecting the department again.';
            pocOptions.append(errorMessage);
            pocToggle.disabled = true;
            assigneeToggle.disabled = true;
            throw error;
        }
    };

    const loadDepartments = async (companyId, selectedDepartmentId = '', selectedAssigneeIds = [], pocIds = [], requestId = stateRequestId) => {
        setSelectMessage(departmentSelect, companyId ? 'Loading departments...' : 'Select company first');
        await loadUsers('', {}, requestId);
        if (requestId !== stateRequestId) {
            return;
        }
        if (!companyId) {
            return;
        }

        try {
            const response = await fetch(departmentsUrl.replace('__COMPANY__', encodeURIComponent(companyId)), {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) {
                throw new Error(`Could not load departments (HTTP ${response.status}).`);
            }
            const departments = await response.json();
            if (requestId !== stateRequestId) {
                return;
            }
            departmentSelect.replaceChildren(new Option('Select department', ''));
            departments.filter(department => department.status === 'active' || String(department.id) === String(selectedDepartmentId)).forEach(department => {
                const option = new Option(department.name, String(department.id));
                option.selected = String(department.id) === String(selectedDepartmentId);
                departmentSelect.append(option);
            });
            departmentSelect.disabled = false;
            syncDepartmentHidden();
        } catch (error) {
            if (requestId !== stateRequestId) {
                return;
            }
            setSelectMessage(departmentSelect, 'Unable to load departments');
            throw error;
        }

        if (selectedDepartmentId && departmentSelect.value) {
            await loadUsers(selectedDepartmentId, {
                pocIds,
                assigneeIds: selectedAssigneeIds,
            }, requestId);
        }
    };

    companySelect.addEventListener('change', () => {
        const requestId = ++stateRequestId;
        selectedPocIds.clear();
        syncPocHiddenInputs();
        selectedAssigneeIds.clear();
        syncAssigneeHiddenInputs();
        departmentHidden.value = '';
        pocError.hidden = true;
        assigneeError.hidden = true;
        loadDepartments(companySelect.value, '', [], [], requestId).catch(error => {
            console.error(error);
        });
    });

    departmentSelect.addEventListener('change', () => {
        const requestId = ++stateRequestId;
        selectedPocIds.clear();
        syncPocHiddenInputs();
        selectedAssigneeIds.clear();
        syncAssigneeHiddenInputs();
        pocError.hidden = true;
        assigneeError.hidden = true;
        syncDepartmentHidden();
        loadUsers(departmentSelect.value, {}, requestId).catch(error => {
            console.error(error);
        });
    });

    assigneeToggle.addEventListener('click', () => {
        const isExpanded = assigneeToggle.getAttribute('aria-expanded') === 'true';
        assigneeToggle.setAttribute('aria-expanded', String(!isExpanded));
        assigneeMenu.hidden = isExpanded;
        if (!isExpanded) {
            assigneeSearch.focus();
        }
    });

    pocToggle.addEventListener('click', () => {
        const isExpanded = pocToggle.getAttribute('aria-expanded') === 'true';
        pocToggle.setAttribute('aria-expanded', String(!isExpanded));
        pocMenu.hidden = isExpanded;
        if (!isExpanded) {
            pocSearch.focus();
        }
    });

    pocSearch.addEventListener('input', renderPocs);
    assigneeSearch.addEventListener('input', renderAssignees);
    document.getElementById('clearAllPocsBtn').addEventListener('click', () => {
        selectedPocIds.clear();
        syncPocHiddenInputs();
        pocError.hidden = true;
        renderPocs();
    });

    document.getElementById('clearAllAssigneesBtn').addEventListener('click', () => {
        selectedAssigneeIds.clear();
        syncAssigneeHiddenInputs();
        renderAssignees();
    });

    document.addEventListener('click', event => {
        if (!pocMenu.hidden && !event.target.closest('.tf-create-task__picker')) {
            pocMenu.hidden = true;
            pocToggle.setAttribute('aria-expanded', 'false');
        }
        if (!assigneeMenu.hidden && !event.target.closest('#createTaskAssigneeMenu, #createTaskAssigneeToggle')) {
            assigneeMenu.hidden = true;
            assigneeToggle.setAttribute('aria-expanded', 'false');
        }
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && (!pocMenu.hidden || !assigneeMenu.hidden)) {
            if (!pocMenu.hidden) {
                pocMenu.hidden = true;
                pocToggle.setAttribute('aria-expanded', 'false');
                pocToggle.focus();
            } else {
                assigneeMenu.hidden = true;
                assigneeToggle.setAttribute('aria-expanded', 'false');
                assigneeToggle.focus();
            }
            return;
        }

        if (event.key === 'Escape' && modal.classList.contains('show')) {
            getModalInstance().hide();
        }
    });

    form.addEventListener('submit', event => {
        // Validate POC selection
        if (selectedPocIds.size === 0) {
            event.preventDefault();
            pocError.textContent = 'Select at least one department POC.';
            pocError.hidden = false;
            pocMenu.hidden = false;
            pocToggle.setAttribute('aria-expanded', 'true');
            pocToggle.focus();
            return;
        }
        // Ensure hidden inputs are up-to-date before submit
        syncDepartmentHidden();
        syncAssigneeHiddenInputs();
        syncPocHiddenInputs();
    });

    const getModalInstance = () => window.bootstrap.Modal.getOrCreateInstance(modal);
    modal.querySelectorAll('[data-bs-dismiss="modal"]').forEach(button => {
        button.addEventListener('click', event => {
            event.preventDefault();
            event.stopPropagation();
            getModalInstance().hide();
        });
    });
    modal.addEventListener('click', event => {
        if (event.target === modal && modal.classList.contains('show')) {
            event.preventDefault();
            event.stopPropagation();
            getModalInstance().hide();
        }
    });
    const clearEditParameter = () => {
        const url = new URL(window.location.href);
        if (!url.searchParams.has('edit')) {
            return;
        }

        url.searchParams.delete('edit');
        window.history.replaceState(
            window.history.state,
            '',
            `${url.pathname}${url.search}${url.hash}`
        );
    };
    const setEditParameter = taskId => {
        const url = new URL(window.location.href);
        url.searchParams.set('edit', String(taskId));
        const address = `${url.pathname}${url.search}${url.hash}`;

        if (new URL(window.location.href).searchParams.get('edit') === String(taskId)) {
            window.history.replaceState(window.history.state, '', address);
            return;
        }

        window.history.pushState(window.history.state, '', address);
    };
    const clearValidationState = () => {
        form.querySelectorAll('.is-invalid').forEach(field => field.classList.remove('is-invalid'));
        form.querySelectorAll('.invalid-feedback, [data-task-error-summary]').forEach(message => message.remove());
        pocError.textContent = '';
        pocError.hidden = true;
    };
    const resetFormToCreate = ({ clearUrl = false } = {}) => {
        stateRequestId++;
        form.reset();
        attachmentsSummary.textContent = 'No files selected';
        clearValidationState();
        selectedPocIds.clear();
        currentPocs = [];
        departmentUsers = [];
        syncPocHiddenInputs();
        selectedAssigneeIds.clear();
        syncAssigneeHiddenInputs();
        renderPocs();
        renderAssignees();

        taskTitle.value = '';
        companySelect.value = '';
        departmentHidden.value = '';
        setSelectMessage(departmentSelect, 'Select company first');
        assigneeMenu.hidden = true;
        assigneeToggle.setAttribute('aria-expanded', 'false');
        assigneeToggle.disabled = true;
        assigneeSearch.value = '';
        prioritySelect.value = '';
        taskTypeSelect.value = '';
        deadlineInput.value = '';
        deadlineInput.name = 'original_deadline';
        deadlineInput.disabled = false;
        deadlineInput.readOnly = false;
        deadlineInput.required = true;
        deadlineInput.min = deadlineInput.dataset.createMinDate;
        deadlineHint.hidden = true;
        deadlineRequired.hidden = false;
        estimatedHoursInput.value = '';
        expectedOutputInput.value = '';
        pocSearch.value = '';
        pocMenu.hidden = true;
        pocToggle.setAttribute('aria-expanded', 'false');
        pocToggle.disabled = true;

        modal.dataset.formMode = 'create';
        modal.dataset.taskId = '';
        form.action = modal.dataset.createAction;
        form.method = 'POST';
        form.querySelector('input[name="_method"]')?.remove();
        modalTitle.textContent = 'Create Task';
        modalDescription.textContent = 'Fill in the task details below.';
        submitIcon.className = 'bi bi-plus-lg';
        submitLabel.textContent = 'Create task';

        if (clearUrl) {
            clearEditParameter();
        }
    };
    const openEditTask = (task, updateHistory = true) => {
        if (!task || !task.id) {
            throw new Error('The selected task data is missing its ID.');
        }

        resetFormToCreate();
        const taskId = String(task.id);
        const requestId = ++stateRequestId;
        modal.dataset.formMode = 'edit';
        modal.dataset.taskId = taskId;
        form.action = modal.dataset.updateUrl.replace('__TASK_ID__', encodeURIComponent(taskId));
        form.method = 'POST';

        let methodInput = form.querySelector('input[name="_method"]');
        if (!methodInput) {
            methodInput = document.createElement('input');
            methodInput.type = 'hidden';
            methodInput.name = '_method';
            form.append(methodInput);
        }
        methodInput.value = 'PUT';

        taskTitle.value = task.title ?? '';
        companySelect.value = String(task.company_id ?? '');
        prioritySelect.value = task.priority ?? '';
        taskTypeSelect.value = task.task_type ?? '';
        deadlineInput.value = task.original_deadline ?? '';
        deadlineInput.removeAttribute('name');
        deadlineInput.disabled = true;
        deadlineInput.readOnly = true;
        deadlineInput.required = false;
        deadlineInput.min = '';
        deadlineHint.hidden = false;
        deadlineRequired.hidden = true;
        estimatedHoursInput.value = task.estimated_hours ?? '';
        expectedOutputInput.value = task.expected_output ?? '';

        const pocIds = Array.isArray(task.poc_ids) ? task.poc_ids.map(String) : [];
        const assigneeIds = Array.isArray(task.assigned_to) ? task.assigned_to.map(String) : [];
        selectedPocIds.clear();
        pocIds.forEach(id => selectedPocIds.add(id));
        syncPocHiddenInputs();
        modalTitle.textContent = 'Edit Task';
        modalDescription.textContent = 'Update the task details below.';
        submitIcon.className = 'bi bi-check-lg';
        submitLabel.textContent = 'Save changes';

        if (updateHistory) {
            setEditParameter(taskId);
        }

        loadDepartments(
            task.company_id,
            task.department_id,
            assigneeIds,
            pocIds,
            requestId
        ).catch(error => {
            console.error(error);
        });
        getModalInstance().show();
    };
    const findTaskData = taskId => {
        if (initialTask && String(initialTask.id) === String(taskId)) {
            return initialTask;
        }

        const taskLink = [...document.querySelectorAll('[data-task-edit]')].find(link => {
            return String(JSON.parse(link.dataset.task).id) === String(taskId);
        });

        return taskLink ? JSON.parse(taskLink.dataset.task) : null;
    };

    document.addEventListener('click', event => {
        const createTrigger = event.target.closest('[data-task-create-trigger]');
        if (createTrigger) {
            event.preventDefault();
            resetFormToCreate({ clearUrl: true });
            getModalInstance().show();
            return;
        }

        const editTrigger = event.target.closest('[data-task-edit]');
        if (!editTrigger) {
            return;
        }

        event.preventDefault();
        openEditTask(JSON.parse(editTrigger.dataset.task));
    });
    modal.addEventListener('hide.bs.modal', () => {
        resetFormToCreate({ clearUrl: true });
    });
    window.addEventListener('popstate', () => {
        const taskId = new URL(window.location.href).searchParams.get('edit');
        if (taskId) {
            const task = findTaskData(taskId);
            if (task) {
                if (modal.dataset.taskId !== String(task.id) || modal.dataset.formMode !== 'edit') {
                    openEditTask(task, false);
                } else {
                    getModalInstance().show();
                }
            } else if (modal.classList.contains('show')) {
                getModalInstance().hide();
            }
            return;
        }

        if (modal.classList.contains('show')) {
            getModalInstance().hide();
        } else {
            resetFormToCreate();
        }
    });

    renderPocs();
    syncPocHiddenInputs();
    renderAssignees();
    syncAssigneeHiddenInputs();
    if (companySelect.value) {
        loadDepartments(companySelect.value, initialDepartmentId, initialAssigneeIds, [...selectedPocIds], stateRequestId).catch(error => {
            console.error(error);
        });
    }

    @if ($isEditingTask || $hasAttachmentErrors || $errors->hasAny(['title', 'company_id', 'department_id', 'poc_ids', 'poc_ids.*', 'assigned_to', 'task_type', 'priority', 'status', 'progress_percentage', 'original_deadline', 'due_date', 'estimated_hours', 'expected_output', 'description']))
        document.addEventListener('DOMContentLoaded', () => {
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
        }, { once: true });
    @endif
})();
</script>
