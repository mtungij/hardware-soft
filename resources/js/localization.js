// Read current-page translations on every call so Livewire navigation cannot retain a stale locale.
export const translate = (key, values = {}) => {
    const root = document.querySelector('[data-localization]');
    const messages = root ? JSON.parse(root.dataset.localization) : {};
    return Object.entries(values).reduce((text, [name, value]) => text.replaceAll(`:${name}`, String(value)), messages[key] || key);
};

const rememberOfflineLanguage = () => {
    const locale = document.documentElement.lang;
    if (['en', 'sw'].includes(locale)) {
        navigator.serviceWorker?.controller?.postMessage({
            type: 'SET_LOCALE',
            locale,
            scope: location.pathname.startsWith('/customer/') ? 'customer' : 'staff',
        });
    }
};
document.addEventListener('DOMContentLoaded', rememberOfflineLanguage);
document.addEventListener('livewire:navigated', rememberOfflineLanguage);
navigator.serviceWorker?.addEventListener('controllerchange', rememberOfflineLanguage);
