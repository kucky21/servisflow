/**
 * ServisHub – grafy na dashboardu (Chart.js). Data pocházejí z PostgreSQL přes presenter.
 */
(() => {
    'use strict';

    const source = document.getElementById('dashboard-charts');
    if (!source || !window.Chart) return;

    const data = JSON.parse(source.textContent || '{}');
    const TONES = {
        slate: '#94a3b8', indigo: '#6366f1', amber: '#f59e0b', blue: '#3b82f6',
        orange: '#f97316', green: '#22c55e', teal: '#14b8a6', red: '#ef4444',
    };
    const czk = new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: 'CZK', maximumFractionDigits: 0 });

    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.font.size = 12;
    Chart.defaults.color = '#64748b';
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.boxWidth = 8;
    Chart.defaults.plugins.tooltip.backgroundColor = '#0f172a';
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.cornerRadius = 8;

    const grid = { color: 'rgba(148, 163, 184, .18)', drawTicks: false };

    const statusCanvas = document.getElementById('chart-status');
    if (statusCanvas && data.status) {
        new Chart(statusCanvas, {
            type: 'doughnut',
            data: {
                labels: data.status.labels,
                datasets: [{
                    data: data.status.values,
                    backgroundColor: data.status.colors.map((c) => TONES[c] || TONES.slate),
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 6,
                }],
            },
            options: {
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: { legend: { position: 'bottom' } },
            },
        });
    }

    const monthlyCanvas = document.getElementById('chart-monthly');
    if (monthlyCanvas && data.monthly) {
        new Chart(monthlyCanvas, {
            type: 'bar',
            data: {
                labels: data.monthly.labels,
                datasets: [
                    { label: 'Přijaté', data: data.monthly.received, backgroundColor: '#c7d2fe', borderRadius: 6, maxBarThickness: 28 },
                    { label: 'Dokončené', data: data.monthly.completed, backgroundColor: '#3b82f6', borderRadius: 6, maxBarThickness: 28 },
                ],
            },
            options: {
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { precision: 0, padding: 8 }, grid, border: { display: false } },
                },
                plugins: { legend: { position: 'top', align: 'end' } },
            },
        });
    }

    const valueCanvas = document.getElementById('chart-value');
    if (valueCanvas && data.monthly) {
        const ctx = valueCanvas.getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, 230);
        gradient.addColorStop(0, 'rgba(20, 184, 166, .28)');
        gradient.addColorStop(1, 'rgba(20, 184, 166, 0)');

        new Chart(valueCanvas, {
            type: 'line',
            data: {
                labels: data.monthly.labels,
                datasets: [{
                    label: 'Hodnota dokončených zakázek',
                    data: data.monthly.value,
                    borderColor: '#14b8a6',
                    backgroundColor: gradient,
                    fill: true,
                    tension: 0.35,
                    pointRadius: 4,
                    pointBackgroundColor: '#fff',
                    pointBorderWidth: 2,
                }],
            },
            options: {
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { callback: (v) => czk.format(v), padding: 8 }, grid, border: { display: false } },
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (c) => ` ${czk.format(c.parsed.y)}` } },
                },
            },
        });
    }
})();
