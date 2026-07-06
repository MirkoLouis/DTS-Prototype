document.addEventListener('DOMContentLoaded', function() {
    const chartContainer = document.querySelector('.grid');
    if (!chartContainer) return;

    // URLs from the data attributes
    const currentLoadUrl = chartContainer.dataset.currentLoadUrl;
    const throughputUrl = chartContainer.dataset.throughputUrl;
    const avgProcessingTimeUrl = chartContainer.dataset.avgProcessingTimeUrl;

    // Dropdown selectors
    const currentLoadPeriodEl = document.getElementById('currentLoadPeriod');
    const avgProcessingTimePeriodEl = document.getElementById('avgProcessingTimePeriod');
    const throughputPeriodEl = document.getElementById('throughputPeriod');

    // Canvas contexts
    const currentLoadCtx = document.getElementById('currentLoadChart')?.getContext('2d');
    const throughputCtx = document.getElementById('throughputChart')?.getContext('2d');
    const avgProcessingTimeCtx = document.getElementById('avgProcessingTimeChart')?.getContext('2d');

    if (!currentLoadCtx || !throughputCtx || !avgProcessingTimeCtx) {
        return;
    }

    let currentLoadChart, throughputChart, avgProcessingTimeChart;
    window.dtsCharts = { currentLoadChart, throughputChart, avgProcessingTimeChart };

    const defaultLineOptions = {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true } },
        plugins: { title: { display: false }, legend: { display: false } },
        interaction: { intersect: false, mode: 'index' },
    };

    function initializeCharts() {
        currentLoadChart = new Chart(currentLoadCtx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Documents Received',
                    data: [],
                    borderColor: 'rgba(54, 162, 235, 1)',
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                    fill: true,
                    tension: 0.1
                }]
            },
            options: { ...defaultLineOptions, scales: { y: { ...defaultLineOptions.scales.y, title: { display: true, text: 'Number of Documents' } } } }
        });

        avgProcessingTimeChart = new Chart(avgProcessingTimeCtx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Avg. Time (Hours)',
                    data: [],
                    borderColor: 'rgba(255, 159, 64, 1)',
                    backgroundColor: 'rgba(255, 159, 64, 0.2)',
                    fill: true,
                    tension: 0.1
                }]
            },
            options: { ...defaultLineOptions, scales: { y: { ...defaultLineOptions.scales.y, title: { display: true, text: 'Hours' } } } }
        });

        throughputChart = new Chart(throughputCtx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Documents Processed',
                    data: [],
                    borderColor: 'rgba(75, 192, 192, 1)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    fill: true,
                    tension: 0.1
                }]
            },
            options: { ...defaultLineOptions, scales: { y: { ...defaultLineOptions.scales.y, title: { display: true, text: 'Number of Documents' } } } }
        });

        window.dtsCharts.currentLoadChart = currentLoadChart;
        window.dtsCharts.avgProcessingTimeChart = avgProcessingTimeChart;
        window.dtsCharts.throughputChart = throughputChart;
    }

    function fetchChartData(chart, baseUrl, period, chartName) {
        if (!chart) return;
        const url = new URL(baseUrl, window.location.origin);
        url.searchParams.append('period', period);

        fetch(url)
            .then(response => response.json())
            .then(data => {
                chart.data.labels = data.labels;
                chart.data.datasets[0].data = data.data;
                chart.update();
            })
            .catch(error => console.error(`Error fetching ${chartName} data:`, error));
    }

    function initialize() {
        initializeCharts();

        const updateAllStatisticsCharts = () => {
            if (currentLoadPeriodEl) fetchChartData(currentLoadChart, currentLoadUrl, currentLoadPeriodEl.value, 'documents received');
            if (avgProcessingTimePeriodEl) fetchChartData(avgProcessingTimeChart, avgProcessingTimeUrl, avgProcessingTimePeriodEl.value, 'avg processing time');
            if (throughputPeriodEl) fetchChartData(throughputChart, throughputUrl, throughputPeriodEl.value, 'throughput');
        };

        // Initial data fetch
        updateAllStatisticsCharts();

        // Polling: Update all statistics charts every 60 seconds
        setInterval(updateAllStatisticsCharts, 60000);

        // Add event listeners
        currentLoadPeriodEl?.addEventListener('change', (e) => fetchChartData(currentLoadChart, currentLoadUrl, e.target.value, 'documents received'));
        avgProcessingTimePeriodEl?.addEventListener('change', (e) => fetchChartData(avgProcessingTimeChart, avgProcessingTimeUrl, e.target.value, 'avg processing time'));
        throughputPeriodEl?.addEventListener('change', (e) => fetchChartData(throughputChart, throughputUrl, e.target.value, 'throughput'));
    }

    initialize();
});
