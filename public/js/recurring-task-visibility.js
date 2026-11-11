(() => {
    const transitionMeta = document.querySelector('meta[name="recurring-task-next-transition"]');
    const delayInMilliseconds = Number(transitionMeta?.dataset.delayMs);

    if (!Number.isFinite(delayInMilliseconds) || delayInMilliseconds < 0) {
        return;
    }

    const transitionAt = performance.now() + delayInMilliseconds;
    let refreshStarted = false;
    let timeoutId;

    const refreshAtTransition = () => {
        if (performance.now() >= transitionAt) {
            if (!refreshStarted) {
                refreshStarted = true;
                window.location.reload();
            }

            return;
        }

        window.clearTimeout(timeoutId);
        timeoutId = window.setTimeout(
            refreshAtTransition,
            Math.min(transitionAt - performance.now() + 100, 2147483647),
        );
    };

    window.addEventListener('pageshow', refreshAtTransition);
    document.addEventListener('visibilitychange', refreshAtTransition);
    refreshAtTransition();
})();
