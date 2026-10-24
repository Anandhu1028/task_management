<div
    class="modal fade tf-reassign-modal"
    id="reassignTaskModal"
    tabindex="-1"
    aria-labelledby="reassignTaskModalLabel"
    aria-hidden="true"
    data-departments-url="{{ route('departments.by-company', ['company' => '__COMPANY__'], false) }}"
    data-users-url="{{ route('tasks.users-by-department', ['department' => '__DEPARTMENT__'], false) }}"
    data-preloaded-departments="{{ json_encode($departments ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
    data-preloaded-employees="{{ json_encode($employees ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
>
    <div class="modal-dialog modal-dialog-centered tf-reassign-modal__dialog">
        <div class="modal-content tf-reassign-modal__content">
            <form id="reassignTaskForm" method="POST" action="">
                @csrf
                <div class="modal-header tf-reassign-modal__header">
                    <div class="tf-reassign-modal__heading">
                        <span class="tf-reassign-modal__icon" aria-hidden="true">
                            <i class="bi bi-arrow-left-right"></i>
                        </span>
                        <div class="tf-reassign-modal__title">
                            <h2 class="modal-title" id="reassignTaskModalLabel">Re-assign task</h2>
                            <p>Choose one or more employees to assign this task to.</p>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body tf-reassign-modal__body">
                    <div class="tf-reassign-summary">
                        <div class="tf-reassign-summary__task">
                            <span class="tf-reassign-summary__code" id="reassignCurrentCode">—</span>
                            <h3 id="reassignCurrentTitle">—</h3>
                            <small id="reassignCurrentOrg">—</small>
                        </div>
                        <div class="tf-reassign-summary__transfer" aria-label="Current and new assignee">
                            <div class="tf-reassign-summary__person">
                                <span class="tf-reassign-summary__avatar" id="reassignCurrentAvatar" aria-hidden="true">?</span>
                                <span class="tf-reassign-summary__person-copy">
                                    <small>Current</small>
                                    <strong id="reassignCurrentAssignee">—</strong>
                                </span>
                            </div>
                            <i class="bi bi-arrow-right tf-reassign-summary__arrow" aria-hidden="true"></i>
                            <div class="tf-reassign-summary__person tf-reassign-summary__person--new">
                                <span class="tf-reassign-summary__avatar tf-reassign-summary__avatar--placeholder" id="reassignNewAvatar" aria-hidden="true">?</span>
                                <span class="tf-reassign-summary__person-copy">
                                    <small>New</small>
                                    <strong id="reassignNewAssignee">Not selected</strong>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="tf-reassign-modal__organization">
                        <div class="tf-reassign-field">
                            <label for="reassignCompanySelect">Company <span>*</span></label>
                            <select id="reassignCompanySelect" name="company_id" required>
                                <option value="">Select company</option>
                                @foreach ($companies as $company)
                                    <option value="{{ $company->id }}">{{ $company->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="tf-reassign-field">
                            <label for="reassignDepartmentSelect">Department <span>*</span></label>
                            <select id="reassignDepartmentSelect" name="department_id" required disabled>
                                <option value="">Select company first</option>
                            </select>
                        </div>
                    </div>

                    <div class="tf-reassign-field">
                        <label for="reassignAssigneeToggle">Assigned employees <span>*</span></label>
                        <div class="tf-reassign-picker" id="reassignAssigneePicker">
                            <button
                                class="tf-reassign-picker__toggle"
                                id="reassignAssigneeToggle"
                                type="button"
                                aria-expanded="false"
                                aria-controls="reassignAssigneeMenu"
                                aria-describedby="reassignAssigneeHint"
                                disabled
                            >
                                <span id="reassignAssigneeSummary">Select department first</span>
                                <i class="bi bi-chevron-down" aria-hidden="true"></i>
                            </button>
                            <div class="tf-reassign-picker__menu" id="reassignAssigneeMenu" hidden>
                                <div class="tf-reassign-picker__search">
                                    <i class="bi bi-search" aria-hidden="true"></i>
                                    <input id="reassignAssigneeSearch" type="search" placeholder="Search employees..." aria-label="Search assigned employees">
                                </div>
                                <div class="tf-reassign-picker__options" id="reassignAssigneeOptions" role="group" aria-label="Assigned employees"></div>
                                <footer class="tf-reassign-picker__footer">
                                    <span id="reassignAssigneeCount">0 selected</span>
                                    <button type="button" id="clearReassignAssignees">Clear all</button>
                                </footer>
                            </div>
                        </div>
                        <div id="reassignAssigneeInputs"></div>
                        <small class="tf-reassign-picker__error" id="reassignAssigneeError" role="alert" hidden>Select at least one employee.</small>
                        <small id="reassignAssigneeHint">Select all employees who should work on this task.</small>
                    </div>

                    <div class="tf-reassign-field tf-reassign-field--reason">
                        <label for="reassignReasonInput">Reason <span class="tf-reassign-field__optional">(optional)</span></label>
                        <textarea
                            id="reassignReasonInput"
                            name="reason"
                            rows="3"
                            maxlength="500"
                            placeholder="e.g. Workload rebalancing or role transfer"
                        ></textarea>
                    </div>
                </div>

                <div class="modal-footer tf-reassign-modal__footer">
                    <button type="button" class="tf-reassign-modal__cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="tf-reassign-modal__submit" id="reassignSubmitBtn">
                        <span>Re-assign Task</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(() => {
    const modalEl = document.getElementById('reassignTaskModal');
    if (!modalEl) return;

    const form = document.getElementById('reassignTaskForm');
    const companySelect = document.getElementById('reassignCompanySelect');
    const departmentSelect = document.getElementById('reassignDepartmentSelect');
    const assigneeToggle = document.getElementById('reassignAssigneeToggle');
    const assigneeMenu = document.getElementById('reassignAssigneeMenu');
    const assigneeSearch = document.getElementById('reassignAssigneeSearch');
    const assigneeOptions = document.getElementById('reassignAssigneeOptions');
    const assigneeSummary = document.getElementById('reassignAssigneeSummary');
    const assigneeCount = document.getElementById('reassignAssigneeCount');
    const assigneeInputs = document.getElementById('reassignAssigneeInputs');
    const assigneeError = document.getElementById('reassignAssigneeError');
    const clearAssigneesButton = document.getElementById('clearReassignAssignees');
    const reasonInput = document.getElementById('reassignReasonInput');

    const currentCode = document.getElementById('reassignCurrentCode');
    const currentOrg = document.getElementById('reassignCurrentOrg');
    const currentTitle = document.getElementById('reassignCurrentTitle');
    const currentAssignee = document.getElementById('reassignCurrentAssignee');
    const currentAvatar = document.getElementById('reassignCurrentAvatar');
    const newAssignee = document.getElementById('reassignNewAssignee');
    const newAvatar = document.getElementById('reassignNewAvatar');

    let preloadedDepartments = [];
    let preloadedEmployees = [];
    try {
        preloadedDepartments = JSON.parse(modalEl.dataset.preloadedDepartments || '[]');
    } catch (e) {
        preloadedDepartments = [];
    }
    try {
        preloadedEmployees = JSON.parse(modalEl.dataset.preloadedEmployees || '[]');
    } catch (e) {
        preloadedEmployees = [];
    }

    const departmentsUrl = modalEl.dataset.departmentsUrl;
    const usersUrl = modalEl.dataset.usersUrl;

    let activeRequestId = 0;
    let availableEmployees = [];
    const selectedEmployeeIds = new Set();

    const toRelativeUrl = (urlStr) => {
        try {
            const parsed = new URL(urlStr, window.location.origin);
            return parsed.pathname + parsed.search;
        } catch {
            return urlStr;
        }
    };

    const initialsFor = (name) => name
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map(part => part[0].toUpperCase())
        .join('') || '?';

    const updateNewAssigneePreview = () => {
        const selectedNames = availableEmployees
            .filter(employee => selectedEmployeeIds.has(String(employee.id)))
            .map(employee => employee.name);
        const previewText = selectedNames.length > 2
            ? `${selectedNames.length} employees selected`
            : selectedNames.join(', ');
        newAssignee.textContent = previewText || 'Not selected';
        newAssignee.title = selectedNames.join(', ');
        newAvatar.textContent = selectedNames.length ? initialsFor(selectedNames[0]) : '?';
        newAvatar.classList.toggle('tf-reassign-summary__avatar--placeholder', selectedNames.length === 0);
    };

    const syncSelectedEmployees = () => {
        assigneeInputs.replaceChildren();
        availableEmployees.forEach(employee => {
            if (!selectedEmployeeIds.has(String(employee.id))) {
                return;
            }

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'assigned_to[]';
            input.value = String(employee.id);
            assigneeInputs.append(input);
        });

        const selectedCount = selectedEmployeeIds.size;
        assigneeCount.textContent = `${selectedCount} selected`;
        assigneeSummary.textContent = selectedCount
            ? `${selectedCount} employee${selectedCount === 1 ? '' : 's'} selected`
            : availableEmployees.length ? 'Select employees' : 'No employees available';
        assigneeError.hidden = true;
        assigneeToggle.removeAttribute('aria-invalid');
        updateNewAssigneePreview();
    };

    const renderEmployees = () => {
        const searchTerm = assigneeSearch.value.trim().toLocaleLowerCase();
        const filteredEmployees = availableEmployees.filter(employee => {
            const searchText = `${employee.name} ${employee.designation || ''}`.toLocaleLowerCase();
            return searchText.includes(searchTerm);
        });

        assigneeOptions.replaceChildren();
        filteredEmployees.forEach(employee => {
            const label = document.createElement('label');
            label.className = 'tf-reassign-picker__option';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = String(employee.id);
            checkbox.checked = selectedEmployeeIds.has(String(employee.id));
            checkbox.addEventListener('change', () => {
                if (checkbox.checked) {
                    selectedEmployeeIds.add(String(employee.id));
                } else {
                    selectedEmployeeIds.delete(String(employee.id));
                }
                syncSelectedEmployees();
            });

            const details = document.createElement('span');
            details.className = 'tf-reassign-picker__option-details';
            details.textContent = employee.name;

            if (employee.designation) {
                const designation = document.createElement('small');
                designation.textContent = employee.designation;
                details.append(designation);
            }

            label.append(checkbox, details);
            assigneeOptions.append(label);
        });

        if (filteredEmployees.length === 0) {
            const emptyMessage = document.createElement('p');
            emptyMessage.className = 'tf-reassign-picker__empty';
            emptyMessage.textContent = availableEmployees.length
                ? 'No employees match your search.'
                : 'No active employees are available in this department.';
            assigneeOptions.append(emptyMessage);
        }
    };

    const setEmployees = (employees, targetUserIds = []) => {
        availableEmployees = employees;
        selectedEmployeeIds.clear();
        const availableEmployeeIds = new Set(availableEmployees.map(employee => String(employee.id)));
        targetUserIds.map(String).forEach(id => {
            if (availableEmployeeIds.has(id)) {
                selectedEmployeeIds.add(id);
            }
        });
        assigneeSearch.value = '';
        assigneeToggle.disabled = availableEmployees.length === 0;
        renderEmployees();
        syncSelectedEmployees();
    };

    const loadDepartments = async (companyId, targetDeptId = null, targetUserIds = []) => {
        const requestId = ++activeRequestId;
        departmentSelect.disabled = true;
        departmentSelect.replaceChildren(new Option('Loading departments...', ''));
        assigneeToggle.disabled = true;
        setEmployees([]);
        updateNewAssigneePreview();

        if (!companyId) {
            departmentSelect.replaceChildren(new Option('Select company first', ''));
            assigneeSummary.textContent = 'Select department first';
            return;
        }

        // 1. Try local preloaded departments first
        const matchedDepts = preloadedDepartments.filter(d => String(d.company_id) === String(companyId));
        if (matchedDepts.length > 0) {
            departmentSelect.replaceChildren(new Option('Select department', ''));
            matchedDepts.forEach(d => {
                const opt = new Option(d.name, String(d.id));
                if (String(d.id) === String(targetDeptId)) {
                    opt.selected = true;
                }
                departmentSelect.append(opt);
            });
            departmentSelect.disabled = false;

            if (targetDeptId && departmentSelect.value) {
                await loadUsers(departmentSelect.value, targetUserIds);
            }
            return;
        }

        // 2. Fetch via relative endpoint
        try {
            const url = toRelativeUrl(departmentsUrl.replace('__COMPANY__', encodeURIComponent(companyId)));
            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            });
            if (!response.ok) throw new Error('Failed to load departments');
            const data = await response.json();

            if (requestId !== activeRequestId) return;

            departmentSelect.replaceChildren(new Option('Select department', ''));
            data.filter(d => d.status === 'active' || String(d.id) === String(targetDeptId)).forEach(d => {
                const opt = new Option(d.name, String(d.id));
                if (String(d.id) === String(targetDeptId)) {
                    opt.selected = true;
                }
                departmentSelect.append(opt);
            });
            departmentSelect.disabled = false;

            if (targetDeptId && departmentSelect.value) {
                await loadUsers(departmentSelect.value, targetUserIds);
            }
        } catch (error) {
            if (requestId !== activeRequestId) return;
            departmentSelect.replaceChildren(new Option('Error loading departments', ''));
            console.error(error);
        }
    };

    const loadUsers = async (departmentId, targetUserIds = []) => {
        const requestId = ++activeRequestId;
        assigneeToggle.disabled = true;
        setEmployees([]);
        assigneeSummary.textContent = 'Loading employees...';
        updateNewAssigneePreview();

        if (!departmentId) {
            assigneeSummary.textContent = 'Select department first';
            return;
        }

        // 1. Try local preloaded employees first
        const matchedEmployees = preloadedEmployees.filter(u => String(u.department_id) === String(departmentId));
        if (matchedEmployees.length > 0) {
            setEmployees(matchedEmployees, targetUserIds);
            return;
        }

        // 2. Fetch via relative endpoint
        try {
            const url = toRelativeUrl(usersUrl.replace('__DEPARTMENT__', encodeURIComponent(departmentId)));
            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            });
            if (!response.ok) throw new Error('Failed to load users');
            const data = await response.json();

            if (requestId !== activeRequestId) return;

            const employees = Array.isArray(data) ? data : (data.employees || data.pocs || []);
            setEmployees(employees, targetUserIds);
        } catch (error) {
            if (requestId !== activeRequestId) return;
            setEmployees([]);
            assigneeSummary.textContent = 'Error loading employees';
            console.error(error);
        }
    };

    companySelect.addEventListener('change', () => {
        loadDepartments(companySelect.value);
    });

    departmentSelect.addEventListener('change', () => {
        loadUsers(departmentSelect.value);
    });

    assigneeToggle.addEventListener('click', () => {
        const isExpanded = assigneeToggle.getAttribute('aria-expanded') === 'true';
        assigneeToggle.setAttribute('aria-expanded', String(!isExpanded));
        assigneeMenu.hidden = isExpanded;
        if (!isExpanded) {
            const pickerRect = assigneeToggle.getBoundingClientRect();
            const footerRect = modalEl.querySelector('.tf-reassign-modal__footer').getBoundingClientRect();
            const modalRect = modalEl.getBoundingClientRect();
            const spaceBelow = Math.max(0, Math.min(
                window.innerHeight - pickerRect.bottom,
                footerRect.top - pickerRect.bottom,
            ));
            const spaceAbove = Math.max(0, pickerRect.top - modalRect.top);
            const opensUp = spaceBelow < 220 && spaceAbove > spaceBelow;
            assigneeMenu.classList.toggle('tf-reassign-picker__menu--up', opensUp);
            assigneeOptions.style.maxHeight = `${Math.max(60, Math.min(145, (opensUp ? spaceAbove : spaceBelow) - 72))}px`;
        } else {
            assigneeMenu.classList.remove('tf-reassign-picker__menu--up');
        }
        if (!isExpanded) {
            assigneeSearch.focus();
        }
    });

    assigneeSearch.addEventListener('input', renderEmployees);

    clearAssigneesButton.addEventListener('click', () => {
        selectedEmployeeIds.clear();
        renderEmployees();
        syncSelectedEmployees();
    });

    document.addEventListener('click', event => {
        if (!assigneeMenu.hidden && !event.target.closest('#reassignAssigneePicker')) {
            assigneeMenu.hidden = true;
            assigneeToggle.setAttribute('aria-expanded', 'false');
            assigneeMenu.classList.remove('tf-reassign-picker__menu--up');
        }
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !assigneeMenu.hidden) {
            assigneeMenu.hidden = true;
            assigneeToggle.setAttribute('aria-expanded', 'false');
            assigneeMenu.classList.remove('tf-reassign-picker__menu--up');
            assigneeToggle.focus();
        }
    });

    form.addEventListener('submit', event => {
        if (selectedEmployeeIds.size > 0) {
            return;
        }

        event.preventDefault();
        assigneeError.hidden = false;
        assigneeToggle.setAttribute('aria-invalid', 'true');
        assigneeToggle.focus();
    });

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-reassign-trigger]');
        if (!trigger) return;

        event.preventDefault();

        const data = trigger.dataset;
        form.action = data.reassignAction;

        currentCode.textContent = data.taskCode || '—';
        currentTitle.textContent = data.taskTitle || '—';
        currentOrg.textContent = `${data.taskCompanyName || ''} · ${data.taskDepartmentName || ''}`;
        currentAssignee.textContent = data.taskAssigneeName || 'Unassigned';
        currentAvatar.textContent = initialsFor(data.taskAssigneeName || 'Unassigned');
        assigneeMenu.hidden = true;
        assigneeToggle.setAttribute('aria-expanded', 'false');
        assigneeMenu.classList.remove('tf-reassign-picker__menu--up');
        setEmployees([]);
        assigneeSummary.textContent = 'Select department first';
        updateNewAssigneePreview();

        if (reasonInput) {
            reasonInput.value = '';
        }

        companySelect.value = data.taskCompanyId || '';
        loadDepartments(data.taskCompanyId, data.taskDepartmentId, JSON.parse(data.taskAssigneeIds || '[]'));

        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });

    const openAssignmentModal = () => document.querySelector('[data-reassign-auto-open]')?.click();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', openAssignmentModal, { once: true });
    } else {
        openAssignmentModal();
    }
})();
</script>
