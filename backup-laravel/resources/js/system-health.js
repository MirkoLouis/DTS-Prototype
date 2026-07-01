import Chart from 'chart.js/auto';

document.addEventListener('DOMContentLoaded', function () {
    // Database Performance Chart
    const dbPerformanceContainer = document.getElementById('db-performance-chart-container');
    if (dbPerformanceContainer) {
        const dbPerformanceCtx = document.getElementById('dbPerformanceChart').getContext('2d');
        const dbPerformancePeriodSelect = document.getElementById('db-performance-period');
        const dbPerformanceUrl = dbPerformanceContainer.dataset.url;
        let dbPerformanceChart;

        const fetchAndRenderDbPerformanceChart = (period) => {
            fetch(`${dbPerformanceUrl}?period=${period}`)
                .then(response => response.json())
                .then(data => {
                    if (dbPerformanceChart) {
                        dbPerformanceChart.destroy();
                    }
                    dbPerformanceChart = new Chart(dbPerformanceCtx, {
                        type: 'line',
                        data: data,
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    type: 'linear',
                                    display: true,
                                    position: 'left',
                                    title: { display: true, text: 'Connections' }
                                },
                                y1: {
                                    type: 'linear',
                                    display: true,
                                    position: 'right',
                                    title: { display: true, text: 'Avg Query Time (ms)' },
                                    grid: { drawOnChartArea: false }
                                },
                                y2: {
                                    type: 'linear',
                                    display: false, // Hidden axis for slow queries
                                    position: 'right',
                                }
                            }
                        }
                    });
                });
        };

        fetchAndRenderDbPerformanceChart(dbPerformancePeriodSelect.value);
        dbPerformancePeriodSelect.addEventListener('change', () => {
            fetchAndRenderDbPerformanceChart(dbPerformancePeriodSelect.value);
        });
    }


    // Run Integrity Check with Progress Modal
    const runCheckButton = document.getElementById('run-integrity-check');
    const integrityModal = document.getElementById('integrity-progress-modal');
    const closeIntegrityModalBtn = document.getElementById('close-integrity-modal');
    const integrityProgressBar = document.getElementById('integrity-progress-bar');
    const integrityProgressText = document.getElementById('integrity-progress-text');
    const integrityProgressTime = document.getElementById('integrity-progress-time');

    let pollingInterval;
    let startTime;
    let currentJobId;

    if (runCheckButton) {
        runCheckButton.addEventListener('click', async function() {
            try {
                runCheckButton.disabled = true;
                
                const response = await fetch(runCheckButton.dataset.url, {
                    method: 'POST',
                    headers: { 
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                if (!response.ok) throw new Error('Could not start integrity check.');

                const data = await response.json();
                currentJobId = data.job_id;

                // Show Modal
                integrityModal.style.display = 'block';
                integrityProgressBar.style.width = '0%';
                integrityProgressBar.classList.remove('bg-green-500', 'bg-red-500');
                integrityProgressBar.classList.add('bg-indigo-600');
                integrityProgressText.textContent = 'Initializing check...';
                integrityProgressTime.textContent = 'Est. time remaining: Calculating...';
                
                closeIntegrityModalBtn.textContent = 'Cancel Verification';
                closeIntegrityModalBtn.classList.remove('bg-white', 'text-gray-700', 'hover:bg-gray-50', 'dark:bg-gray-600', 'dark:text-gray-200', 'dark:hover:bg-gray-500');
                closeIntegrityModalBtn.classList.add('bg-red-500', 'text-white', 'hover:bg-red-600');

                startTime = Date.now();

                // Start polling
                pollingInterval = setInterval(() => {
                    pollIntegrityStatus(currentJobId);
                }, 3000);

            } catch (error) {
                console.error('Error:', error);
                alert(error.message);
                runCheckButton.disabled = false;
            }
        });
    }

    async function pollIntegrityStatus(jobId) {
        try {
            const response = await fetch(`/api/system-health/integrity-status/${jobId}`);
            if (!response.ok) throw new Error('Could not get integrity status.');
            const job = await response.json();

            integrityProgressBar.style.width = `${job.progress || 0}%`;
            
            let statusMsg = "Verifying...";
            if (job.progress <= 5) statusMsg = "Job queued...";
            else if (job.progress <= 50) statusMsg = "Verifying historical log hashes and signatures...";
            else if (job.progress < 100) statusMsg = "Comparing live document states with last logs...";
            
            integrityProgressText.textContent = `${statusMsg} (${job.progress || 0}%)`;

            if (job.progress > 0 && job.progress < 100) {
                const elapsed = (Date.now() - startTime) / 1000;
                const estimatedTotal = elapsed / (job.progress / 100);
                const remaining = Math.max(0, estimatedTotal - elapsed);
                integrityProgressTime.textContent = `Est. time remaining: ${formatTime(remaining)}`;
            }

            if (job.status === 'completed') {
                finishIntegrityJob(true, jobId);
            } else if (job.status === 'failed') {
                finishIntegrityJob(false, jobId, job.error_message);
            } else if (job.status === 'cancelled') {
                finishIntegrityJob(false, jobId, "Verification was cancelled.");
            }
        } catch (error) {
            console.error('Polling error:', error);
        }
    }

    function finishIntegrityJob(success, jobId, error = null) {
        clearInterval(pollingInterval);
        integrityProgressBar.style.width = '100%';
        integrityProgressBar.classList.remove('bg-indigo-600');
        closeIntegrityModalBtn.textContent = 'Close & Refresh';
        closeIntegrityModalBtn.classList.remove('bg-red-500', 'text-white', 'hover:bg-red-600');
        closeIntegrityModalBtn.classList.add('bg-white', 'text-gray-700', 'hover:bg-gray-50', 'dark:bg-gray-600', 'dark:text-gray-200', 'dark:hover:bg-gray-500');

        if (success) {
            integrityProgressBar.classList.add('bg-green-500');
            integrityProgressText.textContent = 'Integrity verification complete!';
            integrityProgressTime.textContent = 'System verified.';
            
            // Auto-refresh after a short delay if successful
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            integrityProgressBar.classList.add('bg-red-500');
            integrityProgressText.textContent = `Error: ${error || 'An unknown error occurred.'}`;
            integrityProgressTime.textContent = 'Verification Failed';
        }
    }

    function formatTime(seconds) {
        if (seconds < 1) return "Soon...";
        if (seconds < 60) return `${Math.round(seconds)}s`;
        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = Math.round(seconds % 60);
        return `${minutes}m ${remainingSeconds}s`;
    }

    if (closeIntegrityModalBtn) {
        closeIntegrityModalBtn.addEventListener('click', async () => {
            if (closeIntegrityModalBtn.textContent === 'Cancel Verification' && currentJobId) {
                if (confirm('Are you sure you want to stop the integrity check?')) {
                    await fetch(`/api/system-health/integrity-cancel/${currentJobId}`, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
                    });
                    finishIntegrityJob(false, currentJobId, "User cancelled the task.");
                }
            } else {
                integrityModal.style.display = 'none';
                window.location.reload();
            }
        });
    }


    // Handle form submissions for actions
    document.querySelectorAll('.rebuild-form, .freeze-form, .unfreeze-form').forEach(form => {
        form.addEventListener('submit', function(event) {
            event.preventDefault();
            
            let confirmationMessage = 'Are you sure you want to proceed with this action?';
            if (form.classList.contains('rebuild-form')) {
                confirmationMessage = 'Are you sure you want to rebuild the hash chain from this point? This action cannot be undone and will create a log entry.';
            } else if (form.classList.contains('freeze-form')) {
                confirmationMessage = 'Are you sure you want to freeze this document? This will prevent any further actions on it.';
            } else if (form.classList.contains('unfreeze-form')) {
                confirmationMessage = 'Are you sure you want to unfreeze this document?';
            }

            if (confirm(confirmationMessage)) {
                submitForm(this);
            }
        });
    });

    function submitForm(form) {
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        button.textContent = 'Processing...';

        fetch(form.action, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'X-Requested-With': 'XMLHttpRequest' },
        })
        .then(response => response.json().then(data => ({ status: response.status, body: data })))
        .then(response => {
            alert(response.body.message || (response.status === 200 ? 'Action completed successfully.' : 'An error occurred.'));
            if (response.status === 200) {
                window.location.reload();
            } else {
                button.disabled = false;
                // Reset text based on original form class
                if (form.classList.contains('rebuild-form')) button.textContent = 'Rebuild Chain';
                else if (form.classList.contains('freeze-form')) button.textContent = 'Freeze';
                else if (form.classList.contains('unfreeze-form')) button.textContent = 'Unfreeze';
            }
        })
        .catch(error => {
            console.error('Form submission error:', error);
            alert('A network error occurred. Please try again.');
            button.disabled = false;
            if (form.classList.contains('rebuild-form')) button.textContent = 'Rebuild Chain';
            else if (form.classList.contains('freeze-form')) button.textContent = 'Freeze';
            else if (form.classList.contains('unfreeze-form')) button.textContent = 'Unfreeze';
        });
    }
});
