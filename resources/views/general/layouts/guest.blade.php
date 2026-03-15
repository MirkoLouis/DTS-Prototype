<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

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

        <!-- Browser Cache Optimizations -->
        <meta http-equiv="Cache-Control" content="max-age=60, public">
        <link rel="dns-prefetch" href="{{ config('app.url') }}">
        <meta name="render-optimize" content="true">

        <!-- Fonts -->

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/css/fonts.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="fixed top-4 right-4 z-50">
            <x-theme-switcher />
        </div>
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100 dark:bg-gray-900">
            <div>
                <a href="/">
                    <x-application-logo class="w-20 h-20 fill-current text-gray-500" />
                </a>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white dark:bg-gray-800 shadow-md overflow-hidden sm:rounded-lg">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
