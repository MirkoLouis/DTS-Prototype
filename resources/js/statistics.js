import Chart from 'chart.js/auto';

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

    const defaultLineOptions = {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true } },
        plugins: { title: { display: false } },
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
    }

    function fetchChartData(chart, baseUrl, period, chartName) {
        if (!chart) return;
        const url = new URL(baseUrl);
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

        // Initial data fetch
        if (currentLoadPeriodEl) fetchChartData(currentLoadChart, currentLoadUrl, currentLoadPeriodEl.value, 'documents received');
        if (avgProcessingTimePeriodEl) fetchChartData(avgProcessingTimeChart, avgProcessingTimeUrl, avgProcessingTimePeriodEl.value, 'avg processing time');
        if (throughputPeriodEl) fetchChartData(throughputChart, throughputUrl, throughputPeriodEl.value, 'throughput');

        // Add event listeners
        currentLoadPeriodEl?.addEventListener('change', (e) => fetchChartData(currentLoadChart, currentLoadUrl, e.target.value, 'documents received'));
        avgProcessingTimePeriodEl?.addEventListener('change', (e) => fetchChartData(avgProcessingTimeChart, avgProcessingTimeUrl, e.target.value, 'avg processing time'));
        throughputPeriodEl?.addEventListener('change', (e) => fetchChartData(throughputChart, throughputUrl, e.target.value, 'throughput'));
    }

    initialize();

    // Logic for the released documents table (for officers) - This remains unchanged
    const releasedDocumentsSection = document.getElementById('released-documents-section');
    if (releasedDocumentsSection) {
        const documentsContainer = document.getElementById('released-documents-container');
        const fetchUrl = releasedDocumentsSection.dataset.fetchUrl;
        const submitterFilter = document.getElementById('submitter-filter');

        const fetchDocuments = async (url) => {
            try {
                const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok) throw new Error('Network response was not ok.');
                const html = await response.text();
                documentsContainer.innerHTML = html;
                history.pushState(null, '', url);
            } catch (error) {
                console.error('Fetch error:', error);
                documentsContainer.innerHTML = '<p class="text-center text-red-500">Failed to load documents. Please try again.</p>';
            }
        };

        const searchInput = document.getElementById('table-search');
        const purposeFilter = document.getElementById('purpose-filter');
        const yearFilter = document.getElementById('year-filter');
        const monthFilter = document.getElementById('month-filter');
        const dayFilter = document.getElementById('day-filter');
        const clearFiltersBtn = document.getElementById('clear-filters-btn');
        let debounceTimer;

        function handleFilterChange() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                const url = new URL(fetchUrl);
                const params = {
                    search: searchInput.value,
                    purpose_id: purposeFilter.value,
                    submitter: submitterFilter.value,
                    year: yearFilter.value,
                    month: monthFilter.value,
                    day: dayFilter.value,
                };
                for (const [key, value] of Object.entries(params)) {
                    if (value && value !== 'all') url.searchParams.set(key, value);
                }
                url.searchParams.set('page', '1');
                fetchDocuments(url.toString());
            }, 300);
        }
        
        clearFiltersBtn?.addEventListener('click', () => {
            searchInput.value = '';
            [purposeFilter, submitterFilter, yearFilter, monthFilter, dayFilter].forEach(el => el.value = 'all');
            handleFilterChange();
        });

        [searchInput, purposeFilter, submitterFilter, yearFilter, monthFilter, dayFilter].forEach(el => {
            el?.addEventListener(el.tagName === 'INPUT' ? 'keyup' : 'change', handleFilterChange);
        });

        documentsContainer.addEventListener('click', (e) => {
            if (e.target.tagName === 'A' && e.target.closest('.pagination')) {
                e.preventDefault();
                const url = e.target.getAttribute('href');
                if (url) fetchDocuments(url);
            }
        });
    }
});
