import Chart from 'chart.js/auto';

window.Chart = Chart;

window.buildMartThemeColor = () => getComputedStyle(document.documentElement).getPropertyValue('--build-theme').trim() || '#f97316';

window.buildMartThemeColorAlpha = (alpha = 1) => {
    const hex = window.buildMartThemeColor().replace('#', '');

    if (!/^[0-9a-fA-F]{6}$/.test(hex)) {
        return `rgba(249, 115, 22, ${alpha})`;
    }

    const red = parseInt(hex.slice(0, 2), 16);
    const green = parseInt(hex.slice(2, 4), 16);
    const blue = parseInt(hex.slice(4, 6), 16);

    return `rgba(${red}, ${green}, ${blue}, ${alpha})`;
};

window.buildMartChart = (canvas, config) => {
    if (!canvas) {
        return null;
    }

    if (canvas._buildMartChart) {
        canvas._buildMartChart.destroy();
    }

    const darkMode = document.documentElement.classList.contains('dark');
    const gridColor = darkMode ? 'rgba(148, 163, 184, 0.18)' : 'rgba(15, 23, 42, 0.08)';
    const textColor = darkMode ? '#cbd5e1' : '#475569';

    canvas._buildMartChart = new Chart(canvas, {
        ...config,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: {
                    labels: { color: textColor, boxWidth: 10, usePointStyle: true },
                },
                tooltip: {
                    backgroundColor: darkMode ? '#0f172a' : '#ffffff',
                    borderColor: darkMode ? '#334155' : '#e2e8f0',
                    borderWidth: 1,
                    titleColor: darkMode ? '#ffffff' : '#0f172a',
                    bodyColor: darkMode ? '#cbd5e1' : '#475569',
                },
                ...(config.options?.plugins || {}),
            },
            scales: {
                x: {
                    ticks: { color: textColor },
                    grid: { color: gridColor },
                    ...(config.options?.scales?.x || {}),
                },
                y: {
                    beginAtZero: true,
                    ticks: { color: textColor },
                    grid: { color: gridColor },
                    ...(config.options?.scales?.y || {}),
                },
                ...(config.options?.scales || {}),
            },
            ...(config.options || {}),
        },
    });

    return canvas._buildMartChart;
};

(() => {
    let deferredInstallPrompt = null;
    let refreshing = false;

    const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

    const installButtons = () => Array.from(document.querySelectorAll('[data-pwa-install-button]'));

    const setInstallButtonsVisible = (visible) => {
        installButtons().forEach((button) => {
            button.classList.toggle('hidden', !visible);
            button.disabled = !visible;
            button.setAttribute('aria-hidden', visible ? 'false' : 'true');
        });
    };

    const showHardexToast = (message) => {
        const toast = document.createElement('div');
        toast.textContent = message;
        toast.className = 'fixed bottom-5 left-1/2 z-[9999] -translate-x-1/2 rounded-xl bg-slate-950 px-4 py-3 text-sm font-bold text-white shadow-2xl dark:bg-white dark:text-slate-950';
        document.body.appendChild(toast);

        window.setTimeout(() => {
            toast.classList.add('opacity-0', 'transition-opacity');
            window.setTimeout(() => toast.remove(), 300);
        }, 3200);
    };

    const bindInstallButtons = () => {
        installButtons().forEach((button) => {
            if (button.dataset.pwaBound === 'true') {
                return;
            }

            button.dataset.pwaBound = 'true';
            button.addEventListener('click', async () => {
                if (!deferredInstallPrompt || isStandalone()) {
                    setInstallButtonsVisible(false);
                    return;
                }

                deferredInstallPrompt.prompt();
                const choice = await deferredInstallPrompt.userChoice;

                if (choice.outcome === 'accepted') {
                    setInstallButtonsVisible(false);
                }

                deferredInstallPrompt = null;
            });
        });

        setInstallButtonsVisible(Boolean(deferredInstallPrompt) && !isStandalone());
    };

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredInstallPrompt = event;
        bindInstallButtons();
        setInstallButtonsVisible(!isStandalone());
    });

    window.addEventListener('appinstalled', () => {
        deferredInstallPrompt = null;
        setInstallButtonsVisible(false);
        showHardexToast('Hardex App Installed Successfully');
    });

    document.addEventListener('DOMContentLoaded', bindInstallButtons);
    document.addEventListener('livewire:navigated', bindInstallButtons);

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', async () => {
            try {
                const registration = await navigator.serviceWorker.register('/sw.js');

                registration.addEventListener('updatefound', () => {
                    const worker = registration.installing;

                    if (!worker) {
                        return;
                    }

                    worker.addEventListener('statechange', () => {
                        if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                            worker.postMessage({ type: 'SKIP_WAITING' });
                        }
                    });
                });
            } catch (error) {
                console.warn('Hardex service worker registration failed.', error);
            }
        });

        navigator.serviceWorker.addEventListener('controllerchange', () => {
            if (refreshing) {
                return;
            }

            refreshing = true;
            window.location.reload();
        });
    }
})();
