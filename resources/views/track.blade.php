<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Documents</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        .subway-map-wrapper { padding-top: 1rem; padding-bottom: 1rem; }
    </style>
</head>
<body>
    <div class="container-lg mt-5 mb-5">
        <div class="text-center mb-4">
            <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/3/33/DepEd_logo.svg/1200px-DepEd_logo.svg.png" alt="DepEd Logo" style="height: 80px;">
        </div>

        <div id="tracked-documents-container">
            @forelse($documents as $document)
                <x-document-card :document="$document" />
            @empty
                <div class="alert alert-info text-center card shadow-sm p-4">
                    <h4 class="alert-heading">No documents are being tracked yet.</h4>
                    <p>Enter a tracking code below to get started.</p>
                </div>
            @endforelse
        </div>

        <div class="text-center mt-4 bg-light p-3 rounded shadow-sm">
            <a href="{{ route('welcome') }}" class="btn btn-secondary me-2">Submit a New Document</a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#trackAnotherModal">
                Track Another Document
            </button>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="trackAnotherModal" tabindex="-1" aria-labelledby="trackAnotherModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="track-another-form">
                    <div class="modal-header">
                        <h1 class="modal-title fs-5" id="trackAnotherModalLabel">Track Another Document</h1>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="tracking_code_input" class="form-label">Enter Tracking Code:</label>
                            <input type="text" class="form-control" id="tracking_code_input" placeholder="e.g., DEPED-A1B2C3D4E5" required>
                        </div>
                        <div id="track-error-message" class="alert alert-danger d-none" role="alert"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Track</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const trackForm = document.getElementById('track-another-form');
            const trackAnotherModal = new bootstrap.Modal(document.getElementById('trackAnotherModal'));
            const trackingCodeInput = document.getElementById('tracking_code_input');
            const trackedDocumentsContainer = document.getElementById('tracked-documents-container');
            const trackErrorMessage = document.getElementById('track-error-message');

            function displayError(message) {
                trackErrorMessage.textContent = message;
                trackErrorMessage.classList.remove('d-none');
            }

            function clearError() {
                trackErrorMessage.classList.add('d-none');
                trackErrorMessage.textContent = '';
            }

            // Clear error message when modal is opened or input changes
            document.getElementById('trackAnotherModal').addEventListener('show.bs.modal', clearError);
            trackingCodeInput.addEventListener('input', clearError);


            if (trackForm) {
                trackForm.addEventListener('submit', async function (e) {
                    e.preventDefault();
                    clearError(); // Clear previous errors
                    const trackingCode = trackingCodeInput.value.trim();
                    if (!trackingCode) {
                        displayError('Please enter a tracking code.');
                        return;
                    }

                    // Get currently tracked codes from URL or data attributes
                    const urlParams = new URLSearchParams(window.location.search);
                    let currentCodes = urlParams.get('codes') ? urlParams.get('codes').split(',') : [];
                    
                    // Check if code is already being tracked
                    if (currentCodes.includes(trackingCode)) {
                        displayError(`Document ${trackingCode} is already being tracked on this page.`);
                        return;
                    }

                    // Update URL
                    currentCodes.push(trackingCode); // Add new code
                    urlParams.set('codes', currentCodes.join(','));
                    history.pushState(null, '', `?${urlParams.toString()}`);

                    // Make AJAX call to get the new document card
                    try {
                        const response = await fetch(`/api/track-document/${trackingCode}`);
                        if (!response.ok) {
                            if (response.status === 404) {
                                displayError(`Document with tracking code ${trackingCode} not found.`);
                            } else {
                                displayError('Error tracking document. Please try again.');
                            }
                            return;
                        }
                        const htmlContent = await response.text();
                        
                        // Append the new card to the container
                        // First, check if the "No documents" alert is present and remove it
                        const noDocumentsAlert = trackedDocumentsContainer.querySelector('.alert.alert-info');
                        if (noDocumentsAlert) {
                            noDocumentsAlert.remove();
                        }
                        trackedDocumentsContainer.insertAdjacentHTML('beforeend', htmlContent);

                    } catch (error) {
                        console.error('Error fetching document module:', error);
                        displayError('Network error. Please try again.');
                    } finally {
                        trackAnotherModal.hide(); // Hide the modal
                        trackingCodeInput.value = ''; // Clear input
                    }
                });
            }
        });
    </script>
</body>
</html>
