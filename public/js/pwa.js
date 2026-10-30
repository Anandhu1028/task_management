// TaskFlow PWA — UI behaviour only. No task data is read, stored, or cached here.
document.addEventListener('click', (event) => {
    const refresh = event.target.closest('[data-refresh]');
    if (refresh) { window.location.reload(); return; }

    const toggle = event.target.closest('[data-password-toggle]');
    if (toggle) {
        const input = document.getElementById('password');
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        toggle.textContent = show ? 'Hide' : 'Show';
        toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        return;
    }

    const tab = event.target.closest('[data-tab]');
    if (tab) {
        document.querySelectorAll('[data-tab]').forEach((el) => {
            const active = el === tab;
            el.classList.toggle('is-active', active);
            el.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        document.querySelectorAll('[data-panel]').forEach((panel) => {
            panel.classList.toggle('is-active', panel.dataset.panel === tab.dataset.tab);
        });
    }
});

// Keep the selected task visible in the split-view list.
document.querySelector('.pwa-row.is-selected')?.scrollIntoView({ block: 'nearest' });

// Only one action form open at a time.
document.querySelectorAll('.pwa-action').forEach((item) => {
    item.addEventListener('toggle', () => {
        if (!item.open) return;
        document.querySelectorAll('.pwa-action[open]').forEach((other) => { if (other !== item) other.open = false; });
    });
});
