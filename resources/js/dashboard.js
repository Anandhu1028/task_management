document.addEventListener('DOMContentLoaded', function () {

    const body = document.body;
    const html = document.documentElement;

    /* ---------- Sidebar (mobile) ---------- */
    document.querySelector('[data-tf-open-sidebar]')
        ?.addEventListener('click', () => body.classList.add('tf-sidebar-open'));

    document.querySelector('[data-tf-overlay]')
        ?.addEventListener('click', () => body.classList.remove('tf-sidebar-open'));

    /* ---------- Theme toggle ---------- */
    const themeBtn = document.querySelector('[data-tf-theme]');

    function applyTheme(theme) {
        html.setAttribute('data-theme', theme);
        html.setAttribute('data-bs-theme', theme);
        const icon = themeBtn?.querySelector('i');
        if (icon) icon.className = theme === 'dark' ? 'bi bi-moon-stars' : 'bi bi-brightness-high';
    }

    let savedTheme = 'light';
    try { savedTheme = localStorage.getItem('tf_theme') || 'light'; } catch (e) {}
    applyTheme(savedTheme);

    themeBtn?.addEventListener('click', () => {
        const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        try { localStorage.setItem('tf_theme', next); } catch (e) {}
    });

    /* ---------- Tooltips ---------- */
    document.querySelectorAll('[data-bs-toggle="tooltip"]')
        .forEach(el => new bootstrap.Tooltip(el));

    /* ---------- Dropdown pickers (updates button label) ---------- */
    document.querySelectorAll('[data-tf-pick]').forEach(menu => {
        menu.querySelectorAll('.dropdown-item').forEach(item => {
            item.addEventListener('click', () => {
                menu.querySelectorAll('.dropdown-item').forEach(i => i.classList.remove('active'));
                item.classList.add('active');
                const label = menu.parentElement.querySelector('[data-label]');
                if (label) label.textContent = item.textContent.trim();
            });
        });
    });

    /* ---------- Toast ---------- */
    const toast = document.getElementById('tfToast');
    let toastTimer;

    function showToast(message) {
        if (!toast) return;
        toast.querySelector('[data-tf-toast-message]').textContent = message;
        toast.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => (toast.hidden = true), 2600);
    }

    /* ---------- Create task form ---------- */
    const form = document.getElementById('createTaskForm');
    form?.addEventListener('submit', function (e) {
        e.preventDefault();
        // TODO: post to your backend here (fetch / axios)
        bootstrap.Modal.getInstance(document.getElementById('createTaskModal'))?.hide();
        form.reset();
        showToast('Task created');
    });

    /* ---------- Notifications: mark all read ---------- */
    document.querySelector('[data-tf-mark-read]')?.addEventListener('click', () => {
        document.querySelectorAll('.tf-notify.is-unread').forEach(n => n.classList.remove('is-unread'));
        document.querySelector('.tf-bell__dot')?.remove();
    });

    /* ---------- Task tabs + priority filter ---------- */
    let activeTab = 'all';
    let activePriority = 'all';

    function applyTaskFilters() {
        const items = document.querySelectorAll('#taskTableBody tr, #taskCardList .tf-task-card');
        let visible = 0;

        items.forEach(el => {
            const tabs = (el.dataset.tabs || '').split(' ');
            const okTab = activeTab === 'all' || tabs.includes(activeTab);
            const okPriority = activePriority === 'all' || el.dataset.priority === activePriority;
            const show = okTab && okPriority;
            el.hidden = !show;
            if (show) visible++;
        });

        const empty = document.getElementById('taskEmpty');
        if (empty) empty.hidden = visible > 0;
    }

    document.querySelectorAll('[data-tf-tab]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('[data-tf-tab]').forEach(b => b.classList.remove('is-active'));
            btn.classList.add('is-active');
            activeTab = btn.dataset.tfTab;
            applyTaskFilters();
        });
    });

    document.querySelectorAll('[data-tf-filter="priority"]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('[data-tf-filter="priority"]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activePriority = btn.dataset.value;
            applyTaskFilters();
        });
    });

    /* =====================================================
       CHARTS
    ===================================================== */
    if (!window.Chart) return;

    const BLUE = '#2f5bea';
    const GRID = 'rgba(138, 149, 173, .18)';
    const TICK = '#8a95ad';

    Chart.defaults.font.family = "'Inter', system-ui, sans-serif";

    function gradient(ctx, area, color, from = .22) {
        const g = ctx.createLinearGradient(0, area.top, 0, area.bottom);
        g.addColorStop(0, hexToRgba(color, from));
        g.addColorStop(1, hexToRgba(color, 0));
        return g;
    }

    function hexToRgba(hex, alpha) {
        const n = parseInt(hex.replace('#', ''), 16);
        return `rgba(${n >> 16}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
    }

    /* ---------- KPI sparklines ---------- */
    document.querySelectorAll('canvas[data-spark]').forEach(canvas => {
        const data = JSON.parse(canvas.dataset.spark);
        const color = canvas.dataset.color || BLUE;

        new Chart(canvas, {
            type: 'line',
            data: {
                labels: data.map((_, i) => i),
                datasets: [{
                    data,
                    borderColor: color,
                    borderWidth: 1.6,
                    tension: 0.45,
                    pointRadius: 0,
                    fill: true,
                    backgroundColor: c => c.chart.chartArea
                        ? gradient(c.chart.ctx, c.chart.chartArea, color, .18)
                        : 'transparent',
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                events: [],
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: { x: { display: false }, y: { display: false } },
                layout: { padding: 1 },
            },
        });
    });

    /* ---------- Task completion (line) ---------- */
    const completionCanvas = document.getElementById('completionChart');

    if (completionCanvas) {
        const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        const chart = new Chart(completionCanvas, {
            type: 'line',
            data: {
                labels: days,
                datasets: [{
                    data: [4, 6, 5, 8, 6, 2, 5],
                    borderColor: BLUE,
                    borderWidth: 2,
                    tension: 0.42,
                    fill: true,
                    backgroundColor: c => c.chart.chartArea
                        ? gradient(c.chart.ctx, c.chart.chartArea, BLUE, .25)
                        : 'transparent',
                    pointRadius: 3.5,
                    pointHoverRadius: 5,
                    pointBackgroundColor: BLUE,
                    pointBorderColor: BLUE,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        displayColors: false,
                        backgroundColor: '#ffffff',
                        titleColor: '#0f1a35',
                        bodyColor: '#5b6885',
                        borderColor: '#e6eaf3',
                        borderWidth: 1,
                        padding: 10,
                        cornerRadius: 8,
                        titleFont: { weight: '700', size: 11 },
                        bodyFont: { size: 10 },
                        callbacks: {
                            title: items => `${items[0].parsed.y} tasks`,
                            label: item => `Completed on ${item.label}`,
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { color: GRID, drawTicks: false },
                        border: { display: false },
                        ticks: { color: TICK, font: { size: 10.5 }, padding: 8 },
                    },
                    y: {
                        min: 0,
                        max: 10,
                        ticks: { stepSize: 2, color: TICK, font: { size: 10.5 }, padding: 8 },
                        grid: { color: GRID, drawTicks: false },
                        border: { display: false },
                    },
                },
            },
        });

        // Show the tooltip on Thursday by default, like the design
        const thu = [{ datasetIndex: 0, index: 3 }];
        chart.setActiveElements(thu);
        chart.tooltip.setActiveElements(thu, { x: 0, y: 0 });
        chart.update();
    }

    /* ---------- Task status (doughnut) ---------- */
    const statusCanvas = document.getElementById('statusChart');

    if (statusCanvas) {
        const statusLabels = JSON.parse(statusCanvas.dataset.labels || '["Notified", "Unassigned", "Assigned", "In Progress", "On Hold", "At Risk", "Overdue", "Submitted for Review", "Completed", "Cancelled"]');
        const statusValues = JSON.parse(statusCanvas.dataset.values || '[0, 0, 0, 0, 0, 0, 0, 0, 0, 0]');
        const statusColors = JSON.parse(statusCanvas.dataset.colors || '["#64748b", "#2563eb", "#0ea5e9", "#f97316", "#a16207", "#dc2626", "#b91c1c", "#8b5cf6", "#22c55e", "#64748b"]');

        new Chart(statusCanvas, {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusValues,
                    backgroundColor: statusColors,
                    borderColor: '#ffffff',
                    borderWidth: 2,
                    hoverOffset: 3,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '66%',
                plugins: { legend: { display: false } },
            },
        });
    }

    /* ---------- Workload (bar with value labels) ---------- */
    const workloadCanvas = document.getElementById('workloadChart');

    if (workloadCanvas) {
        const valueLabels = {
            id: 'valueLabels',
            afterDatasetsDraw(chart) {
                const { ctx } = chart;
                ctx.save();
                ctx.fillStyle = getComputedStyle(document.body).color;
                ctx.font = "700 11px 'Inter', sans-serif";
                ctx.textAlign = 'center';
                chart.getDatasetMeta(0).data.forEach((bar, i) => {
                    ctx.fillText(chart.data.datasets[0].data[i], bar.x, bar.y - 6);
                });
                ctx.restore();
            },
        };

        new Chart(workloadCanvas, {
            type: 'bar',
            data: {
                labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                datasets: [{
                    data: [5, 8, 6, 9, 4],
                    backgroundColor: BLUE,
                    borderRadius: { topLeft: 3, topRight: 3 },
                    borderSkipped: false,
                    maxBarThickness: 32,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 16 } },
                plugins: { legend: { display: false } },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { color: TICK, font: { size: 10.5 } },
                    },
                    y: {
                        min: 0,
                        max: 10,
                        ticks: { stepSize: 2, color: TICK, font: { size: 10.5 }, padding: 8 },
                        grid: { color: GRID, drawTicks: false },
                        border: { display: false },
                    },
                },
            },
            plugins: [valueLabels],
        });
    }
});