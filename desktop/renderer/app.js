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
  'requestDeadlineExtension',
  'reviewDeadlineExtension',
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
    'home-intro',
    'task-list-section',
    'task-detail-page',
    'back-to-tasks',
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
    'extension-dialog',
    'extension-form',
    'extension-deadline',
    'extension-reason',
    'extension-form-error',
    'extension-submit',
    'extension-cancel',
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
  taskMenuTrigger: null,
  taskMenuTask: null,
  activeTaskTab: 'activity',
  currentTask: null,
  extensionReviewPending: false,
};

let taskLoadSequence = 0;
let detailLoadSequence = 0;
let taskMenuSequence = 0;

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
  state.currentTask = null;
  state.activeTaskTab = 'activity';
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
  closeTaskMenu();
  showTaskDetailPage(false);
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
    ['All tasks', counts.total, 'all', '✓'],
    ['Overdue', counts.overdue_count, 'overdue', '!'],
    ['Awaiting assignment', counts.pending_assignment_count, 'awaiting_assignment', '↗'],
    ['At risk', counts.at_risk_count, 'at_risk', '△'],
    ['In progress', counts.in_progress_count, 'in_progress', '◷'],
    ['Completed', counts.completed_count, 'completed', '✓'],
  ];

  state.counts = counts;
  elements.stats.innerHTML = items.map(([label, count, filter, icon]) => `
    <button type="button" class="quick-filter ${filter} ${state.quickFilter === filter ? 'is-active' : ''}" data-quick-filter="${filter}" aria-pressed="${state.quickFilter === filter}">
      <span class="quick-filter__icon" aria-hidden="true">${icon}</span>
      <span class="quick-filter__label">${escapeHtml(label)}</span>
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
            <span class="task-row-icon" aria-hidden="true">✓</span>
            <span class="task-row-copy">
              <span class="task-code">${escapeHtml(task.task_code || `#${task.id}`)}</span>
              <strong>${escapeHtml(task.title)}</strong>
              <span class="task-context">${escapeHtml(task.company?.name || 'No company')} · ${escapeHtml(task.department?.name || 'No department')} · ${escapeHtml(task.assignees?.map((person) => person.name).join(', ') || 'Unassigned')}</span>
            </span>
          </button>
          <div class="task-row-progress">
            <div class="progress-track" role="progressbar" aria-label="Task progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Math.max(0, Math.min(100, Number(task.progress_percentage || 0)))}">
              <span data-progress="${Math.max(0, Math.min(100, Number(task.progress_percentage || 0)))}"></span>
            </div>
            <span class="task-row-status">${Number(task.subtask_counts?.completed || 0)} of ${Number(task.subtask_counts?.total || 0)} subtasks complete · ${Number(task.progress_percentage || 0)}% progress</span>
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
            <button type="button" class="task-row-menu-trigger" data-task-menu-trigger aria-label="More task options" aria-haspopup="menu" aria-expanded="false" title="More task options">···</button>
          </div>
        </div>
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
  if (state.taskMenuTrigger) closeTaskMenu();
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
      showTaskDetailPage(false);
      renderTasks();
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
    return '<p class="task-empty-state">No subtasks have been added.</p>';
  }

  return `<div class="subtask-list">${task.subtasks.map((subtask) => `
    <article class="subtask">
      <div>
        <strong>${escapeHtml(subtask.title)}</strong>
        <span>${escapeHtml(subtask.status_label)} · ${escapeHtml(subtask.assigned_to?.name || 'Unassigned')}${subtask.due_date ? ` · Due ${escapeHtml(formatDate(subtask.due_date))}` : ''}</span>
        <div class="subtask-progress"><span><i style="width:${Math.max(0, Math.min(100, Number(subtask.progress_percentage || 0)))}%"></i></span><small>${Number(subtask.progress_percentage || 0)}%</small></div>
      </div>
      <div class="subtask-actions">
        ${task.permissions.update_subtask ? `<button class="small-button" data-edit-subtask="${Number(subtask.id)}" type="button">Edit</button>` : ''}
        ${task.permissions.change_status ? `<button class="small-button" data-toggle-subtask="${Number(subtask.id)}" type="button">${subtask.is_completed ? 'Reopen' : 'Complete'}</button>` : ''}
      </div>
    </article>
  `).join('')}</div>`;
}

function formatDate(value, options = { year: 'numeric', month: 'short', day: 'numeric' }) {
  if (!value) return '—';
  const dateValue = /^\d{4}-\d{2}-\d{2}$/.test(String(value)) ? `${value}T00:00:00` : value;
  const date = new Date(dateValue);
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString(undefined, options);
}

function renderActivity(task) {
  const composer = task.permissions.add_work_update ? `
    <form class="activity-composer" data-activity-form>
      <textarea name="note" maxlength="5000" placeholder="Add a comment or progress note..." aria-label="Activity note" required></textarea>
      <button type="submit" class="primary" data-activity-submit>Send</button>
    </form>
  ` : '';
  const history = task.history?.length
    ? `<ol class="task-activity-list">${task.history.map((item) => `
        <li class="task-activity-item">
          <span class="task-activity-marker" aria-hidden="true">${item.event_type === 'work_update' ? '≡' : (item.event_type?.includes('deadline_extension') ? '↗' : '•')}</span>
          <div><strong>${escapeHtml(item.remarks || item.event_type || 'Task updated')}</strong><span>${escapeHtml(item.changed_by || 'System')}</span></div>
          <time>${escapeHtml(formatDate(item.created_at))}</time>
        </li>
      `).join('')}</ol>`
    : '<p class="task-empty-state">No activity has been recorded yet.</p>';

  return `${composer}${history}`;
}

function renderExtensionRequests(task) {
  if (!task.deadline_extension_requests?.length) {
    return '<p class="task-empty-state">No deadline extension requests.</p>';
  }

  return `<div class="extension-request-list">${task.deadline_extension_requests.map((request) => `
    <article class="extension-request">
      <div class="extension-request__header">
        <strong>${escapeHtml(request.status.replaceAll('_', ' '))}</strong>
        <span class="request-status ${escapeHtml(request.status)}">${escapeHtml(request.status)}</span>
      </div>
      <p>${escapeHtml(request.requested_by || 'Unknown')} requested ${escapeHtml(formatDate(request.requested_deadline))}${request.current_deadline ? ` from ${escapeHtml(formatDate(request.current_deadline))}` : ''}.</p>
      <p>${escapeHtml(request.reason)}</p>
      ${request.review_note ? `<p class="muted">Review note: ${escapeHtml(request.review_note)}</p>` : ''}
      ${task.permissions.review_deadline_extensions && request.status === 'pending' ? `
        <div class="extension-request__actions">
          <button type="button" class="primary" data-extension-decision="approve" data-extension-request-id="${Number(request.id)}">Approve</button>
          <button type="button" class="ghost" data-extension-decision="reject" data-extension-request-id="${Number(request.id)}">Reject</button>
        </div>
      ` : ''}
    </article>
  `).join('')}</div>`;
}

function renderHistory(task) {
  return renderActivity(task);
}

function allowedStatusOptions(task) {
  return Object.entries(task.status_options || {}).filter(([status]) => {
    if (status === 'cancelled') return Boolean(task.permissions.cancel);
    if (status === 'submitted_for_review') return Boolean(task.permissions.submit_for_review);
    return Boolean(task.permissions.change_status);
  });
}

function taskMenuActions(task) {
  const actions = [{ type: 'view', label: 'View task details' }];
  const permissions = task.permissions;
  const add = (action, label) => actions.push({ type: 'workflow', action, label });

  if (permissions.update) actions.push({ type: 'edit', label: 'Edit task' });
  if (permissions.accept) add('accept', 'Accept task');
  if (permissions.start_work) add('start', 'Start work');
  if (permissions.submit_for_review) add('submit-review', 'Submit for review');
  if (permissions.review) {
    add('approve', 'Approve & complete');
    add('rework', 'Request changes');
  }
  if (permissions.resume) add('resume', 'Resume work');
  if (permissions.add_work_update) add('work-update', 'Add work update');
  if (permissions.flag_risk) {
    add(task.risk_reason ? 'resolve-risk' : 'flag-risk', task.risk_reason ? 'Resolve risk' : 'Flag risk');
  }
  allowedStatusOptions(task).forEach(([status, label]) => {
    actions.push({ type: 'status', status, label: `Change status: ${label}` });
  });

  return actions;
}

function positionTaskMenu(menu, trigger) {
  const triggerRect = trigger.getBoundingClientRect();
  const menuRect = menu.getBoundingClientRect();
  const margin = 12;
  const left = Math.max(margin, Math.min(
    triggerRect.right - menuRect.width,
    window.innerWidth - menuRect.width - margin,
  ));
  const belowTop = triggerRect.bottom + 6;
  const top = belowTop + menuRect.height <= window.innerHeight - margin
    ? belowTop
    : Math.max(margin, triggerRect.top - menuRect.height - 6);

  menu.style.left = `${left}px`;
  menu.style.top = `${top}px`;
}

function closeTaskMenu(restoreFocus = false) {
  taskMenuSequence += 1;
  document.querySelector('.task-action-menu')?.remove();
  const trigger = state.taskMenuTrigger;
  state.taskMenuTrigger = null;
  state.taskMenuTask = null;
  if (trigger) {
    trigger.setAttribute('aria-expanded', 'false');
    if (restoreFocus) trigger.focus();
  }
}

function renderTaskMenu(menu, task, trigger) {
  state.taskMenuTask = task;
  const actions = taskMenuActions(task);
  menu.innerHTML = `
    <div class="task-action-menu__heading" role="presentation">${escapeHtml(task.title || 'Task actions')}</div>
    ${actions.map((item) => `
      <button type="button" role="menuitem" data-menu-type="${item.type}"${item.action ? ` data-menu-action="${escapeHtml(item.action)}"` : ''}${item.status ? ` data-menu-status="${escapeHtml(item.status)}"` : ''}>
        ${escapeHtml(item.label)}
      </button>
    `).join('')}
  `;
  positionTaskMenu(menu, trigger);
  menu.querySelector('[role="menuitem"]')?.focus();
  menu.addEventListener('keydown', (event) => {
    const items = [...menu.querySelectorAll('[role="menuitem"]:not(:disabled)')];
    const currentIndex = items.indexOf(document.activeElement);
    let nextIndex = currentIndex;

    if (event.key === 'ArrowDown') nextIndex = (currentIndex + 1) % items.length;
    else if (event.key === 'ArrowUp') nextIndex = (currentIndex - 1 + items.length) % items.length;
    else if (event.key === 'Home') nextIndex = 0;
    else if (event.key === 'End') nextIndex = items.length - 1;
    else return;

    event.preventDefault();
    items[nextIndex]?.focus();
  });
}

async function loadTaskMenu(menu, trigger, sequence) {
  state.taskMenuTask = null;
  menu.innerHTML = '<p class="task-action-menu__status" role="status">Loading task actions…</p>';
  positionTaskMenu(menu, trigger);

  try {
    const response = await api.getTask(Number(trigger.closest('[data-task-id]')?.dataset.taskId));
    if (sequence !== taskMenuSequence || state.taskMenuTrigger !== trigger) return;
    if (!response.ok) {
      if (handleUnauthorized(response)) {
        closeTaskMenu();
        return;
      }
      menu.innerHTML = `<p class="task-action-menu__error" role="alert">${escapeHtml(responseMessage(response))}</p><button type="button" role="menuitem" data-menu-retry>Retry</button>`;
      positionTaskMenu(menu, trigger);
      menu.querySelector('[data-menu-retry]')?.focus();
      return;
    }

    const task = response.data?.task;
    if (!task?.permissions || !task.status_options || !Number(task.id)) {
      menu.innerHTML = '<p class="task-action-menu__error" role="alert">Laravel returned incomplete task details. Refresh and retry.</p><button type="button" role="menuitem" data-menu-retry>Retry</button>';
      positionTaskMenu(menu, trigger);
      menu.querySelector('[data-menu-retry]')?.focus();
      return;
    }

    renderTaskMenu(menu, task, trigger);
  } catch (error) {
    if (sequence !== taskMenuSequence || state.taskMenuTrigger !== trigger) return;
    menu.innerHTML = `<p class="task-action-menu__error" role="alert">Unable to load task actions. ${escapeHtml(error.message)}</p><button type="button" role="menuitem" data-menu-retry>Retry</button>`;
    positionTaskMenu(menu, trigger);
    menu.querySelector('[data-menu-retry]')?.focus();
  }
}

function openTaskMenu(trigger) {
  if (state.taskMenuTrigger === trigger) {
    closeTaskMenu();
    return;
  }

  closeTaskMenu();
  state.taskMenuTrigger = trigger;
  trigger.setAttribute('aria-expanded', 'true');
  const menu = document.createElement('div');
  menu.className = 'task-action-menu';
  menu.setAttribute('role', 'menu');
  menu.setAttribute('aria-label', 'Task actions');
  document.body.append(menu);
  const sequence = ++taskMenuSequence;
  menu.addEventListener('click', (event) => {
    const retry = event.target.closest('[data-menu-retry]');
    if (retry && state.taskMenuTrigger === trigger && sequence === taskMenuSequence) {
      loadTaskMenu(menu, trigger, sequence);
      return;
    }

    const item = event.target.closest('[data-menu-type]');
    const task = state.taskMenuTask;
    if (!item || !task || state.taskMenuTrigger !== trigger || sequence !== taskMenuSequence) return;

    closeTaskMenu();
    if (item.dataset.menuType === 'view') {
      openTask(task.id);
    } else if (item.dataset.menuType === 'edit') {
      openTaskDialog(task);
    } else if (item.dataset.menuType === 'workflow') {
      performAction(task, item.dataset.menuAction);
    } else if (item.dataset.menuType === 'status') {
      changeStatus(task, item.dataset.menuStatus);
    }
  });
  loadTaskMenu(menu, trigger, sequence);
}

function primaryWorkflowAction(task) {
  if (task.permissions.accept) return { action: 'accept', label: 'Accept task' };
  if (task.permissions.start_work) return { action: 'start', label: 'Start work' };
  if (task.permissions.resume) return { action: 'resume', label: 'Resume work' };
  if (task.permissions.submit_for_review) return { action: 'submit-review', label: 'Submit for review' };
  if (task.permissions.review) return { action: 'approve', label: 'Approve & complete' };
  return null;
}

function renderWorkflow(task) {
  const steps = [
    ['notified', 'Notified'],
    ['unassigned', 'Unassigned'],
    ['assigned', 'Assigned'],
    ['in_progress', task.status === 'rework' ? 'Re-work' : 'In progress'],
    ['submitted_for_review', 'Review'],
    ['completed', 'Completed'],
  ];
  const currentStatus = task.status === 'on_hold'
    ? 'in_progress'
    : (task.status === 'rework' ? 'in_progress' : task.status);
  const current = steps.findIndex(([status]) => status === currentStatus);
  const progress = Math.max(0, Math.min(100, Number(task.progress?.work_completion ?? task.progress_percentage ?? 0)));
  const stages = steps.map(([status, label], index) => `
    <li class="${index < current ? 'done' : ''} ${index === current ? 'current' : ''} ${task.status === 'cancelled' && status === 'completed' ? 'cancelled' : ''}">
      <i>${index < current ? '✓' : ''}</i><span>${label}</span>
    </li>
  `).join('');
  const progressUpdate = task.permissions.update_subtask || task.permissions.create_subtask
    ? '<button type="button" class="progress-update" data-detail-tab-target="subtasks">Update</button>'
    : '';

  return `
    <section class="task-workflow-card">
      <ol class="task-workflow-steps">${stages}</ol>
      ${task.status === 'on_hold' ? '<p class="task-workflow-notice">On hold — waiting to resume.</p>' : ''}
      ${task.status === 'cancelled' ? '<p class="task-workflow-notice is-cancelled">This task was cancelled.</p>' : ''}
      <div class="task-progress-row">
        <strong>Progress</strong>
        <div class="task-progress-track" role="progressbar" aria-label="Task progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${progress}"><span style="width:${progress}%"></span></div>
        <span>${progress}%</span>
        ${progressUpdate}
      </div>
    </section>
  `;
}

function showTaskDetailPage(show) {
  elements.home_intro.hidden = show;
  elements.stats.hidden = show;
  elements.task_list_section.hidden = show;
  elements.task_detail_page.hidden = !show;
  if (!show) {
    elements.details.hidden = true;
    state.currentTask = null;
    state.selectedTaskId = null;
  }
}

async function openTask(taskId, intent = 'open') {
  const sequence = ++detailLoadSequence;
  state.selectedTaskId = Number(taskId);
  if (state.currentTask?.id !== state.selectedTaskId) state.activeTaskTab = 'activity';
  state.currentTask = null;
  showTaskDetailPage(true);
  renderTasks();
  elements.details.hidden = false;
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

    state.currentTask = response.data.task;
    renderTaskDetail(response.data.task, intent);
  } catch (error) {
    if (sequence === detailLoadSequence) {
      elements.details.innerHTML = `<p class="form-error">Unable to load task details. ${escapeHtml(error.message)}</p><button type="button" class="ghost" data-retry-detail>Retry</button>`;
      elements.details.querySelector('[data-retry-detail]')?.addEventListener('click', () => openTask(taskId, intent));
    }
  }
}

function renderTaskDetail(task, intent = 'open') {
  const primaryAction = primaryWorkflowAction(task);
  const statusOptions = allowedStatusOptions(task).filter(([status]) => !(
    (primaryAction?.action === 'submit-review' && status === 'submitted_for_review')
    || (primaryAction?.action === 'accept' && status === 'unassigned')
  ));
  const additionalActions = [];
  if (task.permissions.update) {
    additionalActions.push('<button type="button" data-detail-edit>Edit task / reassign</button>');
  }
  if (task.permissions.review && primaryAction?.action === 'approve') {
    additionalActions.push('<button type="button" data-detail-action="rework">Request changes</button>');
  }
  if (task.permissions.flag_risk) {
    additionalActions.push(`<button type="button" data-detail-action="${task.risk_reason ? 'resolve-risk' : 'flag-risk'}">${task.risk_reason ? 'Resolve risk' : 'Flag risk'}</button>`);
  }
  statusOptions.forEach(([status, label]) => {
    additionalActions.push(`<button type="button" data-detail-status="${escapeHtml(status)}">${escapeHtml(label)}</button>`);
  });
  const extensionRequests = task.deadline_extension_requests || [];
  const tabs = [
    ['activity', 'Activity', task.history?.length || 0],
    ['subtasks', 'Subtasks', task.subtasks?.length || 0],
    ['requests', 'Requests', extensionRequests.length],
  ];
  const activeTab = tabs.some(([tab]) => tab === state.activeTaskTab) ? state.activeTaskTab : 'activity';
  const activeTabContent = activeTab === 'subtasks'
    ? `
      ${task.permissions.create_subtask ? '<button type="button" class="primary add-subtask" data-add-subtask>Add subtask</button>' : ''}
      ${renderSubtasks(task)}
    `
    : (activeTab === 'requests' ? renderExtensionRequests(task) : renderHistory(task));
  const statusLabel = task.status_label || task.status;
  const deadlineLabel = effectiveDeadline(task);

  elements.details.innerHTML = `
    <article class="task-detail-summary">
      <header class="task-detail-header">
        <div class="task-detail-heading">
          <span class="task-code">${escapeHtml(task.task_code || `#${task.id}`)}</span>
          <h1>${escapeHtml(task.title)}</h1>
          <p>${escapeHtml(task.company?.name || '—')} · ${escapeHtml(task.department?.name || '—')}</p>
          <div class="task-detail-badges">
            <span class="status">${escapeHtml(statusLabel)}</span>
            <span class="priority ${escapeHtml(task.priority || 'medium')}">${escapeHtml(task.priority || 'Unspecified')}</span>
            ${task.is_overdue ? `<span class="deadline overdue">Overdue${deadlineLabel ? ` · ${Math.abs(Number(task.progress?.remaining_days || 0))} days` : ''}</span>` : ''}
          </div>
        </div>
        <div class="task-detail-actions">
          ${task.permissions.request_deadline_extension ? '<button type="button" class="ghost" data-request-extension>Request extension</button>' : ''}
          ${primaryAction ? `<button type="button" class="primary" data-primary-workflow="${escapeHtml(primaryAction.action)}">${escapeHtml(primaryAction.label)}</button>` : ''}
          ${additionalActions.length ? `
            <details class="task-detail-more">
              <summary aria-label="More task options" title="More task options">···</summary>
              <div>${additionalActions.join('')}</div>
            </details>
          ` : ''}
        </div>
      </header>
      ${renderWorkflow(task)}
    </article>
    <div class="task-detail-columns">
      <section class="task-detail-main">
        <nav class="task-detail-tabs" aria-label="Task sections">
          ${tabs.map(([tab, label, count]) => `
            <button type="button" class="${activeTab === tab ? 'is-active' : ''}" data-detail-tab="${tab}" aria-selected="${activeTab === tab}">
              ${label}<span>${Number(count || 0)}</span>
            </button>
          `).join('')}
        </nav>
        <section class="task-detail-tab-panel" aria-live="polite">
          ${activeTabContent}
        </section>
      </section>
      <aside class="task-detail-info">
        <h2>Details</h2>
        <dl>
          <div><dt>Assignee</dt><dd>${escapeHtml(task.assignees?.map((person) => person.name).join(', ') || 'Unassigned')}</dd></div>
          <div><dt>Due date</dt><dd>${escapeHtml(formatDate(deadlineLabel))}</dd></div>
          <div><dt>Priority</dt><dd>${escapeHtml(task.priority || 'Unspecified')}</dd></div>
          <div><dt>Task type</dt><dd>${escapeHtml(task.task_type || '—')}</dd></div>
          <div><dt>Company</dt><dd>${escapeHtml(task.company?.name || '—')}</dd></div>
          <div><dt>Department</dt><dd>${escapeHtml(task.department?.name || '—')}</dd></div>
          <div><dt>Estimated effort</dt><dd>${task.estimated_hours ? `${escapeHtml(task.estimated_hours)} hours` : '—'}</dd></div>
          <div><dt>Created</dt><dd>${escapeHtml(formatDate(task.created_at))}</dd></div>
        </dl>
        ${task.description ? `<section class="task-detail-description"><h3>Description</h3><p>${escapeHtml(task.description)}</p></section>` : ''}
        ${task.expected_output ? `<section class="task-detail-description"><h3>Expected output</h3><p>${escapeHtml(task.expected_output)}</p></section>` : ''}
      </aside>
    </div>
  `;

  elements.details.querySelectorAll('[data-detail-tab]').forEach((button) => {
    button.addEventListener('click', () => {
      state.activeTaskTab = button.dataset.detailTab;
      renderTaskDetail(task, intent);
    });
  });
  elements.details.querySelector('[data-detail-tab-target]')?.addEventListener('click', () => {
    state.activeTaskTab = 'subtasks';
    renderTaskDetail(task, intent);
    elements.details.querySelector('.task-detail-tabs')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });
  elements.details.querySelector('[data-primary-workflow]')?.addEventListener('click', (event) => {
    performAction(task, event.currentTarget.dataset.primaryWorkflow);
  });
  elements.details.querySelector('[data-request-extension]')?.addEventListener('click', () => openExtensionDialog(task));
  elements.details.querySelector('[data-detail-edit]')?.addEventListener('click', (event) => {
    event.currentTarget.closest('.task-detail-more')?.removeAttribute('open');
    openTaskDialog(task);
  });
  elements.details.querySelector('[data-add-subtask]')?.addEventListener('click', () => createSubtask(task));
  elements.details.querySelectorAll('[data-toggle-subtask]').forEach((button) => {
    button.addEventListener('click', () => toggleSubtask(task, Number(button.dataset.toggleSubtask)));
  });
  elements.details.querySelectorAll('[data-edit-subtask]').forEach((button) => {
    button.addEventListener('click', () => editSubtask(task, Number(button.dataset.editSubtask)));
  });
  elements.details.querySelectorAll('[data-detail-action]').forEach((button) => {
    button.addEventListener('click', () => {
      button.closest('.task-detail-more')?.removeAttribute('open');
      performAction(task, button.dataset.detailAction);
    });
  });
  elements.details.querySelectorAll('[data-detail-status]').forEach((button) => {
    button.addEventListener('click', () => {
      button.closest('.task-detail-more')?.removeAttribute('open');
      changeStatus(task, button.dataset.detailStatus);
    });
  });
  elements.details.querySelectorAll('[data-extension-decision]').forEach((button) => {
    button.addEventListener('click', () => reviewDeadlineExtension(
      task,
      Number(button.dataset.extensionRequestId),
      button.dataset.extensionDecision,
    ));
  });

  if (intent === 'reassign') {
    if (task.permissions.update) {
      openTaskDialog(task);
    } else {
      showToast('You do not have permission to re-assign this task.', true);
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
  if (action === 'extension-review') {
    return field('review_note', 'Review note (optional)', 'textarea', false, '', 2000);
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
    accept: ['Accept task', 'Confirm that you want to accept this task.'],
    start: ['Start work', 'Record the note and date for starting this task.'],
    'work-update': ['Add work update', 'Add a dated progress update to this task.'],
    resume: ['Resume work', 'Optionally add a note before resuming this task.'],
    'flag-risk': ['Flag task at risk', 'Provide a reason so your team understands the risk.'],
    'resolve-risk': ['Resolve task risk', 'Confirm that the risk flag should be cleared.'],
    'submit-review': ['Submit for review', 'Add a submission note and date.'],
    approve: ['Approve task', 'Confirm the review decision.'],
    rework: ['Return for re-work', 'Optionally add a note explaining the rework.'],
    'extension-review': ['Review deadline extension', 'Add an optional note for the requester.'],
    status: [task.status_options[status] || 'Change task status', 'Confirm this workflow transition.'],
  };
  return labels[action] || ['Update task', 'Confirm this task update.'];
}

function showActionDialog(action, task, status = '') {
  const [title, description] = requestActionDetails(action, task, status);
  elements.action_title.textContent = title;
  elements.action_description.textContent = description;
  elements.action_fields.innerHTML = actionFields(action, task, status);
  elements.action_submit.textContent = action === 'approve'
    ? 'Approve task'
    : (action === 'accept' ? 'Accept task' : 'Continue');
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
  elements.details.querySelectorAll('button').forEach((button) => {
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

async function submitActivity(event) {
  event.preventDefault();
  const form = event.target.closest('[data-activity-form]');
  const note = new FormData(form).get('note')?.toString().trim();
  if (!note || !state.currentTask?.permissions.add_work_update) return;
  const taskId = Number(state.currentTask.id);

  const submitButton = form.querySelector('[data-activity-submit]');
  submitButton.disabled = true;
  try {
    const response = await api.taskAction({
      taskId,
      action: 'work-updates',
      body: { note, update_date: localDateString() },
    });
    if (!response.ok) {
      if (handleUnauthorized(response)) return;
      showToast(responseMessage(response), true);
      return;
    }
    if (!response.data?.task || Number(response.data.task.id) !== Number(state.currentTask.id)) {
      showToast('Laravel did not confirm the activity update. Refresh the task before retrying.', true);
      return;
    }
    showToast(response.data.message || 'Activity added.');
    await loadTasks();
    await openTask(taskId);
  } catch (error) {
    showToast(`Could not save the activity update. ${error.message}`, true);
  } finally {
    submitButton.disabled = false;
  }
}

function openExtensionDialog(task) {
  const currentDate = effectiveDeadline(task) || localDateString();
  const minimumDate = new Date(`${currentDate}T00:00:00`);
  minimumDate.setDate(minimumDate.getDate() + 1);
  const year = minimumDate.getFullYear();
  const month = String(minimumDate.getMonth() + 1).padStart(2, '0');
  const day = String(minimumDate.getDate()).padStart(2, '0');
  elements.extension_deadline.min = `${year}-${month}-${day}`;
  elements.extension_deadline.value = elements.extension_deadline.min;
  elements.extension_reason.value = '';
  elements.extension_form_error.hidden = true;
  elements.extension_submit.disabled = false;
  elements.extension_dialog.showModal();
  elements.extension_deadline.focus();
}

async function submitExtensionRequest(event) {
  event.preventDefault();
  const task = state.currentTask;
  if (!task?.permissions.request_deadline_extension) {
    elements.extension_form_error.textContent = 'You no longer have permission to request a deadline extension.';
    elements.extension_form_error.hidden = false;
    return;
  }

  elements.extension_submit.disabled = true;
  elements.extension_form_error.hidden = true;
  try {
    const response = await api.requestDeadlineExtension({
      taskId: task.id,
      body: {
        requested_deadline: elements.extension_deadline.value,
        reason: elements.extension_reason.value.trim(),
      },
    });
    if (!response.ok) {
      if (handleUnauthorized(response)) return;
      elements.extension_form_error.textContent = responseMessage(response);
      elements.extension_form_error.hidden = false;
      return;
    }
    if (!response.data?.request?.id) {
      elements.extension_form_error.textContent = 'Laravel did not confirm the extension request. Refresh the task before retrying.';
      elements.extension_form_error.hidden = false;
      return;
    }

    elements.extension_dialog.close();
    showToast(response.data.message || 'Deadline extension request submitted.');
    await loadTasks();
    await openTask(task.id);
    state.activeTaskTab = 'requests';
    if (state.currentTask) renderTaskDetail(state.currentTask);
  } catch (error) {
    elements.extension_form_error.textContent = `Could not submit the request. ${error.message}`;
    elements.extension_form_error.hidden = false;
  } finally {
    elements.extension_submit.disabled = false;
  }
}

async function reviewDeadlineExtension(task, requestId, decision) {
  if (state.extensionReviewPending || !['approve', 'reject'].includes(decision)) return;
  const values = await showActionDialog('extension-review', task);
  if (!values) return;

  state.extensionReviewPending = true;
  try {
    const response = await api.reviewDeadlineExtension({
      taskId: task.id,
      requestId,
      body: { decision, ...values },
    });
    if (!response.ok) {
      if (handleUnauthorized(response)) return;
      showToast(responseMessage(response), true);
      return;
    }
    if (!response.data?.request?.id) {
      showToast('Laravel did not confirm the extension review. Refresh the task before retrying.', true);
      return;
    }

    showToast(response.data.message || `Deadline extension ${decision}d.`);
    await loadTasks();
    await openTask(task.id);
    state.activeTaskTab = 'requests';
    if (state.currentTask) renderTaskDetail(state.currentTask);
  } catch (error) {
    showToast(`Could not review the deadline extension. ${error.message}`, true);
  } finally {
    state.extensionReviewPending = false;
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
  document.addEventListener('pointerdown', (event) => {
    if (!state.taskMenuTrigger) return;
    const menu = document.querySelector('.task-action-menu');
    if (menu?.contains(event.target) || state.taskMenuTrigger.contains(event.target)) return;
    closeTaskMenu();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && state.taskMenuTrigger) {
      event.preventDefault();
      closeTaskMenu(true);
    }
  });
  window.addEventListener('resize', () => closeTaskMenu());
  window.addEventListener('scroll', (event) => {
    if (document.querySelector('.task-action-menu')?.contains(event.target)) return;
    closeTaskMenu();
  }, true);

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
  elements.extension_form.addEventListener('submit', submitExtensionRequest);
  elements.extension_cancel.addEventListener('click', () => elements.extension_dialog.close());
  elements.extension_dialog.addEventListener('cancel', (event) => {
    event.preventDefault();
    elements.extension_dialog.close();
  });
  elements.details.addEventListener('submit', (event) => {
    if (event.target.closest('[data-activity-form]')) submitActivity(event);
  });
  elements.back_to_tasks.addEventListener('click', () => {
    showTaskDetailPage(false);
    renderTasks();
  });
  elements.subtask_form.addEventListener('submit', saveSubtask);
  elements.subtask_cancel.addEventListener('click', () => elements.subtask_dialog.close());
  elements.subtask_dialog.addEventListener('close', () => {
    state.editingSubtask = null;
    state.subtaskTaskId = null;
  });
  elements.board.addEventListener('click', (event) => {
    const menuTrigger = event.target.closest('[data-task-menu-trigger]');
    if (menuTrigger) {
      openTaskMenu(menuTrigger);
      return;
    }

    const action = event.target.closest('[data-task-intent]');
    if (action) {
      const card = action.closest('[data-task-id]');
      if (card) {
        action.closest('.task-row-menu')?.removeAttribute('open');
        openTask(Number(card.dataset.taskId), action.dataset.taskIntent);
      }
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
