const api = window.desktopApi;
const DEFAULT_API_BASE_URL = 'https://tracker-manage.focuzacademy.in/api';
const requiredDesktopMethods = [
  'getSession',
  'getApiUrl',
  'setApiUrl',
  'login',
  'logout',
  'getMeta',
  'listTasks',
  'getTask',
  'saveTask',
  'taskAction',
  'saveSubtask',
  'toggleSubtask',
];
const elements = Object.fromEntries(
  [
    'login-view',
    'login-form',
    'login-error',
    'login-submit',
    'login-email',
    'login-password',
    'api-url',
    'error-message',
    'retry-btn',
    'app-view',
    'user-chip',
    'stats',
    'welcome-heading',
    'welcome-summary',
    'task-list-heading',
    'search',
    'filter-scope',
    'filter-company',
    'filter-department',
    'filter-assignee',
    'filter-status',
    'filter-status-group',
    'filter-priority',
    'filter-task-type',
    'filter-condition',
    'filter-sort',
    'filter-dialog',
    'filter-form',
    'filter-panel',
    'filter-toggle',
    'filter-cancel',
    'filter-cancel-footer',
    'filter-apply',
    'clear-filters',
    'create-task-btn',
    'show-overdue-tasks',
    'pagination',
    'refresh-btn',
    'logout-btn',
    'loading',
    'error',
    'board',
    'details',
    'page-label',
    'previous-page',
    'next-page',
    'task-dialog',
    'task-form',
    'task-dialog-title',
    'task-title',
    'task-company',
    'task-department',
    'task-type',
    'task-priority',
    'task-deadline',
    'task-current-deadline',
    'task-start-date',
    'task-hours',
    'task-pocs',
    'task-assignees',
    'task-output',
    'task-description',
    'task-form-error',
    'task-save',
    'task-cancel',
    'action-dialog',
    'action-form',
    'action-title',
    'action-description',
    'action-fields',
    'action-cancel',
    'action-submit',
    'subtask-dialog',
    'subtask-form',
    'subtask-dialog-title',
    'subtask-title',
    'subtask-description',
    'subtask-priority',
    'subtask-status',
    'subtask-assignee',
    'subtask-start-date',
    'subtask-due-date',
    'subtask-hours',
    'subtask-progress',
    'subtask-form-error',
    'subtask-cancel',
    'subtask-save',
    'toast',
  ].map((id) => [id.replaceAll('-', '_'), document.getElementById(id)]),
);

const state = {
  user: null,
  meta: null,
  tasks: [],
  filters: {
    search: '',
    scope: 'all',
    company_id: '',
    department_id: '',
    assigned_to: '',
    status: '',
    status_group: '',
    priority: '',
    task_type: '',
    condition: '',
    sort: 'deadline_priority',
  },
  page: 1,
  lastPage: 1,
  selectedTaskId: null,
  editingTask: null,
  quickFilter: 'all',
  counts: null,
  editingSubtask: null,
  subtaskTaskId: null,
  taskActionPending: false,
  subtaskActionPending: false,
  actionResolver: null,
};

let taskLoadSequence = 0;
let detailLoadSequence = 0;

const escapeHtml = (value) =>
  String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
  })[character]);

function responseMessage(response, fallback = 'The request could not be completed.') {
  const errors = response?.data?.errors;
  if (errors && typeof errors === 'object') {
    const firstError = Object.values(errors).flat().find((message) => typeof message === 'string');
    if (firstError) return firstError;
  }

  const messages = {
    401: 'Your session has expired. Sign in again to continue.',
    403: 'You do not have permission to perform this action.',
    404: 'This task or resource could not be found. Refresh the task list and try again.',
    409: 'This update conflicts with the latest task state. Refresh and try again.',
    422: 'Some submitted information is invalid. Review the fields and try again.',
    429: 'Too many requests. Wait a moment, then try again.',
  };
  const status = Number(response?.status);
  const serverMessage = response?.data?.message;

  if (typeof serverMessage === 'string' && serverMessage.trim()) return serverMessage;
  if (status >= 500) return 'The Laravel server could not complete this request. Try again shortly.';

  return messages[status] || fallback;
}

function showError(message) {
  elements.error_message.textContent = message;
  elements.error.hidden = false;
}

function clearError() {
  elements.error_message.textContent = '';
  elements.error.hidden = true;
}

function clearSessionState() {
  taskLoadSequence += 1;
  detailLoadSequence += 1;
  state.user = null;
  state.meta = null;
  state.tasks = [];
  state.selectedTaskId = null;
  state.quickFilter = 'all';
  state.counts = null;
  state.editingSubtask = null;
  state.subtaskTaskId = null;
  state.page = 1;
  state.lastPage = 1;
  state.filters = {
    search: '',
    scope: 'all',
    company_id: '',
    department_id: '',
    assigned_to: '',
    status: '',
    status_group: '',
    priority: '',
    task_type: '',
    condition: '',
    sort: 'deadline_priority',
  };
  elements.search.value = '';
  elements.filter_scope.value = 'all';
  elements.filter_company.value = '';
  elements.filter_department.value = '';
  elements.filter_assignee.value = '';
  elements.filter_status.value = '';
  elements.filter_status_group.value = '';
  elements.filter_priority.value = '';
  elements.filter_task_type.value = '';
  elements.filter_condition.value = '';
  elements.filter_sort.value = 'deadline_priority';
  if (elements.task_dialog.open) elements.task_dialog.close();
  if (elements.action_dialog.open) finishActionDialog(null);
  if (elements.subtask_dialog.open) elements.subtask_dialog.close();
  elements.details.hidden = true;
  elements.details.parentElement.classList.remove('has-details');
  elements.details.replaceChildren();
  elements.board.replaceChildren();
  elements.stats.replaceChildren();
  elements.loading.hidden = true;
  elements.board.removeAttribute('aria-busy');
}

function handleUnauthorized(response) {
  if (response?.status !== 401) return false;

  clearSessionState();
  showLogin('Your session expired. Please sign in again.');
  return true;
}

function showToast(message, isError = false) {
  elements.toast.textContent = message;
  elements.toast.classList.toggle('error', isError);
  elements.toast.hidden = false;
  window.setTimeout(() => {
    elements.toast.hidden = true;
  }, 3500);
}

function showLogin(message = '') {
  elements.app_view.hidden = true;
  elements.login_view.hidden = false;
  elements.login_error.textContent = message;
  elements.login_error.hidden = !message;
  elements.login_email.focus();
}

function desktopBridgeAvailable() {
  return api && requiredDesktopMethods.every((method) => typeof api[method] === 'function');
}

function showDesktopBridgeError() {
  elements.api_url.value = DEFAULT_API_BASE_URL;
  elements.login_submit.disabled = true;
  showLogin('The desktop connection is unavailable. Do not open renderer/index.html in a web browser. Launch the installed Task Management app from its desktop or Start menu shortcut; if this message appears there too, reinstall the latest Windows installer.');
}

function showApp() {
  elements.login_view.hidden = true;
  elements.app_view.hidden = false;
}

function populateOptions(select, options, placeholder) {
  const selectedValue = select.value;
  select.innerHTML = `<option value="">${escapeHtml(placeholder)}</option>${options.map((option) => {
    const value = typeof option === 'string' ? option : option.value;
    const label = typeof option === 'string' ? option : option.label;
    return `<option value="${escapeHtml(value)}">${escapeHtml(label)}</option>`;
  }).join('')}`;
  select.value = selectedValue;
}

function setMeta(meta) {
  const requiredLists = [
    'companies',
    'departments',
    'people',
    'statuses',
    'priorities',
    'task_types',
    'deadline_conditions',
    'subtask_statuses',
    'subtask_priorities',
  ];
  if (!meta || requiredLists.some((key) => !Array.isArray(meta[key]))) {
    throw new Error('Laravel returned incomplete task settings. Update the backend desktop API and retry.');
  }

  state.meta = meta;
  populateOptions(elements.filter_company, meta.companies.map(({ id, name }) => ({ value: id, label: name })), 'All companies');
  populateOptions(elements.filter_department, meta.departments.map(({ id, name }) => ({ value: id, label: name })), 'All departments');
  populateOptions(elements.filter_assignee, meta.people.map(({ id, name }) => ({ value: id, label: name })), 'All assignees');
  populateOptions(elements.filter_status, meta.statuses, 'All statuses');
  populateOptions(elements.filter_priority, meta.priorities, 'All priorities');
  populateOptions(elements.filter_task_type, meta.task_types, 'All task types');
  populateOptions(elements.filter_condition, meta.deadline_conditions, 'All deadlines');
  populateOptions(elements.task_company, meta.companies.map(({ id, name }) => ({ value: id, label: name })), 'Select company');
  populateOptions(elements.task_department, meta.departments.map(({ id, name }) => ({ value: id, label: name })), 'Select department');
  populateOptions(elements.task_type, meta.task_types, 'Select type');
  populateOptions(elements.task_priority, meta.priorities, 'Select priority');
  populateOptions(elements.task_pocs, meta.people.map(({ id, name }) => ({ value: id, label: name })), 'Select points of contact');
  populateOptions(elements.task_assignees, meta.people.map(({ id, name }) => ({ value: id, label: name })), 'Select assignees');
  populateOptions(elements.subtask_priority, meta.subtask_priorities, 'Select priority');
  populateOptions(elements.subtask_status, meta.subtask_statuses, 'Select status');
  populateOptions(elements.subtask_assignee, meta.people.map(({ id, name }) => ({ value: id, label: name })), 'Unassigned');
}

function selectedValues(select) {
  return [...select.selectedOptions].map((option) => Number(option.value)).filter(Number.isInteger);
}

function renderStats(counts) {
  const items = [
    ['All tasks', counts.total, 'all'],
    ['Overdue', counts.overdue_count, 'overdue'],
    ['Awaiting assignment', counts.pending_assignment_count, 'awaiting_assignment'],
    ['At risk', counts.at_risk_count, 'at_risk'],
    ['In progress', counts.in_progress_count, 'in_progress'],
    ['Completed', counts.completed_count, 'completed'],
  ];

  state.counts = counts;
  elements.stats.innerHTML = items.map(([label, count, filter]) => `
    <button type="button" class="quick-filter ${filter} ${state.quickFilter === filter ? 'is-active' : ''}" data-quick-filter="${filter}" aria-pressed="${state.quickFilter === filter}">
      <span class="quick-filter__dot" aria-hidden="true"></span>
      <span>${escapeHtml(label)}</span>
      <strong>${Number(count || 0).toLocaleString()}</strong>
    </button>
  `).join('');

  const overdue = Number(counts.overdue_count || 0);
  const taskTotal = Number(counts.total || 0).toLocaleString();
  elements.welcome_summary.innerHTML = overdue
    ? `${taskTotal} tasks in your workspace. <strong class="overdue-copy">${overdue.toLocaleString()} ${overdue === 1 ? 'is' : 'are'} overdue</strong> and need your attention.`
    : `${taskTotal} tasks in your workspace. No overdue tasks right now.`;
  elements.show_overdue_tasks.hidden = overdue === 0 || state.quickFilter === 'overdue';
}

function effectiveDeadline(task) {
  return task.due_date || task.original_deadline || '';
}

function deadlineMarkup(task) {
  const deadline = effectiveDeadline(task);
  const stateName = task.is_overdue ? 'overdue' : (task.is_due_today ? 'today' : (deadline ? 'upcoming' : 'none'));
  const label = deadline ? new Date(`${deadline}T00:00:00`).toLocaleDateString() : 'No deadline';
  return `<span class="deadline ${stateName}">${task.is_overdue ? 'Overdue · ' : ''}${escapeHtml(label)}</span>`;
}

function renderTasks() {
  const visibleTasks = state.tasks;
  elements.task_list_heading.textContent = state.quickFilter === 'overdue'
    ? 'Here are your overdue tasks, most urgent first.'
    : 'Here are your tasks, most urgent first.';

  if (visibleTasks.length === 0) {
    elements.board.innerHTML = '<div class="empty-state"><strong>No tasks found</strong><span>Try changing your search or filters.</span></div>';
  } else {
    elements.board.innerHTML = visibleTasks.map((task) => `
      <article class="task-row ${state.selectedTaskId === task.id ? 'selected' : ''}" data-task-id="${Number(task.id)}">
        <div class="task-row-main">
          <button type="button" class="task-row-title" data-task-intent="open">
            <span class="task-code">${escapeHtml(task.task_code || `#${task.id}`)}</span>
            <strong>${escapeHtml(task.title)}</strong>
            <span class="task-context">${escapeHtml(task.company?.name || 'No company')} · ${escapeHtml(task.department?.name || 'No department')} · ${escapeHtml(task.assignees?.map((person) => person.name).join(', ') || 'Unassigned')}</span>
          </button>
          <div class="progress-track" role="progressbar" aria-label="Task progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Math.max(0, Math.min(100, Number(task.progress_percentage || 0)))}">
            <span data-progress="${Math.max(0, Math.min(100, Number(task.progress_percentage || 0)))}"></span>
          </div>
        </div>
        <div class="task-row-side">
          <div class="task-row-meta">
            <span class="status">${escapeHtml(task.status_label || task.status || 'Unknown')}</span>
            <span class="priority ${escapeHtml(task.priority || 'medium')}">${escapeHtml(task.priority || 'Unspecified')}</span>
            ${deadlineMarkup(task)}
          </div>
          <div class="task-row-actions">
            <button type="button" class="primary" data-task-intent="open">Open</button>
          </div>
        </div>
        <span class="task-row-status">${Number(task.subtask_counts?.completed || 0)} of ${Number(task.subtask_counts?.total || 0)} subtasks complete · ${Number(task.progress_percentage || 0)}% progress</span>
      </article>
    `).join('');
    elements.board.querySelectorAll('[data-progress]').forEach((progress) => {
      progress.style.width = `${progress.dataset.progress}%`;
    });
  }

  elements.pagination.hidden = state.lastPage <= 1;
  elements.page_label.textContent = `Page ${state.page} of ${state.lastPage}`;
  elements.previous_page.disabled = state.page <= 1;
  elements.next_page.disabled = state.page >= state.lastPage;
}

async function loadTasks() {
  const sequence = ++taskLoadSequence;
  clearError();
  elements.loading.hidden = false;
  elements.board.setAttribute('aria-busy', 'true');

  try {
    const query = {
      ...state.filters,
      per_page: 25,
      page: state.page,
    };
    Object.keys(query).forEach((key) => {
      if (query[key] === '') delete query[key];
    });

    const response = await api.listTasks(query);
    if (sequence !== taskLoadSequence) return;
    if (!response.ok) {
      if (handleUnauthorized(response)) return;
      showError(responseMessage(response));
      return;
    }
    if (!Array.isArray(response.data?.data)
      || !response.data.meta
      || typeof response.data.meta !== 'object'
      || !response.data.counts
      || typeof response.data.counts !== 'object') {
      showError('Laravel returned an unexpected task-list response.');
      return;
    }

    state.tasks = response.data.data || [];
    state.page = Number(response.data.meta?.current_page || 1);
    state.lastPage = Number(response.data.meta?.last_page || 1);
    renderStats(response.data.counts || {});
    renderTasks();

    if (state.selectedTaskId && !state.tasks.some((task) => task.id === state.selectedTaskId)) {
      elements.details.hidden = true;
      elements.details.parentElement.classList.remove('has-details');
      state.selectedTaskId = null;
    }
  } catch (error) {
    if (sequence === taskLoadSequence) {
      showError(`Cannot reach Laravel. Check that the backend is running and the API URL is correct. ${error.message}`);
    }
  } finally {
    if (sequence === taskLoadSequence) {
      elements.loading.hidden = true;
      elements.board.removeAttribute('aria-busy');
    }
  }
}

function renderSubtasks(task) {
  if (!task.subtasks?.length) {
    return '<p class="muted">No subtasks yet.</p>';
  }

  return `<div class="subtask-list">${task.subtasks.map((subtask) => `
    <article class="subtask">
      <div>
        <strong>${escapeHtml(subtask.title)}</strong>
        <span>${escapeHtml(subtask.status_label)} · ${escapeHtml(subtask.priority || 'medium')} priority${subtask.due_date ? ` · Due ${escapeHtml(subtask.due_date)}` : ''}</span>
        <span>${escapeHtml(subtask.assigned_to?.name || 'Unassigned')} · ${Number(subtask.progress_percentage || 0)}% complete</span>
      </div>
      <div class="subtask-actions">
        ${task.permissions.update_subtask ? `<button class="small-button" data-edit-subtask="${Number(subtask.id)}" type="button">Edit</button>` : ''}
        ${task.permissions.change_status ? `<button class="small-button" data-toggle-subtask="${Number(subtask.id)}" type="button">${subtask.is_completed ? 'Reopen' : 'Complete'}</button>` : ''}
      </div>
    </article>
  `).join('')}</div>`;
}

function renderHistory(task) {
  if (!task.history?.length) return '<p class="muted">No task activity recorded.</p>';
  return `<ol class="history-list">${task.history.map((event) => `
    <li><strong>${escapeHtml(event.changed_by || 'System')}</strong> · ${escapeHtml(event.remarks || event.event_type)}<time>${escapeHtml(event.created_at || '')}</time></li>
  `).join('')}</ol>`;
}

function allowedStatusOptions(task) {
  return Object.entries(task.status_options || {}).filter(([status]) => {
    if (status === 'cancelled') return Boolean(task.permissions.cancel);
    if (status === 'submitted_for_review') return Boolean(task.permissions.submit_for_review);
    return Boolean(task.permissions.change_status);
  });
}

function renderActions(task) {
  const actions = [];
  const add = (action, label, primary = false) => actions.push({ action, label, primary });
  if (task.permissions.accept) add('accept', 'Accept task', true);
  if (task.permissions.start_work) add('start', 'Start work', true);
  if (task.permissions.submit_for_review) add('submit-review', 'Submit for review', true);
  if (task.permissions.review) { add('approve', 'Approve & complete', true); add('rework', 'Request changes'); }
  if (task.permissions.resume) add('resume', 'Resume work', true);
  if (task.permissions.add_work_update) add('work-update', 'Add update');
  if (task.permissions.flag_risk) add(task.risk_reason ? 'resolve-risk' : 'flag-risk', task.risk_reason ? 'Resolve risk' : 'Flag risk');
  const primary = actions.find((item) => item.primary);
  const visible = [primary, ...actions.filter((item) => item !== primary)].filter(Boolean).slice(0, 3);
  const statusOptions = allowedStatusOptions(task);
  const buttons = visible.map((item) => `<button type="button" class="tf-wf-action-btn ${item === primary ? 'tf-wf-action-btn--primary' : ''}" data-action="${item.action}">${escapeHtml(item.label)}</button>`);
  if (statusOptions.length > 0) {
    buttons.push(`<label class="status-action">More actions
      <select data-status-select>
        <option value="">Select status</option>
        ${statusOptions.map(([value, label]) => `<option value="${escapeHtml(value)}">${escapeHtml(label)}</option>`).join('')}
      </select>
    </label>`);
  }

  return buttons.join('') || '<span class="workflow-waiting">Waiting for the next workflow action.</span>';
}

function renderWorkflow(task) {
  const steps = [['notified', 'Notified'], ['unassigned', 'Unassigned'], ['assigned', 'Assigned'], ['in_progress', 'In progress'], ['submitted_for_review', 'Review'], ['completed', 'Completed']];
  const current = steps.findIndex(([status]) => status === task.status);
  const notice = task.status === 'on_hold' ? '<p class="workflow-banner">On hold — waiting to resume.</p>' : (task.status === 'cancelled' ? '<p class="workflow-banner is-cancelled">This task has been cancelled.</p>' : '');
  return `<section class="workflow-card"><div class="workflow-card__head"><strong>Workflow</strong><div class="detail-actions">${renderActions(task)}</div></div>${notice}<ol class="workflow-stepper">${steps.map(([status, label], index) => `<li class="${index < current ? 'done' : ''} ${index === current ? 'current' : ''}"><i>${index < current ? '✓' : ''}</i><span>${label}</span></li>`).join('')}</ol></section>`;
}

async function openTask(taskId, intent = 'open') {
  const sequence = ++detailLoadSequence;
  state.selectedTaskId = Number(taskId);
  renderTasks();
  elements.details.hidden = false;
  elements.details.parentElement.classList.add('has-details');
  elements.details.innerHTML = '<div class="detail-loading">Loading task details…</div>';

  try {
    const response = await api.getTask(state.selectedTaskId);
    if (sequence !== detailLoadSequence) return;
    if (!response.ok) {
      if (handleUnauthorized(response)) return;
      elements.details.innerHTML = `<p class="form-error">${escapeHtml(responseMessage(response))}</p><button type="button" class="ghost" data-retry-detail>Retry</button>`;
      elements.details.querySelector('[data-retry-detail]')?.addEventListener('click', () => openTask(taskId, intent));
      return;
    }

    if (!response.data?.task
      || !response.data.task.permissions
      || !Array.isArray(response.data.task.subtasks)
      || !response.data.task.status_options) {
      elements.details.innerHTML = '<p class="form-error">Laravel returned incomplete task details. Update the backend desktop API and retry.</p><button type="button" class="ghost" data-retry-detail>Retry</button>';
      elements.details.querySelector('[data-retry-detail]')?.addEventListener('click', () => openTask(taskId, intent));
      return;
    }

    renderTaskDetail(response.data.task, intent);
  } catch (error) {
    if (sequence === detailLoadSequence) {
      elements.details.innerHTML = `<p class="form-error">Unable to load task details. ${escapeHtml(error.message)}</p><button type="button" class="ghost" data-retry-detail>Retry</button>`;
      elements.details.querySelector('[data-retry-detail]')?.addEventListener('click', () => openTask(taskId, intent));
    }
  }
}

function renderTaskDetail(task, intent = 'open') {
  elements.details.innerHTML = `
    <div class="detail-header">
      <div><span class="task-code">${escapeHtml(task.task_code)}</span><h2>${escapeHtml(task.title)}</h2></div>
      <button type="button" class="icon-button" data-close-details aria-label="Close task details">×</button>
    </div>
    <p class="detail-context">${escapeHtml(task.company?.name || '—')} · ${escapeHtml(task.department?.name || '—')}</p>
    ${renderWorkflow(task)}
    <div class="detail-grid">
      <div><span>Status</span><strong>${escapeHtml(task.status_label)}</strong></div>
      <div><span>Priority</span><strong class="priority ${escapeHtml(task.priority || 'medium')}">${escapeHtml(task.priority || 'Unspecified')}</strong></div>
      <div><span>Company</span><strong>${escapeHtml(task.company?.name || '—')}</strong></div>
      <div><span>Department</span><strong>${escapeHtml(task.department?.name || '—')}</strong></div>
      <div><span>Deadline</span><strong>${deadlineMarkup(task)}</strong></div>
      <div><span>Task type</span><strong>${escapeHtml(task.task_type || '—')}</strong></div>
      <div><span>Estimated effort</span><strong>${task.estimated_hours ? `${escapeHtml(task.estimated_hours)} hours` : '—'}</strong></div>
      <div><span>Started</span><strong>${escapeHtml(task.start_date || '—')}</strong></div>
      <div><span>Points of contact</span><strong>${escapeHtml(task.pocs?.map((person) => person.name).join(', ') || '—')}</strong></div>
      <div><span>Assignees</span><strong>${escapeHtml(task.assignees?.map((person) => person.name).join(', ') || 'Unassigned')}</strong></div>
      <div><span>Progress</span><strong>${Number(task.progress_percentage || 0)}%</strong></div>
      <div><span>Subtasks complete</span><strong>${Number(task.subtask_counts?.completed || 0)} / ${Number(task.subtask_counts?.total || 0)}</strong></div>
    </div>
    <div class="detail-actions">${task.permissions.update ? '<button type="button" class="ghost" data-edit-task>Edit task</button>' : ''}</div>
    <section class="detail-section"><h3>Description</h3><p class="detail-description">${escapeHtml(task.description || 'No description provided.')}</p></section>
    <section class="detail-section">
      <div class="section-heading"><h3>Subtasks</h3>${task.permissions.create_subtask ? '<button type="button" class="small-button" data-add-subtask>Add subtask</button>' : ''}</div>
      ${renderSubtasks(task)}
    </section>
    <section class="detail-section"><h3>Expected output</h3><p>${escapeHtml(task.expected_output || '—')}</p></section>
    ${task.hold_reason ? `<section class="detail-section"><h3>Hold reason</h3><p>${escapeHtml(task.hold_reason)}</p></section>` : ''}
    ${task.risk_reason ? `<section class="detail-section"><h3>Risk note</h3><p>${escapeHtml(task.risk_reason)}</p></section>` : ''}
    <section class="detail-section"><h3>Recent activity</h3>${renderHistory(task)}</section>
  `;

  elements.details.querySelector('[data-close-details]')?.addEventListener('click', () => {
    elements.details.hidden = true;
    elements.details.parentElement.classList.remove('has-details');
    state.selectedTaskId = null;
    renderTasks();
  });
  elements.details.querySelector('[data-edit-task]')?.addEventListener('click', () => openTaskDialog(task));
  elements.details.querySelector('[data-add-subtask]')?.addEventListener('click', () => createSubtask(task));
  elements.details.querySelectorAll('[data-toggle-subtask]').forEach((button) => {
    button.addEventListener('click', () => toggleSubtask(task, Number(button.dataset.toggleSubtask)));
  });
  elements.details.querySelectorAll('[data-edit-subtask]').forEach((button) => {
    button.addEventListener('click', () => editSubtask(task, Number(button.dataset.editSubtask)));
  });
  elements.details.querySelectorAll('[data-edit-subtask]').forEach((button) => {
    button.addEventListener('click', () => editSubtask(task, Number(button.dataset.editSubtask)));
  });
  elements.details.querySelectorAll('[data-action]').forEach((button) => {
    button.addEventListener('click', () => performAction(task, button.dataset.action));
  });
  elements.details.querySelector('[data-status-select]')?.addEventListener('change', (event) => {
    if (event.target.value) changeStatus(task, event.target.value);
  });

  if (intent === 'reassign') {
    if (task.permissions.update) {
      openTaskDialog(task);
    } else {
      showToast('You do not have permission to re-assign this task.', true);
    }
  } else if (intent === 'status') {
    const statusSelect = elements.details.querySelector('[data-status-select]');
    if (statusSelect) {
      statusSelect.focus();
      statusSelect.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } else {
      showToast('There are no status changes available for this task.', true);
    }
  }
}

function localDateString() {
  const date = new Date();
  const offset = date.getTimezoneOffset() * 60_000;
  return new Date(date.getTime() - offset).toISOString().slice(0, 10);
}

function actionFields(action, task, status = '') {
  const date = localDateString();
  const field = (name, label, type, required = false, value = '', maxLength = '') => {
    const requiredAttribute = required ? 'required' : '';
    const maxLengthAttribute = maxLength ? `maxlength="${maxLength}"` : '';
    if (type === 'textarea') {
      return `<label class="wide">${escapeHtml(label)}<textarea name="${name}" ${requiredAttribute} ${maxLengthAttribute}>${escapeHtml(value)}</textarea></label>`;
    }

    return `<label>${escapeHtml(label)}<input name="${name}" type="${type}" value="${escapeHtml(value)}" ${requiredAttribute} ${maxLengthAttribute}></label>`;
  };

  if (action === 'start') {
    return field('note', 'Starting work note', 'textarea', true, '', 2000)
      + field('start_date', 'Start date', 'date', true, date);
  }
  if (action === 'work-update') {
    return field('note', 'Work update', 'textarea', true, '', 5000)
      + field('update_date', 'Update date', 'date', true, date);
  }
  if (action === 'resume') return field('note', 'Resume note (optional)', 'textarea', false, '', 2000);
  if (action === 'flag-risk') return field('reason', 'Why is this task at risk?', 'textarea', true, '', 2000);
  if (action === 'submit-review') {
    return field('reason', 'Submission note', 'textarea', true, '', 2000)
      + field('update_date', 'Submission date', 'date', true, date);
  }
  if (action === 'approve' || action === 'rework') {
    return field('comment', 'Review comment (optional)', 'textarea', false, '', 2000);
  }
  if (action === 'status') {
    const fields = [];
    if (['on_hold', 'cancelled', 'submitted_for_review'].includes(status)) {
      fields.push(field('reason', `${task.status_options[status]} reason`, 'textarea', true, '', 2000));
    }
    if (['on_hold', 'submitted_for_review'].includes(status)) {
      fields.push(field('update_date', 'Action date', 'date', true, date));
    }
    return fields.join('');
  }
  return '';
}

function requestActionDetails(action, task, status = '') {
  const labels = {
    start: ['Start work', 'Record the note and date for starting this task.'],
    'work-update': ['Add work update', 'Add a dated progress update to this task.'],
    resume: ['Resume work', 'Optionally add a note before resuming this task.'],
    'flag-risk': ['Flag task at risk', 'Provide a reason so your team understands the risk.'],
    'resolve-risk': ['Resolve task risk', 'Confirm that the risk flag should be cleared.'],
    'submit-review': ['Submit for review', 'Add a submission note and date.'],
    approve: ['Approve task', 'Confirm the review decision.'],
    rework: ['Return for re-work', 'Optionally add a note explaining the rework.'],
    status: [task.status_options[status] || 'Change task status', 'Confirm this workflow transition.'],
  };
  return labels[action] || ['Update task', 'Confirm this task update.'];
}

function showActionDialog(action, task, status = '') {
  const [title, description] = requestActionDetails(action, task, status);
  elements.action_title.textContent = title;
  elements.action_description.textContent = description;
  elements.action_fields.innerHTML = actionFields(action, task, status);
  elements.action_submit.textContent = action === 'approve' ? 'Approve task' : 'Continue';
  return new Promise((resolve) => {
    state.actionResolver = resolve;
    elements.action_dialog.showModal();
    elements.action_fields.querySelector('textarea, input')?.focus();
  });
}

function finishActionDialog(value) {
  const resolve = state.actionResolver;
  state.actionResolver = null;
  if (elements.action_dialog.open) elements.action_dialog.close();
  resolve?.(value);
}

async function performAction(task, action) {
  const body = {};
  const formValues = await showActionDialog(action, task);
  if (!formValues) return;
  Object.assign(body, formValues);

  const apiAction = action === 'work-update' ? 'work-updates' : (
    action === 'submit-review' ? 'status' : (
      action === 'flag-risk' || action === 'resolve-risk' ? 'risk' : (
        action === 'approve' || action === 'rework' ? 'review' : action
      )
    )
  );
  if (action === 'resolve-risk') body.action = 'resolve';
  if (action === 'flag-risk') body.action = 'flag';
  if (action === 'submit-review') body.status = 'submitted_for_review';
  if (action === 'approve' || action === 'rework') {
    body.decision = action === 'approve' ? 'approve' : 'rework';
  }

  await runTaskAction(task.id, apiAction, body);
}

async function changeStatus(task, status) {
  const body = { status };
  const formValues = await showActionDialog('status', task, status);
  if (!formValues) return;
  Object.assign(body, formValues);
  await runTaskAction(task.id, 'status', body);
}

async function runTaskAction(taskId, action, body) {
  if (state.taskActionPending) return;
  state.taskActionPending = true;
  elements.details.querySelectorAll('[data-action], [data-status-select], [data-edit-task]').forEach((button) => {
    button.disabled = true;
  });

  try {
    const response = await api.taskAction({ taskId, action, body });
    if (!response.ok) {
      if (handleUnauthorized(response)) return;
      showToast(responseMessage(response), true);
      return;
    }
    if (!response.data?.task || Number(response.data.task.id) !== Number(taskId)) {
      showToast('Laravel did not confirm the task update. Refresh the task before retrying.', true);
      await loadTasks();
      await openTask(taskId);
      return;
    }

    showToast(response.data.message || 'Task updated.');
    await loadTasks();
    await openTask(taskId);
  } catch (error) {
    showToast(`Could not contact Laravel. ${error.message}`, true);
  } finally {
    state.taskActionPending = false;
  }
}

async function createSubtask(task) {
  openSubtaskDialog(task);
}

function editSubtask(task, subtaskId) {
  const subtask = task.subtasks?.find((item) => Number(item.id) === subtaskId);
  if (!subtask) {
    showToast('This subtask is no longer available. Refresh the task and try again.', true);
    return;
  }
  openSubtaskDialog(task, subtask);
}

function openSubtaskDialog(task, subtask = null) {
  state.subtaskTaskId = task.id;
  state.editingSubtask = subtask;
  elements.subtask_dialog_title.textContent = subtask ? 'Edit subtask' : 'Add a subtask';
  elements.subtask_form_error.hidden = true;
  elements.subtask_title.value = subtask?.title || '';
  elements.subtask_description.value = subtask?.description || '';
  elements.subtask_priority.value = subtask?.priority || 'medium';
  elements.subtask_status.value = subtask?.status || 'todo';
  elements.subtask_assignee.value = subtask?.assigned_to?.id || '';
  elements.subtask_assignee.disabled = Boolean(subtask && !task.permissions.update);
  elements.subtask_start_date.value = subtask?.start_date || '';
  elements.subtask_due_date.value = subtask?.due_date || '';
  elements.subtask_hours.value = subtask?.estimated_hours || '';
  elements.subtask_progress.value = Number(subtask?.progress_percentage || 0);
  elements.subtask_save.disabled = false;
  elements.subtask_dialog.showModal();
  elements.subtask_title.focus();
}

async function saveSubtask(event) {
  event.preventDefault();
  elements.subtask_save.disabled = true;
  elements.subtask_form_error.hidden = true;
  const taskId = state.subtaskTaskId;
  const body = {
    title: elements.subtask_title.value.trim(),
    description: elements.subtask_description.value.trim() || null,
    priority: elements.subtask_priority.value,
    status: elements.subtask_status.value,
    assigned_to: elements.subtask_assignee.value ? Number(elements.subtask_assignee.value) : null,
    start_date: elements.subtask_start_date.value || null,
    due_date: elements.subtask_due_date.value || null,
    estimated_hours: elements.subtask_hours.value ? Number(elements.subtask_hours.value) : null,
    progress_percentage: Number(elements.subtask_progress.value || 0),
  };

  try {
    const response = await api.saveSubtask({
      taskId,
      subtaskId: state.editingSubtask?.id,
      body,
    });
    if (!response.ok) {
      if (handleUnauthorized(response)) return;
      elements.subtask_form_error.textContent = responseMessage(response);
      elements.subtask_form_error.hidden = false;
      return;
    }
    if (!response.data?.ok || !response.data.subtask?.id) {
      elements.subtask_form_error.textContent = 'Laravel did not confirm the subtask update. Refresh the task before retrying.';
      elements.subtask_form_error.hidden = false;
      await loadTasks();
      await openTask(taskId);
      return;
    }

    elements.subtask_dialog.close();
    showToast(state.editingSubtask ? 'Subtask updated.' : 'Subtask added.');
    await loadTasks();
    await openTask(taskId);
  } catch (error) {
    elements.subtask_form_error.textContent = `Could not save subtask. ${error.message}`;
    elements.subtask_form_error.hidden = false;
  } finally {
    elements.subtask_save.disabled = false;
  }
}

async function toggleSubtask(task, subtaskId) {
  if (state.subtaskActionPending) return;
  state.subtaskActionPending = true;
  elements.details.querySelectorAll('[data-toggle-subtask], [data-edit-subtask]').forEach((button) => {
    button.disabled = true;
  });

  try {
    const response = await api.toggleSubtask({ taskId: task.id, subtaskId });
    if (!response.ok) {
      if (handleUnauthorized(response)) return;
      showToast(responseMessage(response), true);
      return;
    }
    if (!response.data?.ok || !response.data.subtask?.id) {
      showToast('Laravel did not confirm the subtask status update. Refresh the task before retrying.', true);
      await loadTasks();
      await openTask(task.id);
      return;
    }

    showToast('Subtask status updated.');
    await loadTasks();
    await openTask(task.id);
  } catch (error) {
    showToast(`Could not update subtask. ${error.message}`, true);
  } finally {
    state.subtaskActionPending = false;
  }
}

function openTaskDialog(task = null) {
  state.editingTask = task;
  elements.task_dialog_title.textContent = task ? 'Edit task' : 'New task';
  elements.task_form_error.hidden = true;
  elements.task_title.value = task?.title || '';
  elements.task_company.value = task?.company?.id || '';
  elements.task_department.value = task?.department?.id || '';
  elements.task_type.value = task?.task_type || '';
  elements.task_priority.value = task?.priority || '';
  elements.task_deadline.value = task?.original_deadline || '';
  elements.task_deadline.disabled = Boolean(task);
  elements.task_current_deadline.value = task?.due_date || '';
  elements.task_start_date.value = task?.start_date || '';
  elements.task_hours.value = task?.estimated_hours || '';
  elements.task_output.value = task?.expected_output || '';
  elements.task_description.value = task?.description || '';
  [...elements.task_pocs.options].forEach((option) => {
    option.selected = (task?.pocs || []).some((poc) => Number(poc.id) === Number(option.value));
  });
  [...elements.task_assignees.options].forEach((option) => {
    option.selected = (task?.assignees || []).some((assignee) => Number(assignee.id) === Number(option.value));
  });
  elements.task_dialog.showModal();
}

async function saveTask(event) {
  event.preventDefault();
  elements.task_form_error.hidden = true;
  elements.task_save.disabled = true;
  const body = {
    title: elements.task_title.value.trim(),
    company_id: Number(elements.task_company.value),
    department_id: Number(elements.task_department.value),
    task_type: elements.task_type.value,
    priority: elements.task_priority.value,
    assigned_to: selectedValues(elements.task_assignees),
    poc_ids: selectedValues(elements.task_pocs),
    due_date: elements.task_current_deadline.value || null,
    start_date: elements.task_start_date.value || null,
    estimated_hours: Number(elements.task_hours.value),
    expected_output: elements.task_output.value.trim(),
    description: elements.task_description.value.trim() || null,
  };

  if (!state.editingTask) {
    body.original_deadline = elements.task_deadline.value || null;
  }

  try {
    const response = await api.saveTask(state.editingTask ? { id: state.editingTask.id, ...body } : body);
    if (!response.ok) {
      if (handleUnauthorized(response)) return;
      elements.task_form_error.textContent = responseMessage(response);
      elements.task_form_error.hidden = false;
      return;
    }
    if (!response.data?.task?.id) {
      elements.task_form_error.textContent = 'Laravel did not confirm the task save. Refresh the task list before retrying.';
      elements.task_form_error.hidden = false;
      await loadTasks();
      return;
    }

    const taskId = response.data.task.id;
    elements.task_dialog.close();
    showToast(response.data.message || 'Task saved.');
    state.page = 1;
    await loadTasks();
    if (taskId) await openTask(taskId);
  } catch (error) {
    elements.task_form_error.textContent = `Could not save task. ${error.message}`;
    elements.task_form_error.hidden = false;
  } finally {
    elements.task_save.disabled = false;
  }
}

function bindEvents() {
  const passwordToggle = document.querySelector('[data-password-toggle]');
  passwordToggle?.addEventListener('click', () => {
    const reveal = elements.login_password.type === 'password';
    elements.login_password.type = reveal ? 'text' : 'password';
    passwordToggle.textContent = reveal ? 'Hide' : 'Show';
    passwordToggle.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
    passwordToggle.setAttribute('aria-pressed', String(reveal));
  });

  elements.login_form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!desktopBridgeAvailable()) {
      showDesktopBridgeError();
      return;
    }

    elements.login_submit.disabled = true;
    elements.login_submit.textContent = 'Signing in…';
    elements.login_error.hidden = true;

    try {
      const config = await api.setApiUrl(elements.api_url.value.trim());
      if (!config.ok) {
        showLogin(config.message);
        return;
      }

      const response = await api.login(elements.login_email.value.trim(), elements.login_password.value);
      if (!response.ok) {
        showLogin(responseMessage(response, 'Sign in failed.'));
        return;
      }

      elements.login_password.value = '';
      elements.login_password.type = 'password';
      if (passwordToggle) {
        passwordToggle.textContent = 'Show';
        passwordToggle.setAttribute('aria-label', 'Show password');
        passwordToggle.setAttribute('aria-pressed', 'false');
      }
      await startSession(response.data.user);
    } catch (error) {
      showLogin(`Cannot connect to Laravel. ${error.message}`);
    } finally {
      elements.login_submit.disabled = false;
      elements.login_submit.textContent = 'Sign in';
    }
  });

  elements.logout_btn.addEventListener('click', async () => {
    try {
      const response = await api.logout();
      if (response.warning) showToast(response.warning, true);
    } catch (error) {
      showToast(`Could not complete sign out cleanly. ${error.message}`, true);
    } finally {
      clearSessionState();
      showLogin();
    }
  });

  elements.refresh_btn.addEventListener('click', loadTasks);
  elements.retry_btn.addEventListener('click', () => {
    if (state.user) startSession(state.user);
    else loadTasks();
  });
  elements.create_task_btn.addEventListener('click', () => openTaskDialog());
  elements.filter_toggle.addEventListener('click', () => {
    syncFilterControls();
    elements.filter_dialog.showModal();
    elements.filter_toggle.setAttribute('aria-expanded', 'true');
  });
  elements.filter_dialog.addEventListener('close', () => {
    elements.filter_toggle.setAttribute('aria-expanded', 'false');
    elements.filter_toggle.focus();
  });
  elements.filter_cancel.addEventListener('click', () => elements.filter_dialog.close());
  elements.filter_cancel_footer.addEventListener('click', () => elements.filter_dialog.close());
  elements.show_overdue_tasks.addEventListener('click', () => applyQuickFilter('overdue'));
  elements.filter_form.addEventListener('submit', (event) => {
    event.preventDefault();
    applyFilterControls();
    elements.filter_dialog.close();
  });
  elements.search.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      window.clearTimeout(searchTimeout);
      state.filters.search = elements.search.value.trim();
      state.page = 1;
      loadTasks();
    }
  });
  elements.stats.addEventListener('click', (event) => {
    const button = event.target.closest('[data-quick-filter]');
    if (button) applyQuickFilter(button.dataset.quickFilter);
  });
  elements.clear_filters.addEventListener('click', () => {
    Object.assign(state.filters, {
      search: '',
      scope: 'all',
      company_id: '',
      department_id: '',
      assigned_to: '',
      status: '',
      status_group: '',
      priority: '',
      task_type: '',
      condition: '',
      sort: 'deadline_priority',
    });
    state.quickFilter = 'all';
    state.page = 1;
    elements.search.value = '';
    elements.filter_scope.value = 'all';
    elements.filter_company.value = '';
    elements.filter_department.value = '';
    elements.filter_assignee.value = '';
    elements.filter_status.value = '';
    elements.filter_status_group.value = '';
    elements.filter_priority.value = '';
    elements.filter_task_type.value = '';
    elements.filter_condition.value = '';
    elements.filter_sort.value = 'deadline_priority';
    syncFilterControls();
    elements.filter_dialog.close();
    renderStats(state.counts || {});
    loadTasks();
  });
  elements.task_form.addEventListener('submit', saveTask);
  elements.task_cancel.addEventListener('click', () => elements.task_dialog.close());
  elements.action_form.addEventListener('submit', (event) => {
    event.preventDefault();
    const values = Object.fromEntries(new FormData(elements.action_form).entries());
    finishActionDialog(values);
  });
  elements.action_cancel.addEventListener('click', () => finishActionDialog(null));
  elements.action_dialog.addEventListener('cancel', (event) => {
    event.preventDefault();
    finishActionDialog(null);
  });
  elements.subtask_form.addEventListener('submit', saveSubtask);
  elements.subtask_cancel.addEventListener('click', () => elements.subtask_dialog.close());
  elements.subtask_dialog.addEventListener('close', () => {
    state.editingSubtask = null;
    state.subtaskTaskId = null;
  });
  elements.board.addEventListener('click', (event) => {
    const action = event.target.closest('[data-task-intent]');
    if (action) {
      const card = action.closest('[data-task-id]');
      if (card) openTask(Number(card.dataset.taskId), action.dataset.taskIntent);
    }
  });
  elements.previous_page.addEventListener('click', () => {
    if (state.page > 1) {
      state.page -= 1;
      loadTasks();
    }
  });
  elements.next_page.addEventListener('click', () => {
    if (state.page < state.lastPage) {
      state.page += 1;
      loadTasks();
    }
  });

  let searchTimeout;
  elements.search.addEventListener('input', () => {
    window.clearTimeout(searchTimeout);
    searchTimeout = window.setTimeout(() => {
      if (state.filters.search === elements.search.value.trim()) return;
      state.filters.search = elements.search.value.trim();
      state.page = 1;
      loadTasks();
    }, 300);
  });
  elements.filter_dialog.addEventListener('cancel', (event) => {
    event.preventDefault();
    elements.filter_dialog.close();
  });
}

const FILTER_CONTROLS = [
  ['filter_scope', 'scope'],
  ['filter_company', 'company_id'],
  ['filter_department', 'department_id'],
  ['filter_assignee', 'assigned_to'],
  ['filter_status', 'status'],
  ['filter_status_group', 'status_group'],
  ['filter_priority', 'priority'],
  ['filter_task_type', 'task_type'],
  ['filter_condition', 'condition'],
  ['filter_sort', 'sort'],
];

function syncFilterControls() {
  FILTER_CONTROLS.forEach(([elementName, filterName]) => {
    elements[elementName].value = state.filters[filterName] || '';
  });
}

function applyFilterControls() {
  FILTER_CONTROLS.forEach(([elementName, filterName]) => {
    state.filters[filterName] = elements[elementName].value;
  });
  state.quickFilter = state.filters.status_group === 'awaiting_assignment'
    ? 'awaiting_assignment'
    : (state.filters.status === '' && state.filters.condition === '' ? 'all' : 'custom');
  state.page = 1;
  renderStats(state.counts || {});
  loadTasks();
}

function applyQuickFilter(filter) {
  const quickFilters = {
    all: { status: '', status_group: '', condition: '' },
    overdue: { status: '', status_group: '', condition: 'overdue' },
    awaiting_assignment: { status: '', status_group: 'awaiting_assignment', condition: '' },
    at_risk: { status: '', status_group: '', condition: 'at_risk' },
    in_progress: { status: 'in_progress', status_group: '', condition: '' },
    completed: { status: 'completed', status_group: '', condition: '' },
  };
  if (!quickFilters[filter]) return;

  state.quickFilter = filter;
  Object.assign(state.filters, quickFilters[filter]);
  syncFilterControls();
  state.page = 1;
  renderStats(state.counts || {});
  loadTasks();
}

async function startSession(user = null) {
  if (user) state.user = user;
  if (!state.user) {
    showLogin();
    return;
  }

  showApp();
  const hour = new Date().getHours();
  const greeting = hour < 12 ? 'Good morning' : (hour < 18 ? 'Good afternoon' : 'Good evening');
  elements.welcome_heading.textContent = `${greeting}, ${state.user.name}`;
  elements.user_chip.textContent = `${state.user.name} · ${state.user.role || 'User'}`;
  elements.create_task_btn.hidden = !state.user.can?.create_task;

  try {
    const response = await api.getMeta();
    if (!response.ok) {
      if (handleUnauthorized(response)) {
        return;
      }
      showError(responseMessage(response, 'Unable to load task settings.'));
      return;
    }

    setMeta(response.data);
    state.user = response.data.user || state.user;
    elements.welcome_heading.textContent = `${greeting}, ${state.user.name}`;
    elements.user_chip.textContent = `${state.user.name} · ${state.user.role || 'User'}`;
    elements.create_task_btn.hidden = !state.user.can?.create_task;
    await loadTasks();
  } catch (error) {
    showError(`Unable to load task settings. Check the API connection and desktop API response. ${error.message}`);
  }
}

async function initialize() {
  bindEvents();

  if (!desktopBridgeAvailable()) {
    showDesktopBridgeError();
    return;
  }

  try {
    const config = await api.getApiUrl();
    if (config.ok) elements.api_url.value = config.apiBaseUrl;
    else elements.api_url.value = DEFAULT_API_BASE_URL;

    if (!config.ok) {
      showLogin(`${config.message} Enter the Laravel API base URL, such as https://tracker-manage.focuzacademy.in/api.`);
      return;
    }

    const session = await api.getSession();
    if (session.authenticated) {
      await startSession(session.user);
    } else {
      showLogin(session.error || '');
    }
  } catch (error) {
    showLogin(`Could not initialize the desktop app. ${error.message}`);
  }
}

initialize();
