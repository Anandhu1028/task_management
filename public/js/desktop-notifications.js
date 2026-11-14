// Desktop (OS-level) notifications for TaskFlow.
// Polls the same endpoint as the bell icon and shows a system notification
// for every NEW unread notification. Works in the admin app and in the PWA.
(function () {
    'use strict';

    const button = document.querySelector('[data-tf-desktop-notify]');
    if (!button) return; // not on a logged-in page

    const pollUrl = button.dataset.pollUrl;
    const isPwa = button.dataset.mode === 'pwa';
    const STORAGE_KEY = 'tf.desktopNotify.seen';
    const POLL_MS = 10000;

    const supported = 'Notification' in window;

    const loadSeen = () => {
        try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null'); } catch (e) { return null; }
    };
    const saveSeen = (ids) => {
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(ids.slice(-100))); } catch (e) { /* ignore */ }
    };

    // ---- Button / permission UI -------------------------------------------
    const renderButton = () => {
        if (!supported) { button.hidden = true; return; }
        if (Notification.permission === 'granted') { button.hidden = true; return; }
        button.hidden = false;
        if (Notification.permission === 'denied') {
            const label = 'Desktop notifications are blocked. Change this site’s notification permission to allow them.';
            button.title = label;
            button.setAttribute('aria-label', label);
            button.classList.add('is-blocked');
        } else {
            button.title = 'Enable desktop notifications';
            button.setAttribute('aria-label', 'Enable desktop notifications');
            button.classList.remove('is-blocked');
        }
    };

    button.addEventListener('click', async () => {
        if (!supported) return;
        if (Notification.permission === 'denied') {
            alert('Notifications are blocked for this site.\nClick the lock icon in the address bar, set Notifications to "Allow", then reload.');
            return;
        }
        const result = await Notification.requestPermission();
        renderButton();
        if (result === 'granted') {
            show({
                id: 'tf-test',
                title: 'Desktop notifications enabled',
                message: 'You will now get alerts for new task updates.',
                url: null,
            }, true);
            poll();
        }
    });

    // ---- Showing a notification -------------------------------------------
    const targetUrl = (n) => (isPwa ? (n.pwa_url || n.url) : n.url) || null;

    async function show(n, force) {
        if (!supported || Notification.permission !== 'granted') return;
        const url = targetUrl(n);
        const options = {
            body: [n.message, n.task_label].filter(Boolean).join('\n'),
            icon: '/icons/task-manager-192.png',
            badge: '/icons/task-manager-192.png',
            tag: 'tf-' + n.id,          // same id in two tabs = one popup
            renotify: false,
            data: { url },
        };
        const title = n.title || 'Task update';

        try {
            const reg = 'serviceWorker' in navigator ? await navigator.serviceWorker.getRegistration() : null;
            if (reg && reg.active) {
                await reg.showNotification(title, options); // click handled in sw.js
                return;
            }
        } catch (e) { /* fall through to page notification */ }

        const note = new Notification(title, options);
        note.onclick = () => {
            window.focus();
            if (url) window.location.href = url;
            note.close();
        };
    }

    // ---- Polling -----------------------------------------------------------
    let inFlight = false;

    async function poll() {
        if (inFlight || !pollUrl) return;
        if (!supported || Notification.permission !== 'granted') return;
        inFlight = true;
        try {
            const response = await fetch(pollUrl, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (!response.ok || !(response.headers.get('content-type') || '').includes('json')) return;

            const data = await response.json();
            const items = Array.isArray(data.notifications) ? data.notifications : [];
            const seen = loadSeen();

            if (seen === null) {
                // First run on this browser: remember what exists, don't spam old items.
                saveSeen(items.map((n) => n.id));
                return;
            }

            const fresh = items.filter((n) => n.is_unread && !seen.includes(n.id)).reverse();
            if (fresh.length) {
                saveSeen(seen.concat(fresh.map((n) => n.id)));
                for (const n of fresh) await show(n);
            }
        } catch (e) {
            /* network hiccup — try again next tick */
        } finally {
            inFlight = false;
        }
    }

    renderButton();
    poll();
    setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
})();
