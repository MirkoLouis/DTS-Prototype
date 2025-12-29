<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DepEd Iligan - Document Tracking System</title>

    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    <style>
        body {
            background-color: #f8f9fa;
        }
        .container {
            max-width: 800px;
        }
        .card-header {
            background-color: #004281;
            color: white;
        }
        #requirements-list {
            list-style-type: none;
            padding-left: 0;
        }
        #requirements-list li {
            background-color: #e9ecef;
            padding: 8px 15px;
            border-radius: 4px;
            margin-bottom: 5px;
        }
        .other-purpose-input {
            display: none; /* Hidden by default */
        }
    </style>
</head>
<body class="antialiased">
    <div class="container mt-5">
        <div class="text-center mb-4">
            <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/3/33/DepEd_logo.svg/1200px-DepEd_logo.svg.png" alt="DepEd Logo" style="height: 80px;">
            <h1 class="mt-3">Document Tracking System</h1>
            <p class="lead">DepEd Division of Iligan City</p>
        </div>

        <div class="card">
            <div class="card-header">
                Start a New Document Request
            </div>
            <div class="card-body">
                <form method="POST" action="/submit-document">
                    @csrf
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="guest_name" class="form-label"><strong>Your Full Name</strong></label>
                            <input type="text" class="form-control" id="guest_name" name="guest_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="guest_email" class="form-label"><strong>Your Email Address</strong></label>
                            <input type="email" class="form-control" id="guest_email" name="guest_email" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="purpose_id" class="form-label"><strong>1. Select Purpose of Request</strong></label>
                        <select class="form-select" id="purpose-select" name="purpose_id" required>
                            <option selected disabled value="">Choose an option...</option>
                            @foreach ($purposes as $purpose)
                                <option value="{{ $purpose->id }}" data-requirements="{{ json_encode($purpose->requirements) }}">
                                    {{ $purpose->name }}
                                </option>
                            @endforeach
                            <option value="0">Other (Please specify)</option>
                        </select>
                    </div>

                    <div class="mb-3 other-purpose-input">
                        <label for="other_purpose_text" class="form-label"><strong>Please Specify Your Purpose</strong></label>
                        <input type="text" class="form-control" id="other_purpose_text" name="other_purpose_text">
                    </div>

                    <div id="requirements-section" class="mb-3" style="display: none;">
                        <label class="form-label"><strong>2. Requirements</strong></label>
                        <p class="text-muted small">Please prepare the following documents.</p>
                        <ul id="requirements-list">
                            <!-- Requirements will be injected here by JavaScript -->
                        </ul>
                    </div>

                    <hr>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg">Submit Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const purposeSelect = document.getElementById('purpose-select');
        const otherPurposeInput = document.querySelector('.other-purpose-input');
        const otherPurposeTextField = document.getElementById('other_purpose_text');
        const requirementsSection = document.getElementById('requirements-section');
        const requirementsList = document.getElementById('requirements-list');

        function updatePurposeFields() {
            const selectedOptionValue = purposeSelect.value;
            const selectedOption = purposeSelect.options[purposeSelect.selectedIndex];

            // Handle "Other" purpose input visibility
            if (selectedOptionValue === '0') {
                otherPurposeInput.style.display = 'block';
                otherPurposeTextField.setAttribute('required', 'required');
                requirementsSection.style.display = 'none'; // Hide requirements for "Other"
                requirementsList.innerHTML = '';
            } else {
                otherPurposeInput.style.display = 'none';
                otherPurposeTextField.removeAttribute('required');
                otherPurposeTextField.value = ''; // Clear input if not "Other"

                // Handle requirements display for specific purposes
                const requirements = JSON.parse(selectedOption.dataset.requirements || '[]');
                requirementsList.innerHTML = ''; // Clear previous list

                if (requirements.length > 0) {
                    requirements.forEach(req => {
                        const li = document.createElement('li');
                        li.textContent = req;
                        requirementsList.appendChild(li);
                    });
                    requirementsSection.style.display = 'block';
                } else {
                    requirementsSection.style.display = 'none';
                }
            }
        }

        // Initial call to set up the form correctly on page load
        updatePurposeFields();

        // Add event listener for changes
        purposeSelect.addEventListener('change', updatePurposeFields);
    </script>
</body>
</html>
