let deferredInstallPrompt;

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredInstallPrompt = event;
    document.querySelectorAll('[data-pwa-install]').forEach((button) => {
        button.hidden = false;
    });
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-pwa-install]');
    if (!button || !deferredInstallPrompt) {
        return;
    }

    deferredInstallPrompt.prompt();
    await deferredInstallPrompt.userChoice;
    deferredInstallPrompt = undefined;
    document.querySelectorAll('[data-pwa-install]').forEach((installButton) => {
        installButton.hidden = true;
    });
});
