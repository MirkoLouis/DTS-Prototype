<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Department Statistics') }}
        </h2>
    </x-slot>

    <div class="py-2">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- Performance Analytics Section --}}
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h3 class="text-2xl font-bold mb-4">Performance Analytics for {{ Auth::user()->department->name }}</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6"
                         data-current-load-url="{{ route('api.statistics.current-load') }}"
                         data-throughput-url="{{ route('api.statistics.throughput') }}"
                         data-avg-processing-time-url="{{ route('api.statistics.avg-processing-time') }}">
                        <!-- Charts content -->
                        <div class="bg-gray-50 dark:bg-gray-700/50 p-6 rounded-lg shadow">
                            <div class="flex justify-between items-center mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">
                                <h4 class="text-lg font-bold">Documents Received</h4>
                                <select id="currentLoadPeriod" class="chart-period-selector form-select rounded-md shadow-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                    <option value="daily">Daily (30 Days)</option>
                                    <option value="weekly">Weekly (4 Weeks)</option>
                                    <option value="monthly">Monthly (12 Months)</option>
                                </select>
                            </div>
                            <div class="relative" style="height:200px">
                                <canvas id="currentLoadChart"></canvas>
                            </div>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-700/50 p-6 rounded-lg shadow">
                            <div class="flex justify-between items-center mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">
                                <h4 class="text-lg font-bold">Average Processing Time</h4>
                                <select id="avgProcessingTimePeriod" class="chart-period-selector form-select rounded-md shadow-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                    <option value="daily">Daily (30 Days)</option>
                                    <option value="weekly">Weekly (4 Weeks)</option>
                                    <option value="monthly">Monthly (12 Months)</option>
                                </select>
                            </div>
                            <div class="relative" style="height:200px">
                                <canvas id="avgProcessingTimeChart"></canvas>
                            </div>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-700/50 p-6 rounded-lg shadow md:col-span-2">
                            <div class="flex justify-between items-center mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">
                                <h4 class="text-lg font-bold">Documents Processed Over Time</h4>
                                <select id="throughputPeriod" class="chart-period-selector form-select rounded-md shadow-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                    <option value="daily">Daily (Last 30 Days)</option>
                                    <option value="weekly">Weekly (Last 4 Weeks)</option>
                                    <option value="monthly">Monthly (Last 12 Months)</option>
                                    <option value="yearly">Yearly (Last 5 Years)</option>
                                </select>
                            </div>
                            <div class="relative" style="height:400px">
                                <canvas id="throughputChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Released Documents History Section --}}
            @if (Auth::user()->role === 'officer')
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg" id="released-documents-section" data-fetch-url="{{ route('statistics.index') }}">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-2xl font-bold">Released Documents History</h3>
                        <form id="report-generation-form" action="{{ route('statistics.generate-report') }}" method="POST" class="flex items-center space-x-4">
                            @csrf
                            <input type="hidden" name="purpose_id" id="form_purpose_id">
                            <input type="hidden" name="submitter" id="form_submitter">
                            <input type="hidden" name="search" id="form_search">
                            <input type="hidden" name="year" id="form_year">
                            <input type="hidden" name="month" id="form_month">
                            <input type="hidden" name="day" id="form_day">
                            <input type="hidden" name="chart_load_img" id="chart_load_img">
                            <input type="hidden" name="chart_throughput_img" id="chart_throughput_img">
                            <input type="hidden" name="chart_avg_time_img" id="chart_avg_time_img">
                            <input type="hidden" name="format" id="form_format" value="pdf">
                            <div class="flex items-center space-x-2">
                                <div class="flex items-center" id="charts-checkbox-container">
                                    <input id="include_charts" name="include_charts" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    <label for="include_charts" class="ml-2 block text-sm text-gray-900 dark:text-gray-200">Include Charts</label>
                                </div>
                                <div class="flex items-center ml-4">
                                    <label for="export_format" class="mr-2 text-sm text-gray-900 dark:text-gray-200">Format:</label>
                                    <select id="export_format" class="form-select rounded-md shadow-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm py-1">
                                        <option value="pdf">PDF</option>
                                        <option value="csv">CSV</option>
                                    </select>
                                </div>
                            </div>
                            <button id="generate-report-btn" type="button" class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-500 active:bg-blue-700 focus:outline-none focus:border-blue-700 focus:ring focus:ring-blue-200 disabled:opacity-25 transition">
                                Generate Report
                            </button>
                        </form>
                    </div>
                    <div class="flex flex-row items-end gap-2 pb-4 border-b border-gray-100 dark:border-gray-700 w-full">
                        <div class="flex-grow" style="flex-basis: 10%;">
                            <label for="year-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Year</label>
                            <select id="year-filter" class="filter-input block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="all">All</option>
                                @foreach($years ?? [] as $year)
                                    <option value="{{ $year }}">{{ $year }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex-grow" style="flex-basis: 15%;">
                            <label for="month-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Month</label>
                            <select id="month-filter" class="filter-input block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="all">All</option>
                                @foreach(range(1,12) as $month)
                                    <option value="{{ $month }}">{{ date('F', mktime(0, 0, 0, $month, 10)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex-grow" style="flex-basis: 10%;">
                            <label for="day-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Day</label>
                            <select id="day-filter" class="filter-input block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="all">All</option>
                                @foreach(range(1,31) as $day)
                                    <option value="{{ $day }}">{{ $day }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex-grow" style="flex-basis: 25%;">
                            <label for="purpose-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Purpose</label>
                            <select id="purpose-filter" class="filter-input block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="all">All Purposes</option>
                                @foreach($purposes as $purpose)
                                    <option value="{{ $purpose->id }}">{{ $purpose->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex-grow" style="flex-basis: 20%;">
                            <label for="submitter-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Submitter Name</label>
                            <input type="text" id="submitter-filter" class="filter-input block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" placeholder="Search submitter...">
                        </div>
                        <div class="flex-grow" style="flex-basis: 20%;">
                            <label for="table-search" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Search</label>
                            <input type="text" id="table-search" class="block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" placeholder="Tracking Code...">
                        </div>
                        <button id="clear-filters-btn" class="flex-shrink-0 inline-flex items-center px-4 py-2 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-500 active:bg-gray-700 focus:outline-none focus:border-gray-700 focus:ring focus:ring-gray-200 disabled:opacity-25 transition">
                            Clear
                        </button>
                    </div>
                    <div id="released-documents-container">
                        @include('officer.partials.released-documents-table', ['releasedDocuments' => $releasedDocuments])
                    </div>
                </div>
            </div>
            @endif
        </div>
        <x-report-progress-modal />
    </div>

    @push('scripts')
        @vite('resources/js/statistics.js')
    @endpush
                    
    @push('scripts')
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('report-generation-form');
        const generateReportBtn = document.getElementById('generate-report-btn');
        const exportFormatSelect = document.getElementById('export_format');
        const includeChartsCheckbox = document.getElementById('include_charts');
        const chartsCheckboxContainer = document.getElementById('charts-checkbox-container');

        // Handle CSV vs PDF UI constraints
        if (exportFormatSelect && includeChartsCheckbox) {
            exportFormatSelect.addEventListener('change', function() {
                if (this.value === 'csv') {
                    includeChartsCheckbox.checked = false;
                    includeChartsCheckbox.disabled = true;
                    chartsCheckboxContainer.style.opacity = '0.5';
                    chartsCheckboxContainer.title = 'Charts are not available for CSV export.';
                } else {
                    includeChartsCheckbox.disabled = false;
                    chartsCheckboxContainer.style.opacity = '1';
                    chartsCheckboxContainer.title = '';
                }
            });
        }

        if (form && generateReportBtn) {
             generateReportBtn.addEventListener('click', function(e) {
                // Update form fields
                document.getElementById('form_purpose_id').value = document.getElementById('purpose-filter').value;
                document.getElementById('form_submitter').value = document.getElementById('submitter-filter').value;
                document.getElementById('form_search').value = document.getElementById('table-search').value;
                document.getElementById('form_year').value = document.getElementById('year-filter').value;
                document.getElementById('form_month').value = document.getElementById('month-filter').value;
                document.getElementById('form_day').value = document.getElementById('day-filter').value;
                document.getElementById('form_format').value = document.getElementById('export_format').value;
                
                if (document.getElementById('include_charts').checked) {
                    if (window.dtsCharts && window.dtsCharts.currentLoadChart) {
                        document.getElementById('chart_load_img').value = window.dtsCharts.currentLoadChart.toBase64Image();
                    }
                    if (window.dtsCharts && window.dtsCharts.throughputChart) {
                        document.getElementById('chart_throughput_img').value = window.dtsCharts.throughputChart.toBase64Image();
                    }
                    if (window.dtsCharts && window.dtsCharts.avgProcessingTimeChart) {
                        document.getElementById('chart_avg_time_img').value = window.dtsCharts.avgProcessingTimeChart.toBase64Image();
                    }
                }
                
                handleReportGeneration();
            });
        }

        const documentsContainer = document.getElementById('released-documents-container');
        const documentsSection = document.getElementById('released-documents-section');

        if (documentsContainer && documentsSection) {
            const fetchDocuments = async (url) => {
                try {
                    const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    if (!response.ok) throw new Error('Network response was not ok.');
                    const html = await response.text();
                    documentsContainer.innerHTML = html;
                    history.pushState(null, '', url);
                    documentsSection.scrollIntoView({ behavior: "smooth" });
                } catch (error) {
                    console.error('Fetch error:', error);
                    documentsContainer.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-red-500">Failed to load documents. Please try again.</td></tr>';
                }
            };

            const searchInput = document.getElementById('table-search');
            const purposeFilter = document.getElementById('purpose-filter');
            const submitterFilter = document.getElementById('submitter-filter');
            const yearFilter = document.getElementById('year-filter');
            const monthFilter = document.getElementById('month-filter');
            const dayFilter = document.getElementById('day-filter');
            const clearFiltersBtn = document.getElementById('clear-filters-btn');

            let debounceTimer;

            function handleFilterChange() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    const url = new URL(documentsSection.dataset.fetchUrl);
                    if (searchInput.value) url.searchParams.set('search', searchInput.value);
                    if (purposeFilter.value !== 'all') url.searchParams.set('purpose_id', purposeFilter.value);
                    if (submitterFilter.value) url.searchParams.set('submitter', submitterFilter.value);
                    if (yearFilter.value !== 'all') url.searchParams.set('year', yearFilter.value);
                    if (monthFilter.value !== 'all') url.searchParams.set('month', monthFilter.value);
                    if (dayFilter.value !== 'all') url.searchParams.set('day', dayFilter.value);
                    url.searchParams.set('page', '1');

                    fetchDocuments(url.toString());
                }, 300);
            }

            function clearFilters() {
                searchInput.value = '';
                purposeFilter.value = 'all';
                submitterFilter.value = '';
                yearFilter.value = 'all';
                monthFilter.value = 'all';
                dayFilter.value = 'all';
                handleFilterChange();
            }

            searchInput.addEventListener('keyup', handleFilterChange);
            purposeFilter.addEventListener('change', handleFilterChange);
            submitterFilter.addEventListener('keyup', handleFilterChange);
            yearFilter.addEventListener('change', handleFilterChange);
            monthFilter.addEventListener('change', handleFilterChange);
            dayFilter.addEventListener('change', handleFilterChange);
            clearFiltersBtn.addEventListener('click', clearFilters);

            documentsContainer.addEventListener('click', (e) => {
                const paginationLink = e.target.closest('#pagination-links a');
                if ( paginationLink ) {
                    e.preventDefault();
                    const url = paginationLink.getAttribute('href');
                    if (url && url !== '#') {
                        fetchDocuments(url);
                    }
                }
            });
        }

        // Report Generation Modal Logic
        const reportModal = document.getElementById('report-progress-modal');
        const closeReportModalBtn = document.getElementById('close-report-modal');
        const progressBar = document.getElementById('report-progress-bar');
        const progressText = document.getElementById('report-progress-text');
        const progressTime = document.getElementById('report-progress-time');
        const downloadBtnContainer = document.createElement('div');
        downloadBtnContainer.classList.add('mt-4', 'text-center');
        
        let pollingInterval;
        let startTime;
        let currentJobId;

        function formatTime(seconds) {
            if (seconds < 1) return "Soon...";
            if (seconds < 60) return `${Math.round(seconds)}s`;
            const minutes = Math.floor(seconds / 60);
            const remainingSeconds = Math.round(seconds % 60);
            return `${minutes}m ${remainingSeconds}s`;
        }

        async function handleReportGeneration() {
            const reportForm = document.getElementById('report-generation-form');
            const formData = new FormData(reportForm);
            
            try {
                // Set loading state on button
                generateReportBtn.disabled = true;
                generateReportBtn.textContent = 'Preparing...';

                // Start job
                const response = await fetch(reportForm.action, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value,
                        'Accept': 'application/json'
                    }
                });

                // Reset button state
                generateReportBtn.disabled = false;
                generateReportBtn.textContent = 'Generate Report';

                if (!response.ok) {
                    let errorMessage = 'Could not start report generation. Please try again.';
                    try {
                        const errorData = await response.json();
                        errorMessage = errorData.error || errorMessage;
                    } catch (e) {
                        // If JSON parsing fails, use default message
                    }
                    throw new Error(errorMessage);
                }

                const data = await response.json();
                currentJobId = data.job_id;

                // Configure Modal
                reportModal.style.display = 'block';
                progressBar.style.width = '0%';
                progressBar.classList.remove('bg-green-500', 'bg-red-500');
                progressBar.classList.add('bg-blue-600');
                progressText.textContent = 'Report is in the queue...';
                progressTime.textContent = 'Est. time remaining: Calculating...';
                
                closeReportModalBtn.textContent = 'Cancel Report';
                closeReportModalBtn.classList.remove('bg-white', 'text-gray-700', 'hover:bg-gray-50', 'dark:bg-gray-600', 'dark:text-gray-200', 'dark:hover:bg-gray-500');
                closeReportModalBtn.classList.add('bg-red-500', 'text-white', 'hover:bg-red-600');
                downloadBtnContainer.innerHTML = '';

                startTime = Date.now();

                // Start polling
                pollingInterval = setInterval(() => {
                    pollJobStatus(currentJobId);
                }, 3000);

            } catch (error) {
                console.error('Report generation error:', error);
                alert(error.message);
                // Reset button state just in case
                generateReportBtn.disabled = false;
                generateReportBtn.textContent = 'Generate Report';
            }
        }

        async function pollJobStatus(jobId) {
            try {
                const response = await fetch(`/api/statistics/report-status/${jobId}`);
                if (!response.ok) throw new Error('Could not get report status.');
                const job = await response.json();

                progressBar.style.width = `${job.progress || 0}%`;
                
                let statusMsg = "Processing...";
                if (job.progress <= 5) statusMsg = "Job queued...";
                else if (job.progress <= 25) statusMsg = "Filtering documents...";
                else if (job.progress <= 50) statusMsg = "Gathering data...";
                else if (job.progress <= 90) statusMsg = `Generating Report Pages (${job.total_documents} docs)...`;
                else if (job.progress < 100) statusMsg = "Saving to storage...";
                
                progressText.textContent = `${statusMsg} (${job.progress || 0}%)`;

                if (job.progress > 0 && job.progress < 100) {
                    const elapsed = (Date.now() - startTime) / 1000;
                    const estimatedTotal = elapsed / (job.progress / 100);
                    const remaining = Math.max(0, estimatedTotal - elapsed);
                    progressTime.textContent = `Est. time remaining: ${formatTime(remaining)}`;
                }

                if (job.status === 'completed') {
                    finishJob(true, jobId);
                } else if (job.status === 'failed') {
                    finishJob(false, jobId, job.error_message);
                } else if (job.status === 'cancelled') {
                    finishJob(false, jobId, "Job was cancelled.");
                }
            } catch (error) {
                console.error('Polling error:', error);
            }
        }

        function finishJob(success, jobId, error = null) {
            clearInterval(pollingInterval);
            progressBar.style.width = '100%';
            progressBar.classList.remove('bg-blue-600');
            closeReportModalBtn.textContent = 'Close Window';
            closeReportModalBtn.classList.remove('bg-red-500', 'text-white', 'hover:bg-red-600');
            closeReportModalBtn.classList.add('bg-white', 'text-gray-700', 'hover:bg-gray-50', 'dark:bg-gray-600', 'dark:text-gray-200', 'dark:hover:bg-gray-500');

            if (success) {
                progressBar.classList.add('bg-green-500');
                progressText.textContent = 'Report generated successfully!';
                progressTime.textContent = 'Done!';
                
                const downloadBtn = document.createElement('a');
                downloadBtn.href = `/statistics/report/download/${jobId}`;
                downloadBtn.textContent = 'Download Report';
                downloadBtn.classList.add('inline-flex', 'items-center', 'px-4', 'py-2', 'bg-green-600', 'border', 'border-transparent', 'rounded-md', 'font-semibold', 'text-xs', 'text-white', 'uppercase', 'tracking-widest', 'hover:bg-green-500');
                downloadBtnContainer.innerHTML = '';
                downloadBtnContainer.appendChild(downloadBtn);
                progressBar.parentElement.parentElement.appendChild(downloadBtnContainer);
            } else {
                progressBar.classList.add('bg-red-500');
                progressText.textContent = `Error: ${error || 'An unknown error occurred.'}`;
                progressTime.textContent = 'Generation Failed';
            }
        }
        
        closeReportModalBtn.addEventListener('click', async () => {
            if (closeReportModalBtn.textContent === 'Cancel Report' && currentJobId) {
                if (confirm('Are you sure you want to stop generating this report?')) {
                    await fetch(`/api/statistics/report-cancel/${currentJobId}`, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value }
                    });
                    finishJob(false, currentJobId, "User cancelled the task.");
                }
            } else {
                reportModal.style.display = 'none';
            }
        });
    });
    </script>
    @endpush
</x-app-layout>
