<?php $title = 'Admin Dashboard'; ob_start(); ?>
<div class="py-2">
        <div class="mx-[20vh] sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-2xl font-bold">Admin Dashboard</h2>
                        <form action="/clear-personal-cache" method="POST" class="confirm-action" data-message="Are you sure you want to clear your dashboard cache? This will refresh your view but preserve others' caches.">
                            <button type="submit" class="inline-flex items-center px-3 py-1 bg-gray-200 dark:bg-gray-700 border border-transparent rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-300 dark:hover:bg-accent-2 focus:outline-none transition">
                                Clear My Cache
                            </button>
                        </form>
                    </div>

                    <div class="space-y-6"
                         data-current-load-url="/api/admin-dashboard/current-load"
                         data-throughput-url="/api/admin-dashboard/throughput"
                         data-return-decline-url="/api/admin-dashboard/return-decline-trends"
                         data-status-distribution-url="/api/admin-dashboard/status-distribution"
                         data-return-request-sources-url="/api/admin-dashboard/return-request-sources"
                         data-processing-hotspots-url="/api/admin-dashboard/processing-hotspots"
                         data-avg-step-time-url="/api/admin-dashboard/avg-step-time"
                         data-load-vs-time-url="/api/admin-dashboard/department-load-vs-time"
                         data-submission-districts-url="/api/admin-dashboard/submission-districts">

                        <!-- Section: Processing Analytics -->
                        <div class="bg-gray-50 dark:bg-gray-700/50 pt-3 px-5 pb-5 rounded-lg shadow">
                            <h3 class="text-xl font-bold mb-4">Processing Analytics</h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                                <!-- Document Status Distribution Chart -->
                                <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-inner">
                                    <h4 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Document Status Distribution</h4>
                                    <div class="relative h-64">
                                        <canvas id="statusDistributionChart"></canvas>
                                    </div>
                                </div>

                                <!-- Global Throughput Chart -->
                                <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-inner">
                                    <div class="mb-4 border-b border-gray-200 dark:border-gray-600 pb-4">
                                        <h4 class="text-lg font-bold mb-3">Departmental Average TAT over time (hrs)</h4>
                                        <div class="w-full">
                                            <select id="globalThroughputPeriod" class="filter-input block w-full border-gray-300 dark:border-gray-600 rounded-md shadow-sm sm:text-sm p-2 border bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-accent-1 focus:border-accent-1">
                                                <option value="daily">Daily</option>
                                                <option value="weekly">Weekly</option>
                                                <option value="monthly">Monthly</option>
                                                <option value="yearly">Yearly</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="relative h-64">
                                        <canvas id="throughputChart"></canvas>
                                    </div>
                                </div>

                                <!-- Average Processing Time by Dept Chart -->
                                <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-inner flex flex-col">
                                    <h4 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Average TAT by Department (hrs)</h4>
                                    <div class="relative h-64 flex-grow">
                                        <canvas id="avgStepTimeChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Section: Returns & Declines Analysis -->
                        <div class="bg-gray-50 dark:bg-gray-700/50 pt-3 px-5 pb-5 rounded-lg shadow">
                            <h3 class="text-xl font-bold mb-4">Returns & Declines Analysis</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Return & Decline Trends Chart -->
                                <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-inner">
                                    <div class="mb-4 border-b border-gray-200 dark:border-gray-600 pb-4">
                                        <h4 class="text-lg font-bold mb-3">Return & Decline Rate Trends</h4>
                                        <div class="w-full">
                                            <select id="returnDeclinePeriod" class="filter-input block w-full border-gray-300 dark:border-gray-600 rounded-md shadow-sm sm:text-sm p-2 border bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-accent-1 focus:border-accent-1">
                                                <option value="daily">Daily</option>
                                                <option value="weekly">Weekly</option>
                                                <option value="monthly">Monthly</option>
                                                <option value="yearly">Yearly</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="relative h-64">
                                        <canvas id="returnDeclineChart"></canvas>
                                    </div>
                                </div>

                                <!-- Return Request Sources Chart -->
                                <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-inner">
                                    <h4 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Return Request Sources</h4>
                                    <div class="relative h-64">
                                        <canvas id="returnRequestSourcesChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Section: Department Drill-Down -->
                        <div class="bg-gray-50 dark:bg-gray-700/50 pt-3 px-5 pb-5 rounded-lg shadow">
                            <h3 class="text-xl font-bold mb-4">Department Drill-Down</h3>
                            <!-- Shared Filters -->
                            <div class="flex flex-row flex-wrap items-end gap-3 pb-4 border-b border-gray-200 dark:border-gray-700 w-full mb-6">
                                <div class="flex-grow flex-shrink-0" style="flex-basis: auto; min-width: 150px;">
                                    <label for="department-filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Department</label>
                                    <select id="department-filter" class="filter-input block w-full border-gray-300 dark:border-gray-600 rounded-md shadow-sm sm:text-sm p-2 border bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-accent-1 focus:border-accent-1">
                                        <option value="all">All Departments</option>
                                        <?php foreach($departments as $department): ?>
                                            <option value="<?= htmlspecialchars($department['id']) ?>"><?= htmlspecialchars($department['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="flex-grow flex-shrink-0" style="flex-basis: auto; min-width: 150px;">
                                    <label for="departmentPeriod" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Time Period</label>
                                    <select id="departmentPeriod" class="filter-input block w-full border-gray-300 dark:border-gray-600 rounded-md shadow-sm sm:text-sm p-2 border bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-accent-1 focus:border-accent-1">
                                        <option value="daily">Daily</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="monthly">Monthly</option>
                                        <option value="yearly">Yearly</option>
                                    </select>
                                </div>
                            </div>

                            <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-inner">
                                <h4 id="load-vs-time-title" class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Load vs. Processing Time</h4>
                                <div class="relative h-96">
                                    <canvas id="loadVsTimeChart"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Section: Purpose & Origin Analysis -->
                        <div class="bg-gray-50 dark:bg-gray-700/50 pt-3 px-5 pb-5 rounded-lg shadow mt-6">
                            <h3 class="text-xl font-bold mb-4">Purpose & Origin Analysis</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Processing Hotspots Chart -->
                                <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-inner">
                                    <h4 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Processing Hotspots (Purpose Popularity)</h4>
                                    <div class="relative h-96">
                                        <canvas id="processingHotspotsChart"></canvas>
                                    </div>
                                </div>

                                <!-- Submission Volume by District Chart -->
                                <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-inner">
                                    <h4 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Submission Volume by District</h4>
                                    <div class="relative h-96">
                                        <canvas id="submissionDistrictsChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

        
            <script src="/js/admin-dashboard.js"></script>
        
    <?php $content = ob_get_clean(); require BASE_PATH . '/src/Views/layouts/app.php'; ?>
    