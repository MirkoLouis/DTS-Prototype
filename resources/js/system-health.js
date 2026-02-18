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


    // Run Integrity Check
    const runCheckButton = document.getElementById('run-integrity-check');
    if (runCheckButton) {
        const buttonSpinner = document.getElementById('button-spinner');
        const buttonText = document.getElementById('button-text');

        runCheckButton.addEventListener('click', function() {
            buttonSpinner.classList.remove('hidden');
            buttonText.textContent = 'Verifying...';
            runCheckButton.disabled = true;

            fetch(runCheckButton.dataset.url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    window.location.reload();
                } else {
                    alert('An error occurred while starting the integrity check.');
                    resetButton();
                }
            })
            .catch(error => {
                console.error('Error running integrity check:', error);
                resetButton();
            });
        });

        function resetButton() {
            buttonSpinner.classList.add('hidden');
            buttonText.textContent = 'Run Verification';
            runCheckButton.disabled = false;
        }
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
