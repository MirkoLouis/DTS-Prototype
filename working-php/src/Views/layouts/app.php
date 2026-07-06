<!DOCTYPE html>
<html lang="en" class="light overflow-y-scroll">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pure PHP DTS</title>
    
    <!-- Favicons -->
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicons/favicon-16x16.png">
    <link rel="apple-touch-icon" href="/favicons/apple-touch-icon.png">
    <link rel="manifest" href="/favicons/site.webmanifest">

    <!-- Local Tailwind CSS -->
    <link href="/css/tailwind.css" rel="stylesheet">
    <script>
        (function() {
            const theme = localStorage.getItem('theme');
            const isDark = theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (isDark) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        })();
    </script>
    
    <!-- Local Chart.js for Admin dashboard -->
    <script src="/js/chart.min.js"></script>
</head>
<body class="font-sans antialiased bg-gray-100 dark:bg-darkBg text-gray-900 dark:text-gray-100">

    <div class="min-h-screen bg-gray-100 dark:bg-darkBg">
        <!-- Navigation Bar -->
        <nav class="bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700">
            <div class="mx-[20vh] px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16">
                    <div class="flex">
                        <div class="shrink-0 flex items-center">
                            <a href="/">
                                <img src="/images/logoipsum-411.png" alt="DTS Logo" class="h-8 w-auto dark:brightness-200">
                            </a>
                        </div>

                        <!-- Navigation Links based on Role -->
                        <div class="hidden space-x-8 sm:-my-px sm:ml-10 sm:flex">
                            <?php 
                            if (isset($_SESSION['role'])): 
                                $currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
                                $activeClass = "inline-flex items-center px-1 pt-1 border-b-2 border-accent-1 text-sm font-medium leading-5 text-gray-900 dark:text-gray-100 focus:outline-none focus:border-accent-1-active transition duration-150 ease-in-out";
                                $inactiveClass = "inline-flex items-center px-1 pt-1 border-b-2 border-transparent hover:border-accent-1-hover text-sm font-medium leading-5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none focus:text-gray-700 dark:focus:text-gray-300 focus:border-gray-300 dark:focus:border-gray-600 transition duration-150 ease-in-out";
                            ?>
                                
                                <?php if ($_SESSION['role'] === 'admin'): ?>
                                    <a href="/admin-dashboard" class="<?= $currentPath === '/admin-dashboard' ? $activeClass : $inactiveClass ?>">
                                        Admin Dashboard
                                    </a>
                                    <a href="/all-documents" class="<?= $currentPath === '/all-documents' ? $activeClass : $inactiveClass ?>">
                                        All Documents
                                    </a>
                                    <a href="/system-overview" class="<?= $currentPath === '/system-overview' ? $activeClass : $inactiveClass ?>">
                                        System Overview
                                    </a>
                                    <a href="/users" class="<?= strpos($currentPath, '/users') === 0 ? $activeClass : $inactiveClass ?>">
                                        Users
                                    </a>
                                <?php elseif ($_SESSION['role'] === 'officer'): ?>
                                    <a href="/intake" class="<?= $currentPath === '/intake' ? $activeClass : $inactiveClass ?>">
                                        Dashboard
                                    </a>
                                    <a href="/tasks" class="<?= $currentPath === '/tasks' ? $activeClass : $inactiveClass ?>">
                                        Tasks
                                    </a>
                                    <a href="/tasks/completed" class="<?= $currentPath === '/tasks/completed' ? $activeClass : $inactiveClass ?>">
                                        Completed
                                    </a>
                                    <a href="/releasing" class="<?= $currentPath === '/releasing' ? $activeClass : $inactiveClass ?>">
                                        Releasing
                                    </a>
                                    <a href="/return-requests" class="<?= $currentPath === '/return-requests' ? $activeClass : $inactiveClass ?>">
                                        Return Requests
                                    </a>
                                    <a href="/statistics" class="<?= $currentPath === '/statistics' ? $activeClass : $inactiveClass ?>">
                                        Statistics
                                    </a>
                                <?php elseif ($_SESSION['role'] === 'staff'): ?>
                                    <a href="/tasks" class="<?= $currentPath === '/tasks' ? $activeClass : $inactiveClass ?>">
                                        Tasks
                                    </a>
                                    <a href="/tasks/completed" class="<?= $currentPath === '/tasks/completed' ? $activeClass : $inactiveClass ?>">
                                        Completed
                                    </a>
                                    <a href="/return-requests" class="<?= $currentPath === '/return-requests' ? $activeClass : $inactiveClass ?>">
                                        Return Requests
                                    </a>
                                    <a href="/statistics" class="<?= $currentPath === '/statistics' ? $activeClass : $inactiveClass ?>">
                                        Statistics
                                    </a>
                                <?php endif; ?>

                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- User Dropdown (Simplified) -->
                    <div class="hidden sm:flex sm:items-center sm:ml-6">
                        <div class="relative flex items-center">
                            <button id="theme-toggle" class="mr-4 p-2 bg-gray-100 dark:bg-gray-700 rounded-full shadow-md text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 focus:outline-none focus:ring-2 focus:ring-accent-1-hover transition-colors">
                                <!-- Sun icon for light mode -->
                                <svg id="theme-toggle-light-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" fill-rule="evenodd" clip-rule="evenodd"></path></svg>
                                <!-- Moon icon for dark mode -->
                                <svg id="theme-toggle-dark-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path></svg>
                            </button>
                            <div class="relative">
                                <button type="button" id="user-menu-button" class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none transition ease-in-out duration-150">
                                    <div><?= htmlspecialchars($_SESSION['user_name'] ?? 'Guest') ?> (<?= htmlspecialchars(ucfirst($_SESSION['role'] ?? '')) ?>)</div>
                                    <div class="ml-1">
                                        <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                </button>

                                <div id="user-dropdown-menu" class="hidden absolute right-0 z-50 mt-2 w-48 rounded-md shadow-lg bg-white dark:bg-gray-800 ring-1 ring-black ring-opacity-5 focus:outline-none py-1">
                                    <form method="POST" action="/logout">
                                        <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition duration-150 ease-in-out">Log Out</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Page Heading -->
        <?php if (isset($header)): ?>
            <header class="bg-white dark:bg-gray-800 shadow dark:shadow-none border-b border-gray-200 dark:border-gray-700">
                <div class="mx-[20vh] py-6 px-4 sm:px-6 lg:px-8">
                    <?= $header ?>
                </div>
            </header>
        <?php endif; ?>

        <!-- Page Content -->
        <main>
            <div class="mx-[20vh] sm:px-6 lg:px-8 mt-6">
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="bg-success-light border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                        <span class="block sm:inline"><?= htmlspecialchars($_SESSION['success']) ?></span>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                        <span class="block sm:inline"><?= htmlspecialchars($_SESSION['error']) ?></span>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['info'])): ?>
                    <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded relative mb-4" role="alert">
                        <span class="block sm:inline"><?= htmlspecialchars($_SESSION['info']) ?></span>
                    </div>
                    <?php unset($_SESSION['info']); ?>
                <?php endif; ?>
            </div>

            <?= $content ?? '' ?>
        </main>
    </div>

    <script>
        var themeToggleDarkIcon = document.getElementById('theme-toggle-dark-icon');
        var themeToggleLightIcon = document.getElementById('theme-toggle-light-icon');

        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            themeToggleLightIcon.classList.remove('hidden');
        } else {
            themeToggleDarkIcon.classList.remove('hidden');
        }

        var themeToggleBtn = document.getElementById('theme-toggle');

        if (themeToggleBtn) {
            themeToggleBtn.addEventListener('click', function() {
                themeToggleDarkIcon.classList.toggle('hidden');
                themeToggleLightIcon.classList.toggle('hidden');

                if (localStorage.getItem('theme')) {
                    if (localStorage.getItem('theme') === 'light') {
                        document.documentElement.classList.add('dark');
                        localStorage.setItem('theme', 'dark');
                    } else {
                        document.documentElement.classList.remove('dark');
                        localStorage.setItem('theme', 'light');
                    }
                } else {
                    if (document.documentElement.classList.contains('dark')) {
                        document.documentElement.classList.remove('dark');
                        localStorage.setItem('theme', 'light');
                    } else {
                        document.documentElement.classList.add('dark');
                        localStorage.setItem('theme', 'dark');
                    }
                }
            });
        }
        var userMenuBtn = document.getElementById('user-menu-button');
        var userDropdownMenu = document.getElementById('user-dropdown-menu');

        if (userMenuBtn && userDropdownMenu) {
            userMenuBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                userDropdownMenu.classList.toggle('hidden');
            });

            document.addEventListener('click', function(e) {
                if (!userMenuBtn.contains(e.target) && !userDropdownMenu.contains(e.target)) {
                    userDropdownMenu.classList.add('hidden');
                }
            });
        }
    </script>
</body>
</html>
