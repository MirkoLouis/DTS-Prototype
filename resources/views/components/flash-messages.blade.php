@props(['id' => 'session-alerts'])

<div id="{{ $id }}" class="space-y-4">
    @if (session('success'))
        <div id="success-alert" class="p-4 text-sm text-green-800 rounded-lg bg-green-50 dark:bg-gray-800 dark:text-green-400" role="alert">
            <span class="font-medium">Success!</span> {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div id="error-alert" class="p-4 text-sm text-red-800 rounded-lg bg-red-50 dark:bg-gray-800 dark:text-red-400" role="alert">
            <span class="font-medium">Error!</span> {{ session('error') }}
        </div>
    @endif

    @if (session('info'))
        <div id="info-alert" class="p-4 text-sm text-blue-800 rounded-lg bg-blue-50 dark:bg-gray-800 dark:text-blue-400" role="alert">
            <span class="font-medium">Info:</span> {{ session('info') }}
        </div>
    @endif
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const successAlert = document.getElementById('success-alert');
        if (successAlert) {
            setTimeout(() => {
                successAlert.style.transition = 'opacity 0.5s ease';
                successAlert.style.opacity = '0';
                setTimeout(() => successAlert.remove(), 500);
            }, 3000);
        }

        const errorAlert = document.getElementById('error-alert');
        if (errorAlert) {
            setTimeout(() => {
                errorAlert.style.transition = 'opacity 0.5s ease';
                errorAlert.style.opacity = '0';
                setTimeout(() => errorAlert.remove(), 500);
            }, 5000); // Errors last a bit longer
        }

        const infoAlert = document.getElementById('info-alert');
        if (infoAlert) {
            setTimeout(() => {
                infoAlert.style.transition = 'opacity 0.5s ease';
                infoAlert.style.opacity = '0';
                setTimeout(() => infoAlert.remove(), 500);
            }, 5000); // Info also lasts a bit longer
        }
    });
</script>
@endpush
