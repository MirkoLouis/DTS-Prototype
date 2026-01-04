import Chart from 'chart.js/auto';

document.addEventListener('DOMContentLoaded', function() {
    const chartContainer = document.querySelector('.grid.grid-cols-1'); // A bit fragile, an ID would be better.
    if (!chartContainer) return;

    const currentLoadUrl = chartContainer.dataset.currentLoadUrl;
    const throughputUrl = chartContainer.dataset.throughputUrl;

    const currentLoadCtx = document.getElementById('currentLoadChart')?.getContext('2d');
    const throughputCtx = document.getElementById('throughputChart')?.getContext('2d');

    if (!currentLoadCtx || !throughputCtx) {
        return;
    }
    
    let throughputChart; // To store the instance of the throughput chart

    // Initialize Current Load Chart
    const currentLoadChart = new Chart(currentLoadCtx, {
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
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Number of Documents'
                    }
                }
            },
            plugins: {
                title: {
                    display: false,
                    text: 'Current Load by Department'
                }
            }
        }
    });

    // Fetch and update Current Load Chart data
    function fetchCurrentLoadData() {
        fetch(currentLoadUrl)
            .then(response => response.json())
            .then(data => {
                currentLoadChart.data.labels = data.labels;
                currentLoadChart.data.datasets[0].data = data.data;
                currentLoadChart.update();
            })
            .catch(error => console.error('Error fetching current load data:', error));
    }

    // Initialize Throughput Chart
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
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Number of Documents'
                    }
                }
            },
            plugins: {
                title: {
                    display: false,
                    text: 'Documents Processed Over Time'
                }
            }
        }
    });

    // Fetch and update Throughput Chart data
    function fetchThroughputData(period) {
        fetch(`${throughputUrl}?period=${period}`)
            .then(response => response.json())
            .then(data => {
                throughputChart.data.labels = data.labels;
                throughputChart.data.datasets[0].data = data.data;
                throughputChart.update();
            })
            .catch(error => console.error('Error fetching throughput data:', error));
    }

    // Event listener for period selection
    const throughputPeriodEl = document.getElementById('throughputPeriod');
    if (throughputPeriodEl) {
        throughputPeriodEl.addEventListener('change', function() {
            fetchThroughputData(this.value);
        });
    }

    // Initial data fetch for both charts
    fetchCurrentLoadData();
    fetchThroughputData('daily'); // Default to daily
});
