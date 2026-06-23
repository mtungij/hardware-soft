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

import './pwa-install';
