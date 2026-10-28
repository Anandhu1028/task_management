(() => {
    const body = document.body;
    const overlay = document.querySelector('[data-tf-overlay]');
    const openBtn = document.querySelector('[data-tf-open-sidebar]');
    const search = document.getElementById('tfGlobalSearch');
    const toast = document.getElementById('tfToast');
    const toastMessage = document.querySelector('[data-tf-toast-message]');
    const hideToast = () => {
        if (toast) {
            toast.hidden = true;
        }
    };

    document.querySelector('[data-tf-toast-dismiss]')?.addEventListener('click', hideToast);

    if (toast?.hasAttribute('data-tf-initial-toast')) {
        window.setTimeout(hideToast, 4000);
    }

    const operationModal = document.getElementById('tfOperation');
    const operationTitle = document.querySelector('[data-tf-operation-title]');
    const operationMessage = document.querySelector('[data-tf-operation-message]');
    const operationSpinner = document.querySelector('[data-tf-operation-spinner]');
    const operationCompleteIcon = document.querySelector('[data-tf-operation-complete-icon]');
    const operationTransition = document.querySelector('[data-tf-operation-transition]');
    const operationFrom = document.querySelector('[data-tf-operation-from]');
    const operationTo = document.querySelector('[data-tf-operation-to]');
    let operationCloseTimer;

    const closeOperationModal = () => {
        if (operationModal?.dataset.state === 'complete') {
            window.clearTimeout(operationCloseTimer);
            operationModal.hidden = true;
        }
    };

    const showOperationProgress = (operation) => {
        if (!operationModal) {
            return;
        }

        window.clearTimeout(operationCloseTimer);
        operationModal.className = `tf-operation tf-operation--${operation.to || 'update'}`;
        operationModal.hidden = false;
        operationModal.dataset.state = 'progress';
        operationTitle.textContent = operation.kind === 'status'
            ? 'Updating status...'
            : operation.kind === 'task'
                ? 'Updating task...'
                : 'Updating user...';
        operationMessage.textContent = operation.kind === 'status'
            ? `Changing ${operation.user} to ${operation.to}. Please wait a moment.`
            : operation.kind === 'task'
                ? 'Applying your task action. Please wait a moment.'
                : `Saving changes for ${operation.user}. Please wait a moment.`;
        operationSpinner.hidden = false;
        operationCompleteIcon.hidden = true;

        const hasStatusChange = operation.kind === 'status';
        operationTransition.hidden = !hasStatusChange;

        if (hasStatusChange) {
            operationFrom.textContent = operation.from;
            operationFrom.className = `tf-operation__status tf-operation__status--${operation.from}`;
            operationTo.textContent = operation.to;
            operationTo.className = `tf-operation__status tf-operation__status--${operation.to}`;
        }
    };

    const showOperationComplete = (operation) => {
        if (!operationModal || !operation) {
            return;
        }

        const isStatusChange = operation.kind === 'status';
        operationModal.className = `tf-operation tf-operation--complete tf-operation--${isStatusChange ? operation.to : 'success'}`;
        operationModal.hidden = false;
        operationModal.dataset.state = 'complete';
        operationTitle.textContent = operation.title || (isStatusChange ? 'Status updated' : 'User updated');
        operationMessage.textContent = operation.message;
        operationSpinner.hidden = true;
        operationCompleteIcon.hidden = false;
        operationTransition.hidden = !isStatusChange;

        if (isStatusChange) {
            operationFrom.textContent = operation.from;
            operationFrom.className = `tf-operation__status tf-operation__status--${operation.from}`;
            operationTo.textContent = operation.to;
            operationTo.className = `tf-operation__status tf-operation__status--${operation.to}`;
        }

        operationCloseTimer = window.setTimeout(closeOperationModal, 2000);
    };
    window.tfShowOperationComplete = showOperationComplete;

    if (window.tfCompletedOperation) {
        showOperationComplete(window.tfCompletedOperation);
    }

    document.querySelectorAll('[data-tf-operation-form], .tf-task-workflow form, .tf-wf-panel form').forEach((form) => {
        form.addEventListener('submit', () => {
            showOperationProgress({
                kind: form.dataset.operationKind || (form.closest('.tf-task-workflow, .tf-wf-panel') ? 'task' : undefined),
                user: form.dataset.operationUser,
                from: form.dataset.operationFrom,
                to: form.dataset.operationTo,
            });

            const submitButton = form.querySelector('button[type="submit"]');
            if (submitButton && !submitButton.name) {
                submitButton.disabled = true;
            }
        });
    });

    const positionOrganizationActionMenu = (toggle, menu) => {
        const toggleRect = toggle.getBoundingClientRect();
        const menuRect = menu.getBoundingClientRect();
        const viewportPadding = 8;
        const gap = 6;
        const left = Math.max(
            viewportPadding,
            Math.min(toggleRect.right - menuRect.width, window.innerWidth - menuRect.width - viewportPadding),
        );
        const top = toggleRect.bottom + menuRect.height + gap <= window.innerHeight - viewportPadding
            ? toggleRect.bottom + gap
            : Math.max(viewportPadding, toggleRect.top - menuRect.height - gap);

        menu.style.left = `${left}px`;
        menu.style.top = `${top}px`;
    };

    document.querySelectorAll('.tf-organization-table [data-bs-toggle="dropdown"]').forEach((toggle) => {
        let menuPlaceholder;
        let menu;

        toggle.addEventListener('shown.bs.dropdown', () => {
            menu = toggle.parentElement.querySelector('.dropdown-menu');

            if (!menu) {
                return;
            }

            menuPlaceholder = document.createComment('organization-action-menu');
            menu.parentNode.insertBefore(menuPlaceholder, menu);
            document.body.append(menu);
            menu.classList.add('tf-organization-action-menu--portal');
            menu.style.removeProperty('transform');
            menu.style.removeProperty('right');
            menu.style.removeProperty('bottom');
            menu.style.removeProperty('inset');
            positionOrganizationActionMenu(toggle, menu);
        });

        toggle.addEventListener('hidden.bs.dropdown', () => {
            if (!menu || !menuPlaceholder?.parentNode) {
                return;
            }

            menu.classList.remove('tf-organization-action-menu--portal');
            menu.removeAttribute('style');
            menuPlaceholder.parentNode.insertBefore(menu, menuPlaceholder);
            menuPlaceholder.remove();
            menuPlaceholder = null;
            menu = null;
        });

        window.addEventListener('scroll', () => {
            if (menu?.classList.contains('show')) {
                positionOrganizationActionMenu(toggle, menu);
            }
        }, true);

        window.addEventListener('resize', () => {
            if (menu?.classList.contains('show')) {
                positionOrganizationActionMenu(toggle, menu);
            }
        });
    });

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('.tf-user-offcanvas__form');

        if (form?.querySelector('[name="form_mode"]')?.value === 'edit') {
            showOperationProgress({
                kind: 'update',
                user: form.querySelector('[name="name"]')?.value || 'user',
            });
        }
    }, true);

    // Mobile sidebar toggle
    const closeMobileSidebar = () => body.classList.remove('sidebar-open');
    openBtn?.addEventListener('click', () => body.classList.add('sidebar-open'));
    overlay?.addEventListener('click', closeMobileSidebar);

    document.querySelectorAll('[data-tf-submenu-toggle]').forEach((toggle) => {
        toggle.addEventListener('click', () => {
            const submenu = document.getElementById(toggle.getAttribute('aria-controls'));
            if (!submenu) {
                return;
            }

            const isExpanded = toggle.getAttribute('aria-expanded') === 'true';
            if (!isExpanded) {
                document.querySelectorAll('[data-tf-submenu-toggle]').forEach((otherToggle) => {
                    if (otherToggle === toggle) {
                        return;
                    }

                    const otherSubmenu = document.getElementById(otherToggle.getAttribute('aria-controls'));
                    otherToggle.setAttribute('aria-expanded', 'false');
                    otherToggle.closest('.tf-nav__group')?.classList.remove('is-open');
                    if (otherSubmenu) {
                        otherSubmenu.hidden = true;
                    }
                });
            }

            toggle.setAttribute('aria-expanded', String(!isExpanded));
            toggle.closest('.tf-nav__group')?.classList.toggle('is-open', !isExpanded);
            submenu.hidden = isExpanded;
        });
    });

    // Global shortcut Ctrl+K / Cmd+K
    document.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            search?.focus();
        }
        if (event.key === 'Escape') {
            closeOperationModal();
            closeMobileSidebar();
        }
    });

    // Date range & picker dropdowns
    document.querySelectorAll('[data-tf-pick]').forEach((menu) => {
        menu.querySelectorAll('.dropdown-item').forEach((item) => {
            item.addEventListener('click', () => {
                menu.querySelectorAll('.dropdown-item').forEach((el) => el.classList.remove('active'));
                item.classList.add('active');
                const trigger = menu.closest('.dropdown')?.querySelector('[data-label]');
                if (trigger) {
                    trigger.textContent = item.textContent.trim();
                }
            });
        });
    });

    const notificationPoller = document.querySelector('[data-tf-notification-poll]');
    const notificationBell = document.querySelector('[data-tf-notification-bell]');
    const notificationList = document.querySelector('[data-tf-notification-list]');
    const notificationMarkReadForm = document.querySelector('[data-tf-mark-read-form]');
    let notificationPollInProgress = false;
    let notificationPollingErrorReported = false;

    const createNotificationElement = (notification) => {
        const element = document.createElement(notification.url ? 'a' : 'div');
        element.className = `tf-notify${notification.is_unread ? ' is-unread' : ''}`;

        if (notification.url) {
            element.href = notification.url;
        }

        const dot = document.createElement('span');
        dot.className = 'tf-notify__dot';
        element.append(dot);

        const content = document.createElement('span');
        const title = document.createElement('span');
        title.className = 'tf-notify__title';
        title.textContent = notification.title || 'Task update';
        content.append(title);

        if (notification.actor_name) {
            const actorSpan = document.createElement('span');
            actorSpan.className = 'tf-notify__actor text-muted';
            actorSpan.style.fontSize = '11px';
            actorSpan.style.display = 'block';
            actorSpan.textContent = notification.actor_role
                ? `${notification.actor_name} (${notification.actor_role})`
                : notification.actor_name;
            content.append(actorSpan);
        }

        if (notification.task_title) {
            const taskTitle = document.createElement('span');
            taskTitle.className = 'tf-notify__body';
            taskTitle.style.fontWeight = '600';
            taskTitle.textContent = notification.task_title;
            content.append(taskTitle);
        }

        if (notification.body) {
            const bodyText = document.createElement('span');
            bodyText.className = 'tf-notify__body';
            bodyText.textContent = notification.body;
            content.append(bodyText);
        }

        const time = document.createElement('span');
        time.className = 'tf-notify__time';
        time.textContent = notification.created_at || '';
        content.append(time);
        element.append(content);

        return element;
    };

    const refreshNotifications = async () => {
        if (!notificationPoller || !notificationList || notificationPollInProgress || document.hidden) {
            return;
        }

        notificationPollInProgress = true;

        try {
            const response = await fetch(notificationPoller.dataset.tfNotificationPoll, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`Notification polling failed with status ${response.status}.`);
            }

            const data = await response.json();
            const notifications = Array.isArray(data.notifications) ? data.notifications : [];

            notificationList.replaceChildren(...notifications.map(createNotificationElement));

            if (notifications.length === 0) {
                const emptyMessage = document.createElement('p');
                emptyMessage.className = 'tf-notify__empty';
                emptyMessage.textContent = 'No notifications yet.';
                notificationList.append(emptyMessage);
            }

            let notificationDot = notificationBell?.querySelector('[data-tf-notification-dot]');
            if ((data.unread_count || 0) > 0 && notificationBell && !notificationDot) {
                notificationDot = document.createElement('span');
                notificationDot.className = 'tf-bell__dot';
                notificationDot.dataset.tfNotificationDot = '';
                notificationBell.append(notificationDot);
            } else if ((data.unread_count || 0) === 0) {
                notificationDot?.remove();
            }

            if (notificationMarkReadForm) {
                notificationMarkReadForm.hidden = (data.unread_count || 0) === 0;
            }

            notificationBell?.setAttribute(
                'aria-label',
                (data.unread_count || 0) > 0
                    ? `Notifications, ${data.unread_count} unread`
                    : 'Notifications',
            );

            notificationPollingErrorReported = false;
        } catch (error) {
            if (!notificationPollingErrorReported) {
                console.error('Unable to refresh notifications.', error);
                notificationPollingErrorReported = true;
            }
        } finally {
            notificationPollInProgress = false;
        }
    };

    if (notificationPoller) {
        refreshNotifications();
        window.setInterval(refreshNotifications, 3000);
    }

    // Notifications read
    document.querySelector('[data-tf-mark-read]')?.addEventListener('click', () => {
        document.querySelectorAll('.tf-notify').forEach((note) => note.classList.remove('is-unread'));
        document.querySelector('.tf-bell__dot')?.remove();
        if (notificationMarkReadForm) {
            notificationMarkReadForm.hidden = true;
        }
    });

    // Task Tab Filtering
    let activeTab = 'all';
    const applyTaskFilters = () => {
        const rows = document.querySelectorAll('#taskTableBody tr, #taskCardList .tf-task-card');
        let visible = 0;
        rows.forEach((row) => {
            const tabs = (row.dataset.tabs || '').split(' ');
            const show = activeTab === 'all' || tabs.includes(activeTab);
            row.hidden = !show;
            if (show) {
                visible += 1;
            }
        });
        const empty = document.getElementById('taskEmpty');
        if (empty) {
            empty.hidden = visible !== 0;
        }
    };

    document.querySelectorAll('[data-tf-tab]').forEach((tab) => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('[data-tf-tab]').forEach((el) => el.classList.remove('is-active'));
            tab.classList.add('is-active');
            activeTab = tab.dataset.tfTab;
            applyTaskFilters();
        });
    });

    // Toast helper
    const showToast = (message) => {
        if (!toast) return;
        if (toastMessage) toastMessage.textContent = message;
        toast.hidden = false;
        window.setTimeout(() => {
            toast.hidden = true;
        }, 2200);
    };

    // =========================================================================
    // KPI mini bar-chart renderer
    // =========================================================================
    const renderSparklines = () => {
        document.querySelectorAll('.tf-kpi__spark canvas').forEach((canvas) => {
            const raw = canvas.dataset.spark;
            if (!raw) {
                return;
            }

            const points = JSON.parse(raw);
            if (!Array.isArray(points) || points.length === 0 || !points.every(Number.isFinite)) {
                return;
            }

            const rect = canvas.getBoundingClientRect();
            const dpr = window.devicePixelRatio || 1;
            const w = rect.width || 84;
            const h = rect.height || 32;

            canvas.width = w * dpr;
            canvas.height = h * dpr;

            const ctx = canvas.getContext('2d');
            if (!ctx) {
                return;
            }

            ctx.scale(dpr, dpr);
            ctx.clearRect(0, 0, w, h);

            const max = Math.max(...points, 1);
            const gap = Math.min(3, w / points.length * 0.3);
            const barWidth = (w - gap * (points.length - 1)) / points.length;
            const usableHeight = h - 2;
            const colors = ['#1e3a8a', '#2563eb', '#60a5fa', '#94a3b8'];

            points.forEach((point, index) => {
                const barHeight = Math.max(point > 0 ? 2 : 0, (point / max) * usableHeight);
                const x = index * (barWidth + gap);
                const y = h - barHeight;

                ctx.fillStyle = colors[index % colors.length];
                ctx.fillRect(x, y, barWidth, barHeight);
            });
        });
    };

    renderSparklines();
    window.addEventListener('resize', renderSparklines);

    // =========================================================================
    // Chart.js Visual Configuration & Dynamic Data Binding
    // =========================================================================
    const brandBlue = '#2563eb';
    const gridBorderColor = '#f1f5f9';
    const textLight = '#94a3b8';

    // 1. Task Completion Chart (Real database records)
    const completionEl = document.getElementById('completionChart');
    if (completionEl && window.Chart) {
        try {
            const isAdminDashboard = Boolean(completionEl.closest('.tf-admin-dashboard'));
            const rawLabels = JSON.parse(completionEl.dataset.labels || '[]');
            const rawValues = JSON.parse(completionEl.dataset.values || '[]');
            const labels = rawLabels.length ? rawLabels : ['No Data'];
            const values = rawValues.length ? rawValues : [0];

            const ctx = completionEl.getContext('2d');
            const gradient = ctx.createLinearGradient(0, 0, 0, 220);
            gradient.addColorStop(0, 'rgba(37, 99, 235, 0.22)');
            gradient.addColorStop(0.7, 'rgba(37, 99, 235, 0.05)');
            gradient.addColorStop(1, 'rgba(37, 99, 235, 0.00)');

            const maxVal = Math.max(...values, 5);
            const yMax = Math.ceil(maxVal * 1.25);

            const compChart = new Chart(completionEl, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        data: values,
                        borderColor: brandBlue,
                        backgroundColor: gradient,
                        fill: true,
                        tension: 0.38,
                        borderWidth: 2.2,
                        pointRadius: values.map(v => v > 0 ? 4 : (isAdminDashboard ? 0 : 2)),
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: brandBlue,
                        pointBorderWidth: 2,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            enabled: true,
                            backgroundColor: '#0f172a',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            padding: 8,
                            cornerRadius: 8,
                            displayColors: false,
                            callbacks: {
                                label: (c) => `${c.parsed.y} task${c.parsed.y === 1 ? '' : 's'} completed`,
                            },
                        },
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                color: textLight,
                                font: { size: 11, family: 'Inter', weight: 500 },
                            },
                            border: { display: false },
                        },
                        y: {
                            beginAtZero: true,
                            min: 0,
                            suggestedMax: yMax,
                            ticks: {
                                display: !isAdminDashboard,
                                precision: 0,
                                color: textLight,
                                font: { size: 11, family: 'Inter', weight: 500 },
                            },
                            grid: {
                                color: gridBorderColor,
                            },
                            border: { display: false },
                        },
                    },
                },
            });

            // Position peak tooltip if peak point exists and has completions
            const peakTooltip = document.getElementById('tfPeakTooltip');
            if (peakTooltip && values.length > 0) {
                const maxIndex = values.indexOf(Math.max(...values));
                if (values[maxIndex] > 0) {
                    const updateTooltipPos = () => {
                        const meta = compChart.getDatasetMeta(0);
                        if (meta && meta.data && meta.data[maxIndex]) {
                            const pt = meta.data[maxIndex];
                            peakTooltip.style.left = `${pt.x}px`;
                            peakTooltip.style.top = `${Math.max(10, pt.y - 48)}px`;
                            peakTooltip.style.display = 'block';
                        }
                    };
                    window.setTimeout(updateTooltipPos, 180);
                    window.addEventListener('resize', updateTooltipPos);
                }
            }
        } catch (e) {
            console.error('Error initializing completion chart', e);
        }
    }

    // 2. Task Status Donut Chart (Real database records)
    const statusEl = document.getElementById('statusChart');
    if (statusEl && window.Chart) {
        try {
            const labels = JSON.parse(statusEl.dataset.labels || '["Notified", "Unassigned", "Assigned", "In Progress", "On Hold", "At Risk", "Overdue", "Submitted for Review", "Completed", "Cancelled"]');
            const values = JSON.parse(statusEl.dataset.values || '[0, 0, 0, 0, 0, 0, 0, 0, 0, 0]');
            const colors = JSON.parse(statusEl.dataset.colors || '["#64748b", "#2563eb", "#0ea5e9", "#f97316", "#a16207", "#dc2626", "#b91c1c", "#8b5cf6", "#22c55e", "#64748b"]');

            const hasData = values.some(v => v > 0);
            const chartData = hasData ? values : [1];
            const chartColors = hasData ? colors : ['#e2e8f0'];
            const chartLabels = hasData ? labels : ['No tasks'];

            new Chart(statusEl, {
                type: 'doughnut',
                data: {
                    labels: chartLabels,
                    datasets: [{
                        data: chartData,
                        backgroundColor: chartColors,
                        borderWidth: 0,
                        hoverOffset: hasData ? 4 : 0,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '74%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            enabled: hasData,
                            backgroundColor: '#0f172a',
                            padding: 10,
                            cornerRadius: 8,
                            titleFont: { size: 12, family: 'Inter' },
                            bodyFont: { size: 12, family: 'Inter' },
                            callbacks: {
                                label: (c) => ` ${c.label}: ${c.parsed} task${c.parsed === 1 ? '' : 's'}`,
                            },
                        },
                    },
                },
            });
        } catch (e) {
            console.error('Error initializing status donut chart', e);
        }
    }

    // 3. Employee My Workload Chart (Assigned tasks by weekday)
    const myWorkloadEl = document.getElementById('myWorkloadChart');
    if (myWorkloadEl && window.Chart) {
        try {
            const labels = JSON.parse(myWorkloadEl.dataset.labels || '["Mon", "Tue", "Wed", "Thu", "Fri"]');
            const values = JSON.parse(myWorkloadEl.dataset.values || '[0, 0, 0, 0, 0]');

            const barValuePlugin = {
                id: 'barValuePlugin',
                afterDatasetsDraw(chart) {
                    const { ctx } = chart;
                    chart.data.datasets.forEach((dataset, datasetIndex) => {
                        const meta = chart.getDatasetMeta(datasetIndex);
                        meta.data.forEach((bar, index) => {
                            const val = dataset.data[index];
                            if (val > 0) {
                                ctx.fillStyle = '#64748b';
                                ctx.font = '600 11px Inter, sans-serif';
                                ctx.textAlign = 'center';
                                ctx.textBaseline = 'bottom';
                                ctx.fillText(val, bar.x, bar.y - 4);
                            }
                        });
                    });
                },
            };

            const maxVal = Math.max(...values, 3);

            new Chart(myWorkloadEl, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        data: values,
                        backgroundColor: brandBlue,
                        borderRadius: 6,
                        borderSkipped: 'bottom',
                        barThickness: 28,
                        maxBarThickness: 32,
                    }],
                },
                plugins: [barValuePlugin],
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: { top: 18 } },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            callbacks: {
                                label: (c) => `${c.parsed.y} tasks scheduled`,
                            },
                        },
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                color: textLight,
                                font: { size: 11, family: 'Inter', weight: 500 },
                            },
                            border: { display: false },
                        },
                        y: {
                            beginAtZero: true,
                            suggestedMax: maxVal + 1,
                            ticks: {
                                precision: 0,
                                color: textLight,
                                font: { size: 11, family: 'Inter', weight: 500 },
                            },
                            grid: { color: gridBorderColor },
                            border: { display: false },
                        },
                    },
                },
            });
        } catch (e) {
            console.error('Error initializing my workload chart', e);
        }
    }

    // 4. Team Leader department workload charts
    const deptWorkloadEl = document.getElementById('deptWorkloadChart');
    if (deptWorkloadEl && window.Chart) {
        try {
            const labels = JSON.parse(deptWorkloadEl.dataset.labels || '[]');
            const periods = JSON.parse(deptWorkloadEl.dataset.periods || '{}');
            const memberPeriodSelect = document.querySelector('[data-member-range]');
            const initialMemberPeriod = memberPeriodSelect?.value || 'this_week';
            const memberData = periods[initialMemberPeriod] || {};
            const initialMemberTotals = labels.map((_, index) => (
                (memberData.completed?.[index] || 0)
                + (memberData.in_progress?.[index] || 0)
                + (memberData.overdue?.[index] || 0)
            ));
            const memberMax = Math.max(0, ...initialMemberTotals);

            const memberChart = new Chart(deptWorkloadEl, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        { label: 'Completed', data: memberData.completed || [], backgroundColor: '#2563eb', borderRadius: 3 },
                        { label: 'In progress', data: memberData.in_progress || [], backgroundColor: '#7da8f7', borderRadius: 3 },
                        { label: 'Overdue', data: memberData.overdue || [], backgroundColor: '#94a3b8', borderRadius: 3 },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: { top: 18 } },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                boxHeight: 12,
                                padding: 16,
                                font: { size: 11, family: 'Inter', weight: 500 },
                                color: '#475569',
                            },
                        },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            callbacks: {
                                label: (c) => ` ${c.dataset.label}: ${c.parsed.y} tasks`,
                            },
                        },
                    },
                    datasets: {
                        bar: { categoryPercentage: 0.58, barPercentage: 0.82 },
                    },
                    scales: {
                        x: {
                            stacked: true,
                            grid: { display: false },
                            ticks: {
                                color: '#475569',
                                font: { size: 11, family: 'Inter', weight: 600 },
                            },
                            border: { display: false },
                        },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            suggestedMax: memberMax + 1,
                            ticks: {
                                precision: 0,
                                color: textLight,
                                font: { size: 11, family: 'Inter' },
                            },
                            grid: { color: gridBorderColor },
                            border: { display: false },
                        },
                    },
                },
                plugins: [{
                    id: 'teamMemberBarTotals',
                    afterDatasetsDraw(chart) {
                        const { ctx, scales: { x, y } } = chart;
                        ctx.save();
                        ctx.font = '600 11px Inter, sans-serif';
                        ctx.fillStyle = '#334155';
                        ctx.textAlign = 'center';

                        chart.data.labels.forEach((_, index) => {
                            const total = chart.data.datasets.reduce(
                                (sum, dataset) => sum + (Number(dataset.data[index]) || 0),
                                0,
                            );
                            if (total > 0) {
                                ctx.fillText(String(total), x.getPixelForValue(index), y.getPixelForValue(total) - 7);
                            }
                        });

                        ctx.restore();
                    },
                }],
            });

            memberPeriodSelect?.addEventListener('change', () => {
                const selectedPeriod = periods[memberPeriodSelect.value] || {};
                memberChart.data.datasets[0].data = selectedPeriod.completed || [];
                memberChart.data.datasets[1].data = selectedPeriod.in_progress || [];
                memberChart.data.datasets[2].data = selectedPeriod.overdue || [];
                const totals = labels.map((_, index) => (
                    (selectedPeriod.completed?.[index] || 0)
                    + (selectedPeriod.in_progress?.[index] || 0)
                    + (selectedPeriod.overdue?.[index] || 0)
                ));
                memberChart.options.scales.y.suggestedMax = Math.max(0, ...totals) + 1;
                memberChart.update();
            });
        } catch (e) {
            console.error('Error initializing department workload chart', e);
        }
    }

    const departmentWeeklyEl = document.getElementById('departmentWeeklyChart');
    if (departmentWeeklyEl && window.Chart) {
        try {
            const weekly = JSON.parse(departmentWeeklyEl.dataset.weekly || '{}');
            const weekRangeSelect = document.querySelector('[data-week-range]');
            const visibleWeeks = Number(weekRangeSelect?.value || 4);
            const getVisibleWeekLabels = (count) => weekly.labels
                .slice(-count)
                .map((_, index) => `Wk ${index + 1}`);
            const weeklyChart = new Chart(departmentWeeklyEl, {
                type: 'bar',
                data: {
                    labels: getVisibleWeekLabels(visibleWeeks),
                    datasets: [
                        { label: 'Created', data: weekly.created.slice(-visibleWeeks), backgroundColor: '#1e3a8a', borderRadius: 3 },
                        { label: 'Completed', data: weekly.completed.slice(-visibleWeeks), backgroundColor: '#2563eb', borderRadius: 3 },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                boxHeight: 12,
                                padding: 16,
                                font: { size: 11, family: 'Inter', weight: 500 },
                                color: '#475569',
                            },
                        },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            callbacks: {
                                label: (context) => ` ${context.dataset.label}: ${context.parsed.y} tasks`,
                            },
                        },
                    },
                    datasets: {
                        bar: { categoryPercentage: 0.62, barPercentage: 0.82 },
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: '#475569', font: { size: 11, family: 'Inter', weight: 500 } },
                            border: { display: false },
                        },
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0, color: textLight, font: { size: 11, family: 'Inter' } },
                            grid: { color: gridBorderColor },
                            border: { display: false },
                        },
                    },
                },
            });

            weekRangeSelect?.addEventListener('change', () => {
                const selectedWeeks = Number(weekRangeSelect.value);
                weeklyChart.data.labels = getVisibleWeekLabels(selectedWeeks);
                weeklyChart.data.datasets[0].data = weekly.created.slice(-selectedWeeks);
                weeklyChart.data.datasets[1].data = weekly.completed.slice(-selectedWeeks);
                weeklyChart.update();
            });
        } catch (e) {
            console.error('Error initializing weekly department chart', e);
        }
    }

    const departmentPerformanceCompanySelect = document.querySelector('[data-department-performance-company]');
    const departmentPerformanceRows = document.querySelectorAll('[data-department-performance-row]');
    const departmentPerformanceEmpty = document.querySelector('[data-department-performance-empty]');
    const departmentCountEl = document.querySelector('[data-department-count]');
    const departmentAverageEl = document.querySelector('[data-department-average]');
    const departmentTasksEl = document.querySelector('[data-department-tasks]');
    const filterDepartmentPerformanceByCompany = () => {
        if (!departmentPerformanceCompanySelect || departmentPerformanceRows.length === 0) {
            return;
        }

        const selectedCompanyId = departmentPerformanceCompanySelect.value;
        let visibleDepartments = 0;
        let totalTasks = 0;
        let totalCompletionRate = 0;

        departmentPerformanceRows.forEach((row) => {
            const isVisible = !selectedCompanyId || row.dataset.companyId === selectedCompanyId;
            row.hidden = !isVisible;
            if (isVisible) {
                visibleDepartments++;
                totalTasks += Number(row.dataset.taskCount || 0);
                totalCompletionRate += Number(row.dataset.completionRate || 0);
            }
        });

        if (departmentCountEl) {
            departmentCountEl.textContent = `${visibleDepartments} departments`;
        }
        if (departmentAverageEl) {
            departmentAverageEl.textContent = `${Math.round(visibleDepartments ? totalCompletionRate / visibleDepartments : 0)}%`;
        }
        if (departmentTasksEl) {
            departmentTasksEl.textContent = new Intl.NumberFormat().format(totalTasks);
        }
        if (departmentPerformanceEmpty) {
            departmentPerformanceEmpty.hidden = visibleDepartments > 0;
        }
    };
    departmentPerformanceCompanySelect?.addEventListener('change', filterDepartmentPerformanceByCompany);
    filterDepartmentPerformanceByCompany();

    const employeeHeadcountEl = document.getElementById('employeeHeadcountChart');
    if (employeeHeadcountEl && window.Chart) {
        try {
            const labels = JSON.parse(employeeHeadcountEl.dataset.labels || '[]');
            const counts = JSON.parse(employeeHeadcountEl.dataset.counts || '[]');

            new Chart(employeeHeadcountEl, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: 'Employees',
                        data: counts,
                        backgroundColor: '#7da8f7',
                        borderRadius: 6,
                        borderSkipped: false,
                        maxBarThickness: 30,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: { top: 16, left: 2, right: 2 } },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            callbacks: {
                                label: (context) => ` ${context.parsed.y} active employees`,
                            },
                        },
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                color: textLight,
                                font: { size: 9, family: 'Inter', weight: 500 },
                                maxRotation: 0,
                                autoSkip: false,
                            },
                            border: { display: false },
                        },
                        y: {
                            beginAtZero: true,
                            suggestedMax: Math.max(1, ...counts) + 2,
                            ticks: {
                                precision: 0,
                                color: textLight,
                                font: { size: 9, family: 'Inter' },
                            },
                            grid: { color: gridBorderColor },
                            border: { display: false },
                        },
                    },
                },
                plugins: [{
                    id: 'employeeHeadcountLabels',
                    afterDatasetsDraw(chart) {
                        const { ctx } = chart;
                        ctx.save();
                        ctx.font = '600 9px Inter, sans-serif';
                        ctx.fillStyle = '#64748b';
                        ctx.textAlign = 'center';

                        chart.getDatasetMeta(0).data.forEach((bar, index) => {
                            ctx.fillText(String(counts[index] || 0), bar.x, bar.y - 5);
                        });

                        ctx.restore();
                    },
                }],
            });
        } catch (e) {
            console.error('Error initializing employee headcount chart', e);
        }
    }

    // 5. Company Comparison Chart (Admin & CEO)
    const compComparisonEl = document.getElementById('companyComparisonChart');
    if (compComparisonEl && window.Chart) {
        try {
            const isAdminDashboard = Boolean(compComparisonEl.closest('.tf-admin-dashboard'));
            const companyBarSpacing = isAdminDashboard
                ? { categoryPercentage: 0.62, barPercentage: 0.68 }
                : {};
            const labels = JSON.parse(compComparisonEl.dataset.labels || '[]');
            const totals = JSON.parse(compComparisonEl.dataset.totals || '[]');
            const completed = JSON.parse(compComparisonEl.dataset.completed || '[]');
            const atRisk = JSON.parse(compComparisonEl.dataset.atRisk || '[]');
            const overdue = JSON.parse(compComparisonEl.dataset.overdue || '[]');

            new Chart(compComparisonEl, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        { label: 'Total Tasks', data: totals, backgroundColor: '#1e3a8a', borderRadius: 4, ...companyBarSpacing },
                        { label: 'Completed', data: completed, backgroundColor: '#2563e9', borderRadius: 4, ...companyBarSpacing },
                        { label: 'At Risk', data: atRisk, backgroundColor: '#dbe7ff', borderRadius: 4, ...companyBarSpacing },
                        { label: 'Overdue', data: overdue, backgroundColor: '#94a3b8', borderRadius: 4, ...companyBarSpacing },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                boxWidth: isAdminDashboard ? 8 : 12,
                                usePointStyle: isAdminDashboard,
                                pointStyle: 'circle',
                                font: { size: isAdminDashboard ? 9 : 11 },
                            },
                        },
                        tooltip: {
                            backgroundColor: '#0f172a',
                        },
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: textLight, font: { size: 11 } },
                            border: { display: false },
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                display: !isAdminDashboard,
                                precision: 0,
                                color: textLight,
                                font: { size: 11 },
                            },
                            grid: {
                                color: isAdminDashboard
                                    ? (context) => context.tick.value === 0 ? gridBorderColor : 'transparent'
                                    : gridBorderColor,
                            },
                            border: { display: false },
                        },
                    },
                },
            });
        } catch (e) {
            console.error('Error initializing company comparison chart', e);
        }
    }

    // =========================================================================
    // Filter Bar Interactivity
    // =========================================================================
    const filterForm = document.getElementById('dashboardFilterForm');
    if (filterForm) {
        const filterDateRange = document.getElementById('filterDateRange');
        const customDateWrap = document.getElementById('customDateWrap');
        const filterCompany = document.getElementById('filterCompany');
        const filterDept = document.getElementById('filterDepartment');
        const filterTL = document.getElementById('filterTeamLeader');
        const filterEmp = document.getElementById('filterEmployee');

        // Date range menu buttons
        document.querySelectorAll('#dateRangeMenu [data-range]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const range = btn.dataset.range;
                filterDateRange.value = range;

                if (range === 'custom') {
                    if (customDateWrap) customDateWrap.style.display = 'flex';
                } else {
                    if (customDateWrap) customDateWrap.style.display = 'none';
                    filterForm.submit();
                }
            });
        });

        // Cascading Department options based on selected Company
        if (filterCompany && filterDept) {
            const syncDeptOptions = () => {
                const selectedCompany = filterCompany.value;
                Array.from(filterDept.options).forEach(opt => {
                    if (!opt.value) return; // 'All Departments'
                    const optCompany = opt.dataset.companyId;
                    if (!selectedCompany || optCompany === selectedCompany) {
                        opt.hidden = false;
                    } else {
                        opt.hidden = true;
                        if (opt.selected) filterDept.value = '';
                    }
                });
            };

            filterCompany.addEventListener('change', () => {
                syncDeptOptions();
                filterForm.submit();
            });
        }

        if (filterDept) {
            filterDept.addEventListener('change', () => filterForm.submit());
        }

        if (filterTL) {
            filterTL.addEventListener('change', () => filterForm.submit());
        }

        if (filterEmp) {
            filterEmp.addEventListener('change', () => filterForm.submit());
        }
    }

    /* ---------- ChatGPT Popup Window & Launcher ---------- */
    const chatGptModal = document.getElementById('tfChatGptModal');
    const chatGptTrigger = document.getElementById('tfChatGptNavBtn');

    const closeModal = () => {
        if (!chatGptModal) return;
        chatGptModal.hidden = true;
        document.body.classList.remove('tf-chatgpt-modal-open');
        chatGptTrigger?.focus();
    };

    const openChatGptWindow = () => {
        const width = Math.min(1240, Math.floor(window.screen.availWidth * 0.9));
        const height = Math.min(860, Math.floor(window.screen.availHeight * 0.88));
        const left = Math.max(0, Math.floor((window.screen.availWidth - width) / 2));
        const top = Math.max(0, Math.floor((window.screen.availHeight - height) / 2));

        const features = `width=${width},height=${height},left=${left},top=${top},menubar=no,toolbar=no,location=yes,status=no,resizable=yes,scrollbars=yes`;
        const win = window.open('https://chatgpt.com/', 'TaskFlowChatGPTWindow', features);

        if (win && !win.closed) {
            win.focus();
            if (chatGptModal && !chatGptModal.hidden) {
                closeModal();
            }
        } else {
            // Popup blocker intercepted: fallback to modal
            if (chatGptModal) {
                chatGptModal.hidden = false;
                document.body.classList.add('tf-chatgpt-modal-open');
                const focusTarget = chatGptModal.querySelector('#tfChatGptLaunchBtn') || chatGptModal.querySelector('#tfChatGptModalClose');
                focusTarget?.focus();
            }
        }
    };

    chatGptTrigger?.addEventListener('click', (e) => {
        e.preventDefault();
        openChatGptWindow();
    });

    const launchBtn = document.getElementById('tfChatGptLaunchBtn');
    launchBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        openChatGptWindow();
    });

    chatGptModal?.querySelectorAll('[data-tf-chatgpt-close]').forEach(btn => {
        btn.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && chatGptModal && !chatGptModal.hidden) {
            closeModal();
        }
    });

    chatGptModal?.addEventListener('keydown', (e) => {
        if (e.key !== 'Tab') return;
        const focusable = Array.from(chatGptModal.querySelectorAll(
            'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter(el => !el.closest('[hidden]'));
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    });
})();
