<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Submitted Successfully</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
</head>
<body>
    <div class="container mt-5">
        <div class="text-center mb-4">
            <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/3/33/DepEd_logo.svg/1200px-DepEd_logo.svg.png" alt="DepEd Logo" style="height: 80px;">
        </div>
        <div class="alert alert-success text-center">
            <h1 class="alert-heading">Request Submitted!</h1>
            <p>Thank you for submitting your document request. Your request has been received and is now being processed.</p>
            <hr>
            <p class="mb-0">Please save your tracking code. You can use it to check the status of your document.</p>
            <h2 class="mt-3">Your Tracking Code:</h2>
            <p class="display-4" style="font-weight: 500;">{{ $tracking_code }}</p>
            <a href="{{ route('track', ['codes' => $tracking_code]) }}" class="btn btn-info mt-3">Track Your Document</a>
        </div>
        <div class="text-center mt-4">
            <a href="{{ route('welcome') }}" class="btn btn-primary">Submit Another Request</a>
        </div>
    </div>
</body>
</html>