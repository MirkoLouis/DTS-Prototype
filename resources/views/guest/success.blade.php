<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Submitted Successfully</title>

    <!-- Theme Detection Script -->
    <script>
        (function() {
            const theme = localStorage.getItem('theme');
            const isDark = theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (isDark) {
                document.documentElement.classList.add('dark');
                document.documentElement.setAttribute('data-bs-theme', 'dark');
            } else {
                document.documentElement.classList.remove('dark');
                document.documentElement.setAttribute('data-bs-theme', 'light');
            }
        })();
    </script>

    @vite(['resources/scss/bootstrap.scss', 'resources/js/bootstrap_public.js'])
</head>
<body>
    <div class="fixed top-4 right-4 z-50">
        <x-theme-switcher />
    </div>
    <div class="container mt-5">
        <div class="text-center mb-4">
            <img src="{{ asset('images/logoipsum-411.png') }}" alt="DepEd Logo" style="height: 80px;">
        </div>
        <div class="card shadow-sm">
            <div class="card-header text-center bg-success text-white">
                <h1 class="alert-heading h3">Submission Successful!</h1>
            </div>
            <div class="card-body p-4 text-center">
                <p class="lead">Your document request has been received.</p>
                <hr class="my-4">

                <div class="alert alert-warning border-warning" role="alert">
                    <h4 class="alert-heading">Action Required!</h4>
                    <p>You <strong style="text-decoration: underline;">MUST</strong> print the official Document Tracking Form. This printed form must be submitted to the Records Office along with your documents to begin the process.</p>
                </div>

                <a href="{{ route('documents.print-tracking-form', ['document' => $document_id]) }}" class="btn btn-primary btn-lg mt-3" target="_blank">
                    <i class="bi bi-printer-fill me-2"></i> Print Document Tracking Form
                </a>

                <div class="mt-5">
                    <p class="mb-2">For your reference, here is your tracking information:</p>
                    <div class="d-inline-block p-3 border rounded bg-light">
                        <div class="mb-2">
                            {!! $qrCode !!}
                        </div>
                        <p class="h3" style="font-weight: 500;">{{ $tracking_code }}</p>
                    </div>
                </div>

                <div class="mt-4">
                    <a href="{{ route('track', ['codes' => $tracking_code]) }}" class="btn btn-info">Track Your Document Status</a>
                    <a href="{{ route('welcome') }}" class="btn btn-secondary">Submit Another Request</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>