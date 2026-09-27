(() => {
    let deferredInstallPrompt = null;

    const dismissKey = 'hardex_pwa_install_dismissed_at';
    const successMessage = () => `${document.querySelector('[data-pwa-install-root]')?.dataset.pwaBrandName || 'Hardex'} App imewekwa kwenye kifaa chako kikamilifu.`;
    const unavailableMessage = 'Install haijapatikana sasa. Tumia menu ya browser kisha chagua Install app au Add to Home Screen.';

    const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    const isIos = () => /iphone|ipad|ipod/i.test(window.navigator.userAgent);
    const isSafari = () => /^((?!chrome|android|crios|fxios).)*safari/i.test(window.navigator.userAgent);
    const isIosSafari = () => isIos() && isSafari() && !isStandalone();
    const installButtons = () => Array.from(document.querySelectorAll('[data-pwa-install-button]'));

    const dismissedRecently = () => {
        const dismissedAt = Number(localStorage.getItem(dismissKey) || 0);

        return dismissedAt > 0 && Date.now() - dismissedAt < 24 * 60 * 60 * 1000;
    };

    const setInstallButtonsVisible = (visible) => {
        installButtons().forEach((button) => {
            button.classList.toggle('hidden', !visible);
            button.disabled = !visible;
            button.setAttribute('aria-hidden', visible ? 'false' : 'true');
        });
    };

    const showToast = (message) => {
        const toast = document.createElement('div');
        toast.textContent = message;
        toast.className = 'fixed bottom-5 left-1/2 z-[9999] w-[calc(100%-2rem)] max-w-md -translate-x-1/2 rounded-xl bg-slate-950 px-4 py-3 text-center text-sm font-bold text-white shadow-2xl dark:bg-white dark:text-slate-950';
        document.body.appendChild(toast);

        window.setTimeout(() => {
            toast.classList.add('opacity-0', 'transition-opacity');
            window.setTimeout(() => toast.remove(), 300);
        }, 3600);
    };

    const showIosModal = (button) => {
        const modal = button.closest('[data-pwa-install-root]')?.querySelector('[data-pwa-ios-modal]');

        if (!modal) {
            return;
        }

        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
    };

    const hideIosModal = (button) => {
        const modal = button.closest('[data-pwa-install-root]')?.querySelector('[data-pwa-ios-modal]');

        if (!modal) {
            return;
        }

        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
    };

    const showModal = (button, selector) => {
        const modal = button.closest('[data-pwa-install-root]')?.querySelector(selector);

        if (!modal) {
            return;
        }

        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
    };

    const hideModal = (button, selector) => {
        const modal = button.closest('[data-pwa-install-root]')?.querySelector(selector);

        if (!modal) {
            return;
        }

        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
    };

    const showHelpModal = (button) => {
        showModal(button, '[data-pwa-help-modal]');
    };

    const shouldShowInstallButton = () => {
        if (isStandalone()) {
            return false;
        }

        return true;
    };

    const canDeferInstallPrompt = () => installButtons().length > 0 && shouldShowInstallButton() && !dismissedRecently();

    const refreshInstallButtons = () => {
        setInstallButtonsVisible(shouldShowInstallButton());
    };

    const bindInstallButtons = () => {
        installButtons().forEach((button) => {
            if (button.dataset.pwaBound === 'true') {
                return;
            }

            button.dataset.pwaBound = 'true';
            button.addEventListener('click', async () => {
                if (isStandalone()) {
                    setInstallButtonsVisible(false);
                    return;
                }

                if (isIosSafari() && !deferredInstallPrompt) {
                    showIosModal(button);
                    return;
                }

                if (!deferredInstallPrompt) {
                    showHelpModal(button);
                    showToast(unavailableMessage);
                    return;
                }

                button.dataset.loading = 'true';
                button.setAttribute('aria-busy', 'true');
                button.querySelector('[data-pwa-install-label]')?.classList.add('hidden');
                button.querySelector('[data-pwa-install-loading]')?.classList.remove('hidden');

                try {
                    await deferredInstallPrompt.prompt();
                    const choice = await deferredInstallPrompt.userChoice;

                    if (choice.outcome === 'accepted') {
                        localStorage.removeItem(dismissKey);
                        setInstallButtonsVisible(false);
                    } else {
                        localStorage.setItem(dismissKey, String(Date.now()));
                    }
                } catch (error) {
                    console.warn('Hardex install prompt failed.', error);
                    showToast(unavailableMessage);
                } finally {
                    button.dataset.loading = 'false';
                    button.setAttribute('aria-busy', 'false');
                    button.querySelector('[data-pwa-install-label]')?.classList.remove('hidden');
                    button.querySelector('[data-pwa-install-loading]')?.classList.add('hidden');
                    deferredInstallPrompt = null;
                    refreshInstallButtons();
                }
            });
        });

        document.querySelectorAll('[data-pwa-ios-close]').forEach((button) => {
            if (button.dataset.pwaBound === 'true') {
                return;
            }

            button.dataset.pwaBound = 'true';
            button.addEventListener('click', () => {
                localStorage.setItem(dismissKey, String(Date.now()));
                hideIosModal(button);
                refreshInstallButtons();
            });
        });

        document.querySelectorAll('[data-pwa-help-close]').forEach((button) => {
            if (button.dataset.pwaBound === 'true') {
                return;
            }

            button.dataset.pwaBound = 'true';
            button.addEventListener('click', () => {
                hideModal(button, '[data-pwa-help-modal]');
            });
        });

        refreshInstallButtons();
    };

    window.addEventListener('beforeinstallprompt', (event) => {
        if (!canDeferInstallPrompt()) {
            return;
        }

        event.preventDefault();
        deferredInstallPrompt = event;
        localStorage.removeItem(dismissKey);
        bindInstallButtons();
        refreshInstallButtons();
    });

    window.addEventListener('appinstalled', () => {
        deferredInstallPrompt = null;
        localStorage.removeItem(dismissKey);
        setInstallButtonsVisible(false);
        showToast(successMessage());
        rememberCurrentBrand();
    });

    document.addEventListener('DOMContentLoaded', bindInstallButtons);
    document.addEventListener('livewire:navigated', bindInstallButtons);

    const brandVersionMeta = () => document.querySelector('meta[name="pwa-brand-version"]');
    const brandStorageKey = () => `hardex_pwa_brand_version:${document.querySelector('meta[name="pwa-tenant-key"]')?.content || 'generic'}`;
    const brandNotice = 'App branding has been updated. Your browser may require reinstalling the app to update the installed app name or icon.';
    let workerRegistration = null;

    const rememberCurrentBrand = () => {
        const version = brandVersionMeta()?.content;
        if (version) {
            localStorage.setItem(brandStorageKey(), version);
        }
    };

    const refreshBranding = async (showLegacyNotice = false) => {
        const manifestLink = document.querySelector('[data-pwa-manifest]');
        if (!manifestLink) {
            return;
        }

        try {
            const manifestUrl = new URL(manifestLink.href);
            manifestUrl.searchParams.delete('v');
            const response = await fetch(manifestUrl, {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'application/manifest+json' }
            });
            if (!response.ok) {
                return;
            }
            const manifest = await response.json();
            const version = manifest.version;
            if (!version) {
                return;
            }
            const previous = localStorage.getItem(brandStorageKey());
            const changed = previous && previous !== version;
            if (isStandalone() && (changed || showLegacyNotice)) {
                showToast(brandNotice);
            }
            localStorage.setItem(brandStorageKey(), version);
            brandVersionMeta().content = version;
            manifestUrl.searchParams.set('v', version);
            manifestLink.href = manifestUrl.toString();

            const icon192 = manifest.icons?.find((icon) => icon.sizes === '192x192')?.src;
            if (icon192) {
                document.querySelectorAll('[data-pwa-icon], [data-pwa-apple-icon]').forEach((link) => {
                    link.href = icon192;
                });
            }
            document.querySelector('[data-pwa-theme-color]')?.setAttribute('content', manifest.theme_color);
            document.querySelector('[data-pwa-app-name]')?.setAttribute('content', manifest.name);
            document.querySelector('[data-pwa-application-name]')?.setAttribute('content', manifest.name);
            document.querySelectorAll('[data-pwa-install-root]').forEach((root) => {
                root.dataset.pwaBrandName = manifest.name;
                const button = root.querySelector('[data-pwa-install-button]');
                button?.setAttribute('aria-label', `Install ${manifest.name} App`);
                button?.setAttribute('title', `Install ${manifest.name} App`);
            });
        } catch (error) {
            console.warn('PWA branding refresh failed.', error);
        }
    };

    const checkForUpdates = async () => {
        await refreshBranding();
        try {
            await workerRegistration?.update();
        } catch (error) {
            console.warn('PWA update check failed.', error);
        }
    };

    window.addEventListener('hardex-brand-updated', checkForUpdates);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            checkForUpdates();
        }
    });

    window.addEventListener('load', async () => {
        const isLocalHost = ['localhost', '127.0.0.1', '::1'].includes(window.location.hostname);
        let hadLegacyCache = false;
        if ('caches' in window) {
            const keys = await caches.keys();
            hadLegacyCache = keys.some((key) => key.startsWith('hardex-customer-pwa-v4'));
            if (isLocalHost) {
                await Promise.all(keys.filter((key) => key.startsWith('hardex-')).map((key) => caches.delete(key)));
            }
        }

        await refreshBranding(hadLegacyCache && !localStorage.getItem(brandStorageKey()));

        if (!('serviceWorker' in navigator)) {
            return;
        }
        if (isLocalHost) {
            const registrations = await navigator.serviceWorker.getRegistrations();
            await Promise.all(registrations.map((registration) => registration.unregister()));
            return;
        }

        try {
            workerRegistration = await navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' });
            workerRegistration.addEventListener('updatefound', () => {
                const worker = workerRegistration.installing;
                worker?.addEventListener('statechange', () => {
                    if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                        worker.postMessage({ type: 'SKIP_WAITING' });
                    }
                });
            });
            await workerRegistration.update();
        } catch (error) {
            console.warn('Hardex service worker registration failed.', error);
        }
    });

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('controllerchange', () => {
            // New assets are used on the next navigation; avoid discarding unsaved work.
            refreshBranding();
        });
    }
})();
