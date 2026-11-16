<?php $title = 'System Health Monitor'; ob_start(); ?>
<h1 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
    System Health Monitor
</h1>
<?php $header = ob_get_clean(); ?>

<?php ob_start(); ?>
<div class="py-10">
    <div class="px-4 sm:px-6 lg:px-8 xl:mx-[20vh] space-y-6">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
            <h2 class="text-2xl font-bold mb-4">System Health Overview</h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="bg-gray-50 p-6 rounded-lg shadow">
                    <h3 class="text-lg font-bold mb-2">Avg. Processing Time</h3>
                    <p class="text-3xl font-semibold"><?= htmlspecialchars(round($appHealthMetrics['average_processing_time'] / 60, 1)) ?> min</p>
                </div>
                <div class="bg-gray-50 p-6 rounded-lg shadow">
                    <h3 class="text-lg font-bold mb-2">Failed Jobs</h3>
                    <p class="text-3xl font-semibold <?= $appHealthMetrics['failed_jobs_count'] > 0 ? 'text-red-500' : '' ?>"><?= htmlspecialchars($appHealthMetrics['failed_jobs_count']) ?></p>
                </div>
                <div class="bg-gray-50 p-6 rounded-lg shadow">
                    <h3 class="text-lg font-bold mb-2">Cache Status</h3>
                    <p class="text-3xl font-semibold <?= $appHealthMetrics['cache_status'] ? 'text-green-500' : 'text-red-500' ?>">
                        <?= $appHealthMetrics['cache_status'] ? 'Operational' : 'Error' ?>
                    </p>
                </div>
            </div>

            <!-- Database Integrity -->
            <div class="bg-gray-50 p-6 rounded-lg shadow mb-6">
                <h2 class="text-xl font-bold mb-4 border-b pb-2">Database Integrity</h2>
                <div class="text-center">
                    <div class="text-5xl font-bold <?= $integrityCheckResult['verified_percentage'] == 100 ? 'text-green-500' : 'text-red-500' ?>">
                        <?= htmlspecialchars($integrityCheckResult['verified_percentage']) ?>%
                    </div>
                    <div class="text-sm text-gray-500 mt-2">Chain Integrity</div>
                    <div class="text-xs text-gray-400 mt-3">Last checked: <?= htmlspecialchars($integrityCheckResult['last_checked']) ?></div>
                    <div class="mt-6">
                        <button onclick="fetch('/system-health/run-check', {method:'POST'}).then(()=>location.reload())" class="px-4 py-2 bg-accent-1 text-white rounded-md">Run Verification</button>
                    </div>
                </div>
            </div>

            <!-- Admin Utilities -->
            <div class="bg-gray-50 p-6 rounded-lg shadow">
                <h2 class="text-xl font-bold mb-4 border-b pb-2">Admin Utilities</h2>
                <a href="/system/backups" class="block p-4 bg-white rounded-lg shadow hover:bg-gray-50">
                    <h3 class="text-lg font-bold text-accent-1">Backup Manager &rarr;</h3>
                </a>
            </div>

        </div>
    </div>
</div>
<?php $content = ob_get_clean(); ?>
<?php require BASE_PATH . '/src/Views/layouts/app.php'; ?>
