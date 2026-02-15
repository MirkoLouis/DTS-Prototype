import Chart from 'chart.js/auto';

document.addEventListener('DOMContentLoaded', function() {
    const chartContainer = document.querySelector('[data-current-load-url]');
    if (!chartContainer) return;

    // URLs from the main data attribute container
    const { returnDeclineUrl, statusDistributionUrl, returnRequestSourcesUrl, processingHotspotsUrl, avgStepTimeUrl, throughputUrl, loadVsTimeUrl } = chartContainer.dataset;

    // --- Element Selectors ---
    const departmentFilterEl = document.getElementById('department-filter');
    const departmentPeriodEl = document.getElementById('departmentPeriod');
    const globalThroughputPeriodEl = document.getElementById('globalThroughputPeriod');
    const returnDeclinePeriodEl = document.getElementById('returnDeclinePeriod');
    const loadVsTimeTitle = document.getElementById('load-vs-time-title');
    const chartModal = document.getElementById('chart-modal');
    const closeChartModalBtn = document.getElementById('close-chart-modal');
    const chartModalTitle = document.getElementById('chart-modal-title');
    const modalChartCanvas = document.getElementById('modal-chart-canvas')?.getContext('2d');
    const viewFullAvgStepTimeBtn = document.getElementById('view-full-avg-step-time');
    
    // Chart Canvases
    const statusDistributionCtx = document.getElementById('statusDistributionChart')?.getContext('2d');
    const returnDeclineCtx = document.getElementById('returnDeclineChart')?.getContext('2d');
    const avgStepTimeCtx = document.getElementById('avgStepTimeChart')?.getContext('2d');
    const throughputCtx = document.getElementById('throughputChart')?.getContext('2d');
    const returnRequestSourcesCtx = document.getElementById('returnRequestSourcesChart')?.getContext('2d');
    const processingHotspotsCtx = document.getElementById('processingHotspotsChart')?.getContext('2d');
    const loadVsTimeCtx = document.getElementById('loadVsTimeChart')?.getContext('2d'); // New Combo Chart

    // --- Chart Instances ---
    let statusDistributionChart, returnDeclineChart, avgStepTimeChart, throughputChart, returnRequestSourcesChart, processingHotspotsChart, loadVsTimeChart;
    let modalChart = null;

    // --- Helper & Modal Functions ---
    function formatDuration(totalSeconds) {
        if (totalSeconds <= 0) return 'N/A';
        const days = Math.floor(totalSeconds / 86400);
        if (days > 0) return `${days}` + (days === 1 ? ' day' : ' days');
        const hours = Math.floor(totalSeconds / 3600);
        if (hours > 0) return `${hours}` + (hours === 1 ? ' hour' : ' hours');
        const minutes = Math.floor(totalSeconds / 60);
        if (minutes > 0) return `${minutes}` + (minutes === 1 ? ' min' : ' mins');
        const seconds = Math.floor(totalSeconds);
        return `${seconds}` + (seconds === 1 ? ' sec' : ' secs');
    }

    function openChartModal(chartInstance, title, customOptions = null) {
        if (!chartModal || !modalChartCanvas) return;
        chartModalTitle.textContent = title;
        chartModal.style.display = 'flex';
        if (modalChart) modalChart.destroy();
        const finalOptions = JSON.parse(JSON.stringify(chartInstance.config.options));
        if (customOptions?.scales?.y) finalOptions.scales.y = Object.assign(finalOptions.scales.y || {}, customOptions.scales.y);
        finalOptions.responsive = true;
        finalOptions.maintainAspectRatio = true;
        modalChart = new Chart(modalChartCanvas, { type: chartInstance.config.type, data: JSON.parse(JSON.stringify(chartInstance.data)), options: finalOptions });
    }

    function closeChartModal() {
        if (chartModal) {
            chartModal.style.display = 'none';
            if (modalChart) { modalChart.destroy(); modalChart = null; }
        }
    }

    // --- Chart Initializers ---
    function initializeCharts() {
        const lineChartOptions = { responsive: true, scales: { y: { beginAtZero: true, title: { display: true, text: 'Average Processing Time (hrs)' } } }, plugins: { title: { display: false } } };
        const barChartOptions = { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } }, plugins: { legend: { display: false } } };
        const horizontalBarOptions = { responsive: true, maintainAspectRatio: false, indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { callback: (v) => formatDuration(v) } } }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => 'Avg Time: ' + formatDuration(c.raw) } } } };

        statusDistributionChart = new Chart(statusDistributionCtx, { type: 'doughnut', data: { labels: [], datasets: [] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' }, title: { display: false } } } });
        returnDeclineChart = new Chart(returnDeclineCtx, { type: 'line', data: { labels: [], datasets: [] }, options: { ...lineChartOptions, scales: { y: { ...lineChartOptions.scales.y, title: { display: true, text: 'Number of Documents' } } } } });
        throughputChart = new Chart(throughputCtx, { type: 'line', data: { labels: [], datasets: [] }, options: { ...lineChartOptions, maintainAspectRatio: false } });
        returnRequestSourcesChart = new Chart(returnRequestSourcesCtx, { type: 'bar', data: { labels: [], datasets: [] }, options: barChartOptions });
        processingHotspotsChart = new Chart(processingHotspotsCtx, { type: 'bar', data: { labels: [], datasets: [] }, options: { ...horizontalBarOptions, scales: { x: { ...horizontalBarOptions.scales.x, title: { display: true, text: 'Average Processing Time' } } } } });
        avgStepTimeChart = new Chart(avgStepTimeCtx, { type: 'bar', data: { labels: [], datasets: [] }, options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            scales: {
                x: {
                    beginAtZero: true,
                    title: { display: true, text: 'Average Step Time (hrs)' }
                },
                y: {
                    ticks: {
                        callback: function(v) {
                            const l = this.getLabelForValue(v);
                            return l.length > 15 ? l.substring(0, 12) + '...' : l;
                        }
                    }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            // Display the raw value which is now in hours, with 2 decimal places
                            return `Avg Time: ${Number(context.raw).toFixed(2)} hrs`;
                        }
                    }
                }
            }
        } });
        
        loadVsTimeChart = new Chart(loadVsTimeCtx, {
            type: 'line',
            data: { labels: [], datasets: [] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { type: 'linear', display: true, position: 'left', title: { display: true, text: 'Documents Received' } },
                    y1: { type: 'linear', display: true, position: 'right', title: { display: true, text: 'Avg. Processing Time (hrs)' }, grid: { drawOnChartArea: false } }
                }
            }
        });
    }

    // --- Data Fetching ---
    const fetchData = (url, chart) => {
        fetch(url)
            .then(response => response.json())
            .then(data => {
                chart.data.labels = data.labels;
                chart.data.datasets = data.datasets || [{ data: data.data }];
                chart.update();
            })
            .catch(error => console.error(`Error fetching data for ${chart.canvas.id}:`, error));
    };

    const fetchLoadVsTimeData = (period, departmentId) => {
        const url = new URL(loadVsTimeUrl);
        url.searchParams.append('period', period);
        url.searchParams.append('department_id', departmentId);
        fetchData(url, loadVsTimeChart);
    };
    
    // --- Event Listeners ---
    const updateLoadVsTimeChart = () => {
        const departmentId = departmentFilterEl.value;
        const period = departmentPeriodEl.value;
        const deptName = departmentFilterEl.options[departmentFilterEl.selectedIndex].text;
        if (loadVsTimeTitle) loadVsTimeTitle.textContent = `${deptName} - Load vs. Processing Time`;
        fetchLoadVsTimeData(period, departmentId);
    };

    if (departmentFilterEl) departmentFilterEl.addEventListener('change', updateLoadVsTimeChart);
    if (departmentPeriodEl) departmentPeriodEl.addEventListener('change', updateLoadVsTimeChart);
    if (globalThroughputPeriodEl) globalThroughputPeriodEl.addEventListener('change', () => fetchData(`${throughputUrl}?period=${globalThroughputPeriodEl.value}`, throughputChart));
    if (returnDeclinePeriodEl) returnDeclinePeriodEl.addEventListener('change', () => fetchData(`${returnDeclineUrl}?period=${returnDeclinePeriodEl.value}`, returnDeclineChart));

    if (viewFullAvgStepTimeBtn) {
        viewFullAvgStepTimeBtn.addEventListener('click', () => {
            if (!avgStepTimeChart) return;
            const fullDataUrl = new URL(avgStepTimeUrl);
            fullDataUrl.searchParams.append('full', 'true');
            fetch(fullDataUrl)
                .then(res => res.json())
                .then(fullData => {
                    const tempInstance = { config: avgStepTimeChart.config, data: fullData };
                    const modalOptions = { scales: { y: { ticks: { callback: (v) => tempInstance.data.labels[v] } } } };
                    openChartModal(tempInstance, 'Average Step Time by Department', modalOptions);
                })
                .catch(err => console.error('Error fetching full chart data:', err));
        });
    }
    if (closeChartModalBtn) closeChartModalBtn.addEventListener('click', closeChartModal);
    window.addEventListener('click', (event) => { if (event.target == chartModal) closeChartModal(); });

    // --- Initial Load ---
    initializeCharts();
    fetchData(statusDistributionUrl, statusDistributionChart);
    fetchData(returnRequestSourcesUrl, returnRequestSourcesChart);
    fetchData(processingHotspotsUrl, processingHotspotsChart);
    fetchData(avgStepTimeUrl, avgStepTimeChart);
    fetchData(`${returnDeclineUrl}?period=${returnDeclinePeriodEl.value}`, returnDeclineChart);
    fetchData(`${throughputUrl}?period=${globalThroughputPeriodEl.value}`, throughputChart);
    updateLoadVsTimeChart();
});
