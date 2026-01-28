import Chart from 'chart.js/auto';

document.addEventListener('DOMContentLoaded', function() {
    const chartContainer = document.querySelector('.grid.grid-cols-1');
    if (!chartContainer) return;

    const currentLoadUrl = chartContainer.dataset.currentLoadUrl;
    const throughputUrl = chartContainer.dataset.throughputUrl;

    const departmentFilterEl = document.getElementById('department-filter');
    const throughputPeriodEl = document.getElementById('throughputPeriod');

    const currentLoadCtx = document.getElementById('currentLoadChart')?.getContext('2d');
    const throughputCtx = document.getElementById('throughputChart')?.getContext('2d');

    if (!currentLoadCtx || !throughputCtx) {
        return;
    }

    let currentLoadChart, throughputChart;

    function initializeCharts() {
        currentLoadChart = new Chart(currentLoadCtx, {
            type: 'bar',
            data: {
                labels: [],
                datasets: [{
                    label: 'Documents Pending',
                    data: [],
                    backgroundColor: 'rgba(54, 162, 235, 0.5)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true, title: { display: true, text: 'Number of Documents' } } },
                plugins: { title: { display: false } }
            }
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
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true, title: { display: true, text: 'Number of Documents' } } },
                plugins: { title: { display: false } }
            }
        });
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
            })
            .catch(error => console.error('Error fetching current load data:', error));
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
            })
            .catch(error => console.error('Error fetching throughput data:', error));
    }

    function updateCharts() {
        const selectedDepartment = departmentFilterEl.value;
        const selectedPeriod = throughputPeriodEl.value;
        fetchCurrentLoadData(selectedDepartment);
        fetchThroughputData(selectedPeriod, selectedDepartment);
    }

    if (departmentFilterEl) {
        departmentFilterEl.addEventListener('change', updateCharts);
    }
    if (throughputPeriodEl) {
        throughputPeriodEl.addEventListener('change', updateCharts);
    }

    initializeCharts();
    updateCharts(); // Initial data fetch
});
