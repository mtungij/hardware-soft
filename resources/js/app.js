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

    window.setTimeout(() => {
        document.querySelectorAll('[data-hs-select]').forEach((select) => {
            const instance = window.HSSelect?.getInstance?.(select);

            if (instance && typeof instance.setValue === 'function') {
                instance.setValue(select.value || '');
            }
        });
    });
};

const imageCropState = {
    root: null,
    input: null,
    image: null,
    url: '',
    scale: 1,
    minScale: 1,
    offsetX: 0,
    offsetY: 0,
    dragging: false,
    lastX: 0,
    lastY: 0,
};

const imageCropModal = () => {
    let modal = document.querySelector('[data-image-crop-modal]');

    if (modal) {
        return modal;
    }

    modal = document.createElement('div');
    modal.dataset.imageCropModal = '1';
    modal.className = 'fixed inset-0 z-[9999] hidden place-items-center bg-slate-950/80 p-4 backdrop-blur-sm';
    modal.innerHTML = `
        <div class="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-4 text-slate-900 shadow-2xl dark:border-slate-700 dark:bg-slate-900 dark:text-white">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-lg font-black">Crop Profile Picture</h3>
                    <p class="mt-1 text-sm font-semibold text-slate-500 dark:text-slate-400">Drag the picture and adjust zoom before using it.</p>
                </div>
                <button type="button" class="rounded-lg px-2 py-1 text-sm font-black text-slate-500 hover:bg-slate-100 dark:hover:bg-white/10" data-image-crop-cancel>Close</button>
            </div>
            <div class="mt-4 flex justify-center">
                <canvas data-image-crop-canvas width="320" height="320" class="aspect-square w-full max-w-80 touch-none rounded-2xl border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-950"></canvas>
            </div>
            <label class="mt-4 block text-sm font-bold text-slate-700 dark:text-slate-200">
                Zoom
                <input data-image-crop-zoom type="range" min="1" max="3" step="0.01" value="1" class="mt-2 w-full accent-cyan-500">
            </label>
            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700" data-image-crop-cancel>Cancel</button>
                <button type="button" class="rounded-xl bg-cyan-500 px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-cyan-500/20" data-image-crop-apply>Use Cropped Picture</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);

    return modal;
};

const drawImageCrop = () => {
    const canvas = imageCropModal().querySelector('[data-image-crop-canvas]');
    const context = canvas.getContext('2d');
    const { image, scale, offsetX, offsetY } = imageCropState;

    if (!image) {
        return;
    }

    context.clearRect(0, 0, canvas.width, canvas.height);
    context.fillStyle = document.documentElement.classList.contains('dark') ? '#020617' : '#f1f5f9';
    context.fillRect(0, 0, canvas.width, canvas.height);

    const width = image.width * scale;
    const height = image.height * scale;
    const x = (canvas.width - width) / 2 + offsetX;
    const y = (canvas.height - height) / 2 + offsetY;

    context.drawImage(image, x, y, width, height);
    context.strokeStyle = 'rgba(6, 182, 212, 0.9)';
    context.lineWidth = 3;
    context.strokeRect(1.5, 1.5, canvas.width - 3, canvas.height - 3);
};

const clampImageCropOffset = () => {
    const canvas = imageCropModal().querySelector('[data-image-crop-canvas]');
    const width = imageCropState.image.width * imageCropState.scale;
    const height = imageCropState.image.height * imageCropState.scale;
    const maxX = Math.max(0, (width - canvas.width) / 2);
    const maxY = Math.max(0, (height - canvas.height) / 2);

    imageCropState.offsetX = Math.max(-maxX, Math.min(maxX, imageCropState.offsetX));
    imageCropState.offsetY = Math.max(-maxY, Math.min(maxY, imageCropState.offsetY));
};

const closeImageCrop = () => {
    const modal = imageCropModal();

    modal.classList.add('hidden');
    modal.classList.remove('grid');

    if (imageCropState.url) {
        URL.revokeObjectURL(imageCropState.url);
    }

    imageCropState.root = null;
    imageCropState.input = null;
    imageCropState.image = null;
    imageCropState.url = '';
};

const openImageCrop = (root, input, file) => {
    const modal = imageCropModal();
    const canvas = modal.querySelector('[data-image-crop-canvas]');
    const zoom = modal.querySelector('[data-image-crop-zoom]');
    const image = new Image();

    imageCropState.root = root;
    imageCropState.input = input;
    imageCropState.url = URL.createObjectURL(file);

    image.onload = () => {
        imageCropState.image = image;
        imageCropState.minScale = Math.max(canvas.width / image.width, canvas.height / image.height);
        imageCropState.scale = imageCropState.minScale;
        imageCropState.offsetX = 0;
        imageCropState.offsetY = 0;
        zoom.min = String(imageCropState.minScale);
        zoom.max = String(Math.max(imageCropState.minScale * 3, imageCropState.minScale + 1));
        zoom.value = String(imageCropState.scale);
        modal.classList.remove('hidden');
        modal.classList.add('grid');
        drawImageCrop();
    };

    image.src = imageCropState.url;
};

const applyImageCrop = () => {
    const canvas = imageCropModal().querySelector('[data-image-crop-canvas]');
    const outputSize = Number(imageCropState.root?.dataset.previewSize || 512);
    const output = document.createElement('canvas');
    const outputContext = output.getContext('2d');

    output.width = outputSize;
    output.height = outputSize;
    outputContext.drawImage(canvas, 0, 0, outputSize, outputSize);

    output.toBlob((blob) => {
        if (!blob || !imageCropState.input) {
            return;
        }

        const file = new File([blob], `profile-picture-${Date.now()}.jpg`, { type: 'image/jpeg' });
        const transfer = new DataTransfer();
        transfer.items.add(file);
        const input = imageCropState.input;
        input.dataset.cropApplying = '1';
        input.files = transfer.files;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        window.setTimeout(() => delete input.dataset.cropApplying);
        closeImageCrop();
    }, 'image/jpeg', 0.92);
};

const initializeImageCropUploads = () => {
    const modal = imageCropModal();
    const canvas = modal.querySelector('[data-image-crop-canvas]');
    const zoom = modal.querySelector('[data-image-crop-zoom]');

    if (modal.dataset.initialized !== '1') {
        modal.querySelectorAll('[data-image-crop-cancel]').forEach((button) => {
            button.addEventListener('click', () => {
                if (imageCropState.input) {
                    imageCropState.input.value = '';
                }

                closeImageCrop();
            });
        });
        modal.querySelector('[data-image-crop-apply]')?.addEventListener('click', applyImageCrop);
        zoom.addEventListener('input', () => {
            imageCropState.scale = Number(zoom.value);
            clampImageCropOffset();
            drawImageCrop();
        });
        canvas.addEventListener('pointerdown', (event) => {
            imageCropState.dragging = true;
            imageCropState.lastX = event.clientX;
            imageCropState.lastY = event.clientY;
            canvas.setPointerCapture(event.pointerId);
        });
        canvas.addEventListener('pointermove', (event) => {
            if (!imageCropState.dragging) {
                return;
            }

            imageCropState.offsetX += event.clientX - imageCropState.lastX;
            imageCropState.offsetY += event.clientY - imageCropState.lastY;
            imageCropState.lastX = event.clientX;
            imageCropState.lastY = event.clientY;
            clampImageCropOffset();
            drawImageCrop();
        });
        canvas.addEventListener('pointerup', () => {
            imageCropState.dragging = false;
        });
        canvas.addEventListener('pointercancel', () => {
            imageCropState.dragging = false;
        });
        modal.dataset.initialized = '1';
    }

    document.querySelectorAll('[data-image-crop-upload]').forEach((root) => {
        const input = root.querySelector('[data-image-crop-input]');

        if (!input || input.dataset.cropInitialized === '1') {
            return;
        }

        input.addEventListener('change', () => {
            if (input.dataset.cropApplying === '1') {
                return;
            }

            const file = input.files?.[0];

            if (!file || !file.type.startsWith('image/')) {
                return;
            }

            openImageCrop(root, input, file);
        });

        input.dataset.cropInitialized = '1';
    });
};

document.addEventListener('DOMContentLoaded', initializePreline);
document.addEventListener('livewire:navigated', initializePreline);
document.addEventListener('open-modal', () => window.setTimeout(initializePreline, 50));
document.addEventListener('DOMContentLoaded', initializeMoneyInputs);
document.addEventListener('livewire:navigated', initializeMoneyInputs);
document.addEventListener('DOMContentLoaded', initializeImageCropUploads);
document.addEventListener('livewire:navigated', initializeImageCropUploads);
document.addEventListener('livewire:init', () => {
    initializeMoneyInputs();
    initializePreline();
    initializeImageCropUploads();
    Livewire.hook('morph.updated', initializeMoneyInputs);
    Livewire.hook('morph.updated', initializePreline);
    Livewire.hook('morph.updated', initializeImageCropUploads);
});
document.addEventListener('livewire:initialized', initializeMoneyInputs);
document.addEventListener('livewire:initialized', initializePreline);
document.addEventListener('livewire:initialized', initializeImageCropUploads);
document.addEventListener('money-input-updated', (event) => {
    const detail = Array.isArray(event.detail) ? event.detail[0] : event.detail;

    if (!detail?.model) {
        return;
    }

    updateMoneyInputDisplay(detail.model, detail.value ?? '');
});
