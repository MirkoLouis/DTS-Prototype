import Chart from 'chart.js/auto';

document.addEventListener('DOMContentLoaded', function() {
    const chartContainer = document.querySelector('[data-current-load-url]');
    if (!chartContainer) return;

    const currentLoadUrl = chartContainer.dataset.currentLoadUrl;
    const throughputUrl = chartContainer.dataset.throughputUrl;
    const returnDeclineUrl = chartContainer.dataset.returnDeclineUrl;
    const statusDistributionUrl = chartContainer.dataset.statusDistributionUrl;
    const returnRequestSourcesUrl = chartContainer.dataset.returnRequestSourcesUrl;
    const processingHotspotsUrl = chartContainer.dataset.processingHotspotsUrl;
    const avgStepTimeUrl = chartContainer.dataset.avgStepTimeUrl; // New URL

    const departmentFilterEl = document.getElementById('department-filter');
    const throughputPeriodEl = document.getElementById('throughputPeriod');
    const returnDeclinePeriodEl = document.getElementById('returnDeclinePeriod');

    const currentLoadCtx = document.getElementById('currentLoadChart')?.getContext('2d');
    const throughputCtx = document.getElementById('throughputChart')?.getContext('2d');
    const returnDeclineCtx = document.getElementById('returnDeclineChart')?.getContext('2d');
    const statusDistributionCtx = document.getElementById('statusDistributionChart')?.getContext('2d');
    const returnRequestSourcesCtx = document.getElementById('returnRequestSourcesChart')?.getContext('2d');
    const processingHotspotsCtx = document.getElementById('processingHotspotsChart')?.getContext('2d');
    const avgStepTimeCtx = document.getElementById('avgStepTimeChart')?.getContext('2d'); // New Context

    if (!currentLoadCtx || !throughputCtx || !returnDeclineCtx || !statusDistributionCtx || !returnRequestSourcesCtx || !processingHotspotsCtx || !avgStepTimeCtx) {
        return;
    }

    let currentLoadChart, throughputChart, returnDeclineChart, statusDistributionChart, returnRequestSourcesChart, processingHotspotsChart, avgStepTimeChart;

    function formatDuration(totalSeconds) {
        if (totalSeconds <= 0) return 'N/A';

        const days = Math.floor(totalSeconds / 86400);
        if (days > 0) {
            return `${days}` + (days === 1 ? ' day' : ' days');
        }

        const hours = Math.floor(totalSeconds / 3600);
        if (hours > 0) {
            return `${hours}` + (hours === 1 ? ' hour' : ' hours');
        }

        const minutes = Math.floor(totalSeconds / 60);
        if (minutes > 0) {
            return `${minutes}` + (minutes === 1 ? ' min' : ' mins');
        }

        const seconds = Math.floor(totalSeconds);
        return `${seconds}` + (seconds === 1 ? ' sec' : ' secs');
    }

    function initializeCharts() {
        // Status Distribution Chart
        statusDistributionChart = new Chart(statusDistributionCtx, {
            type: 'doughnut',
            data: { labels: [], datasets: [] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' }, title: { display: false } }
            }
        });

        // Return Request Sources Chart
        returnRequestSourcesChart = new Chart(returnRequestSourcesCtx, {
            type: 'bar',
            data: { labels: [], datasets: [] },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true, title: { display: true, text: 'Number of Requests' } } },
                plugins: { title: { display: false }, legend: { display: false } }
            }
        });

        // Processing Hotspots Chart
        processingHotspotsChart = new Chart(processingHotspotsCtx, {
            type: 'bar',
            data: { labels: [], datasets: [] },
            options: {
                responsive: true,
                indexAxis: 'y', // Horizontal bar chart
                scales: {
                    x: {
                        beginAtZero: true,
                        title: { display: true, text: 'Average Processing Time' },
                        ticks: {
                            callback: function(value) { return formatDuration(value); }
                        }
                    }
                },
                plugins: {
                    title: { display: false },
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Avg Time: ' + formatDuration(context.raw);
                            }
                        }
                    }
                }
            }
        });

        // Average Step Time Chart
        avgStepTimeChart = new Chart(avgStepTimeCtx, {
            type: 'bar',
            data: { labels: [], datasets: [] },
            options: {
                responsive: true,
                indexAxis: 'y', // Horizontal bar chart
                scales: {
                    x: {
                        beginAtZero: true,
                        title: { display: true, text: 'Average Step Time' },
                        ticks: {
                            callback: function(value) { return formatDuration(value); }
                        }
                    }
                },
                plugins: {
                    title: { display: false },
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Avg Time: ' + formatDuration(context.raw);
                            }
                        }
                    }
                }
            }
        });

        currentLoadChart = new Chart(currentLoadCtx, {
            type: 'bar',
            data: { labels: [], datasets: [{ label: 'Documents Pending', data: [], backgroundColor: 'rgba(54, 162, 235, 0.5)', borderColor: 'rgba(54, 162, 235, 1)', borderWidth: 1 }] },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true, title: { display: true, text: 'Number of Documents' } } },
                plugins: { title: { display: false } }
            }
        });

        throughputChart = new Chart(throughputCtx, {
            type: 'line',
            data: { labels: [], datasets: [{ label: 'Documents Processed', data: [], borderColor: 'rgba(75, 192, 192, 1)', backgroundColor: 'rgba(75, 192, 192, 0.2)', fill: true, tension: 0.1 }] },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true, title: { display: true, text: 'Number of Documents' } } },
                plugins: { title: { display: false } }
            }
        });

        returnDeclineChart = new Chart(returnDeclineCtx, {
            type: 'line',
            data: { labels: [], datasets: [] },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true, title: { display: true, text: 'Number of Documents' } } },
                plugins: { title: { display: false } }
            }
        });
    }

    function fetchStatusDistributionData() {
        fetch(statusDistributionUrl)
            .then(response => response.json())
            .then(data => {
                statusDistributionChart.data.labels = data.labels;
                statusDistributionChart.data.datasets = data.datasets;
                statusDistributionChart.update();
            }).catch(error => console.error('Error fetching status distribution data:', error));
    }

    function fetchReturnRequestSourcesData() {
        fetch(returnRequestSourcesUrl)
            .then(response => response.json())
            .then(data => {
                returnRequestSourcesChart.data.labels = data.labels;
                returnRequestSourcesChart.data.datasets = data.datasets;
                returnRequestSourcesChart.update();
            }).catch(error => console.error('Error fetching return request sources data:', error));
    }

    function fetchProcessingHotspotsData() {
        fetch(processingHotspotsUrl)
            .then(response => response.json())
            .then(data => {
                processingHotspotsChart.data.labels = data.labels;
                processingHotspotsChart.data.datasets = data.datasets;
                processingHotspotsChart.update();
            }).catch(error => console.error('Error fetching processing hotspots data:', error));
    }

    function fetchAvgStepTimeData() {
        fetch(avgStepTimeUrl)
            .then(response => response.json())
            .then(data => {
                avgStepTimeChart.data.labels = data.labels;
                avgStepTimeChart.data.datasets = data.datasets;
                avgStepTimeChart.update();
            }).catch(error => console.error('Error fetching avg step time data:', error));
    }

    function fetchCurrentLoadData(departmentId = 'all') {
        const url = new URL(currentLoadUrl);
        url.searchParams.append('department_id', departmentId);
        fetch(url)
            .then(response => response.json())
            .then(data => {
                currentLoadChart.data.labels = data.labels;
                currentLoadChart.data.datasets[0].data = data.data;
                currentLoadChart.update();
            }).catch(error => console.error('Error fetching current load data:', error));
    }

    function fetchThroughputData(period = 'daily', departmentId = 'all') {
        const url = new URL(throughputUrl);
        url.searchParams.append('period', period);
        url.searchParams.append('department_id', departmentId);
        fetch(url)
            .then(response => response.json())
            .then(data => {
                throughputChart.data.labels = data.labels;
                throughputChart.data.datasets[0].data = data.data;
                throughputChart.update();
            }).catch(error => console.error('Error fetching throughput data:', error));
    }

    function fetchReturnDeclineData(period = 'daily') {
        const url = new URL(returnDeclineUrl);
        url.searchParams.append('period', period);
        fetch(url)
            .then(response => response.json())
            .then(data => {
                returnDeclineChart.data.labels = data.labels;
                returnDeclineChart.data.datasets = data.datasets;
                returnDeclineChart.update();
            }).catch(error => console.error('Error fetching return/decline data:', error));
    }

    function updateCharts() {
        const selectedDepartment = departmentFilterEl.value;
        const selectedThroughputPeriod = throughputPeriodEl.value;
        const selectedReturnDeclinePeriod = returnDeclinePeriodEl.value;

        fetchStatusDistributionData();
        fetchReturnRequestSourcesData();
        fetchProcessingHotspotsData();
        fetchAvgStepTimeData();
        fetchCurrentLoadData(selectedDepartment);
        fetchThroughputData(selectedThroughputPeriod, selectedDepartment);
        fetchReturnDeclineData(selectedReturnDeclinePeriod);
    }

    if (departmentFilterEl) departmentFilterEl.addEventListener('change', updateCharts);
    if (throughputPeriodEl) throughputPeriodEl.addEventListener('change', updateCharts);
    if (returnDeclinePeriodEl) returnDeclinePeriodEl.addEventListener('change', updateCharts);

    initializeCharts();
    updateCharts();
});

