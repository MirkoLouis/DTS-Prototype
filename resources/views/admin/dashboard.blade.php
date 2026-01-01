<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Admin Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h3 class="text-2xl font-bold mb-4">Process Analytics: Bottleneck Detector</h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Current Load Chart -->
                        <div class="bg-gray-50 dark:bg-gray-700/50 p-6 rounded-lg shadow">
                            <h4 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Current Load by Department (Pending Documents)</h4>
                            <canvas id="currentLoadChart"></canvas>
                        </div>

                        <!-- Throughput Chart -->
                        <div class="bg-gray-50 dark:bg-gray-700/50 p-6 rounded-lg shadow">
                            <h4 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Documents Processed Over Time</h4>
                            <div class="flex justify-end mb-4">
                                <select id="throughputPeriod" class="form-select rounded-md shadow-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                    <option value="daily">Daily (Last 30 Days)</option>
                                    <option value="weekly">Weekly (Last 4 Weeks)</option>
                                    <option value="monthly">Monthly (Last 12 Months)</option>
                                    <option value="yearly">Yearly (Last 5 Years)</option>
                                </select>
                            </div>
                            <canvas id="throughputChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const currentLoadCtx = document.getElementById('currentLoadChart').getContext('2d');
                const throughputCtx = document.getElementById('throughputChart').getContext('2d');
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
                    fetch('{{ route('api.admin-dashboard.current-load') }}')
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
                    fetch(`{{ route('api.admin-dashboard.throughput') }}?period=${period}`)
                        .then(response => response.json())
                        .then(data => {
                            throughputChart.data.labels = data.labels;
                            throughputChart.data.datasets[0].data = data.data;
                            throughputChart.update();
                        })
                        .catch(error => console.error('Error fetching throughput data:', error));
                }

                // Event listener for period selection
                document.getElementById('throughputPeriod').addEventListener('change', function() {
                    fetchThroughputData(this.value);
                });

                // Initial data fetch for both charts
                fetchCurrentLoadData();
                fetchThroughputData('daily'); // Default to daily
            });
        </script>
    @endpush
</x-app-layout>