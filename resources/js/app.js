import Chart from 'chart.js/auto';
import 'preline';

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

import './pwa-install';
import './onboarding';

const moneyFormatter = new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});

const normalizeMoneyValue = (value) => {
    const cleaned = String(value || '').replace(/[^\d.]/g, '');
    const parts = cleaned.split('.');
    const whole = parts.shift() || '';
    const decimal = parts.join('').slice(0, 2);

    if (!whole && !decimal) {
        return '';
    }

    return decimal ? `${whole || '0'}.${decimal}` : whole;
};

const formatMoneyValue = (value) => {
    const normalized = normalizeMoneyValue(value);

    if (!normalized) {
        return '';
    }

    const [whole, decimal] = normalized.split('.');
    const formatted = moneyFormatter.format(Number(whole || 0));

    if (decimal === undefined || /^0*$/.test(decimal)) {
        return formatted;
    }

    return `${formatted}.${decimal}`;
};

const moneyModelName = (value) => {
    const modelAttribute = Array.from(value.attributes).find((attribute) => attribute.name.startsWith('wire:model'));

    return modelAttribute?.value || null;
};

const moneyLivewireComponent = (field) => {
    const root = field.closest('[wire\\:id]');
    const id = root?.getAttribute('wire:id');

    return id && window.Livewire ? window.Livewire.find(id) : null;
};

const writePath = (source, path, nextValue) => {
    const keys = path.split('.');
    const last = keys.pop();
    const target = keys.reduce((value, key) => {
        value[key] ??= {};

        return value[key];
    }, source);

    target[last] = nextValue;
};

const setLivewireValue = (component, model, nextValue) => {
    if (!component || !model) {
        return;
    }

    if (typeof component.$wire?.$set === 'function') {
        component.$wire.$set(model, nextValue, true);

        return;
    }

    if (typeof component.$wire?.set === 'function') {
        component.$wire.set(model, nextValue, true);

        return;
    }

    if (component.reactive) {
        writePath(component.reactive, model, nextValue);
    }

    component.$wire?.$commit?.();
};

const initializeMoneyInputs = () => {
    document.querySelectorAll('[data-money-field]').forEach((field) => {
        if (field.dataset.moneyInitialized === '1') {
            const display = field.querySelector('[data-money-display]');
            const value = field.querySelector('[data-money-value]');

            if (display && value && document.activeElement !== display) {
                display.value = formatMoneyValue(value.value);
            }

            return;
        }

        const display = field.querySelector('[data-money-display]');
        const value = field.querySelector('[data-money-value]');

        if (!display || !value) {
            return;
        }

        const component = moneyLivewireComponent(field);
        const model = moneyModelName(value);

        const syncDisplay = () => {
            display.value = formatMoneyValue(value.value);
        };

        display.addEventListener('input', () => {
            const normalized = normalizeMoneyValue(display.value);
            display.value = formatMoneyValue(normalized);
            value.value = normalized;
            value.dispatchEvent(new Event('input', { bubbles: true }));

            setLivewireValue(component, model, normalized);
        });

        display.addEventListener('focus', syncDisplay);
        display.addEventListener('blur', syncDisplay);

        field.dataset.moneyInitialized = '1';
        syncDisplay();
    });
};

const updateMoneyInputDisplay = (model, nextValue) => {
    document.querySelectorAll('[data-money-field]').forEach((field) => {
        const value = field.querySelector('[data-money-value]');
        const display = field.querySelector('[data-money-display]');

        if (!value || !display || moneyModelName(value) !== model) {
            return;
        }

        value.value = normalizeMoneyValue(nextValue);
        display.value = formatMoneyValue(value.value);
    });
};

const initializePreline = () => {
    window.HSStaticMethods?.autoInit();
};

document.addEventListener('DOMContentLoaded', initializePreline);
document.addEventListener('livewire:navigated', initializePreline);
document.addEventListener('DOMContentLoaded', initializeMoneyInputs);
document.addEventListener('livewire:navigated', initializeMoneyInputs);
document.addEventListener('livewire:init', () => {
    initializeMoneyInputs();
    Livewire.hook('morph.updated', initializeMoneyInputs);
});
document.addEventListener('livewire:initialized', initializeMoneyInputs);
document.addEventListener('money-input-updated', (event) => {
    const detail = Array.isArray(event.detail) ? event.detail[0] : event.detail;

    if (!detail?.model) {
        return;
    }

    updateMoneyInputDisplay(detail.model, detail.value ?? '');
});
