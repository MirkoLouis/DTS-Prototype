<?php
$unreadNotifications = [];
$sessionAlerts = [];

if (isset($_SESSION['success'])) {
    $sessionAlerts[] = ['type' => 'success', 'title' => 'Success', 'message' => $_SESSION['success'], 'is_toast' => true];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $sessionAlerts[] = ['type' => 'error', 'title' => 'Error', 'message' => $_SESSION['error'], 'is_toast' => true];
    unset($_SESSION['error']);
}
if (isset($_SESSION['info'])) {
    $sessionAlerts[] = ['type' => 'info', 'title' => 'Information', 'message' => $_SESSION['info'], 'is_toast' => true];
    unset($_SESSION['info']);
}

if (isset($_SESSION['user_id'])) {
    $notifService = new \App\Core\NotificationService();
    
    // Convert flash alerts into persistent notifications for this user
    foreach ($sessionAlerts as $alert) {
        $notifService->notifyUser($_SESSION['user_id'], $alert['title'], $alert['message'], $alert['type']);
    }
    
    $unreadNotifications = $notifService->getUnreadForCurrentUser();
}
?>
<!DOCTYPE html>
<html lang="en" class="light overflow-y-scroll">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>">
    <title>Pure PHP DTS</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/images/logoipsum-411.png">
    <link rel="apple-touch-icon" href="/images/logoipsum-411.png">

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
    <script src="/js/vendor/libsodium-wrappers.js"></script>
    <script src="/js/vendor/libsodium.js"></script>
    <script>
        window.UserConfig = {
            id: <?= json_encode($_SESSION['user_id'] ?? null) ?>,
            encryptedPrivKey: <?= json_encode($_SESSION['private_key'] ?? '') ?>
        };
    </script>
    <style>
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .animate-slide-in-right {
            animation: slideInRight 0.3s ease-out forwards;
        }
    </style>
    
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
                        <div id="pjax-nav-links" class="hidden space-x-8 sm:-my-px sm:ml-10 sm:flex">
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

                            <!-- Notification Dropdown -->
                            <div class="relative mr-4">
                                <button type="button" id="notification-menu-button" class="relative p-2 bg-gray-100 dark:bg-gray-700 rounded-full shadow-md text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 focus:outline-none focus:ring-2 focus:ring-accent-1-hover transition-colors">
                                    <span class="sr-only"></span>
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>

                                </button>
                                <div id="notification-dropdown-menu" class="hidden absolute right-0 z-50 mt-2 w-80 sm:w-96 rounded-md shadow-lg bg-white dark:bg-gray-800 ring-1 ring-black ring-opacity-5 focus:outline-none py-2 max-h-[80vh] overflow-y-auto">
                                    <div class="px-4 py-2 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Notifications</h3>
                                        <?php if (count($unreadNotifications) > 0): ?>
                                        <button id="mark-all-read" class="text-xs text-accent-1 hover:text-accent-1-hover dark:text-accent-2 dark:hover:text-accent-2-hover font-medium">Mark all as read</button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="p-2" id="notification-list">
                                        <?php if (empty($unreadNotifications)): ?>
                                            <p class="text-sm text-gray-500 dark:text-gray-400 p-4 text-center">No new notifications.</p>
                                        <?php else: ?>
                                            <?php foreach ($unreadNotifications as $notification): ?>
                                                <?php require BASE_PATH . '/src/Views/components/notification-alert.php'; ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

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
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
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
            <!-- pjax-router.js also swaps this element's innerHTML after each navigation -->
            <header id="pjax-header" class="bg-white dark:bg-gray-800 shadow dark:shadow-none border-b border-gray-200 dark:border-gray-700 relative z-10">
                <div class="mx-[20vh] py-6 px-4 sm:px-6 lg:px-8">
                    <?= $header ?>
                </div>
            </header>
        <?php else: ?>
            <!-- Render an empty placeholder so pjax-router.js always has a stable #pjax-header to swap -->
            <header id="pjax-header" class="hidden"></header>
        <?php endif; ?>

        <!-- Page Content -->

        <!-- PJAX top progress bar: a 3px gradient line that simulates loading
             progress. Driven entirely by pjax-router.js via inline style tweens. -->
        <div id="pjax-progress-bar" style="
            position: fixed; top: 0; left: 0; z-index: 99999;
            height: 3px; width: 0%; opacity: 0;
            background: linear-gradient(90deg, #6366f1, #a855f7, #ec4899);
            pointer-events: none;
            border-radius: 0 2px 2px 0;
        "></div>

        <main>
            <!-- Toast Notification Container -->
            <div id="toast-container" class="flex flex-col gap-3 pointer-events-none" style="position: fixed; bottom: 1.25rem; right: 1.25rem; z-index: 9999;">
                <?php
                foreach ($sessionAlerts as $alert) {
                    $notification = $alert; // notification-alert.php uses $notification
                    require BASE_PATH . '/src/Views/components/notification-alert.php';
                }
                ?>
            </div>

            <!-- PJAX content wrapper: pjax-router.js swaps innerHTML of this div
                 on every navigation, leaving the nav, progress bar, and modals intact. -->
            <div id="pjax-content">
                <?= $content ?? '' ?>
            </div>
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
    <?php if (isset($_SESSION['console_error'])): ?>
        <script>
            console.error(<?= json_encode($_SESSION['console_error']) ?>);
        </script>
        <?php unset($_SESSION['console_error']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['needs_security_key']) && $_SESSION['needs_security_key']): ?>
    <?php
    $modalId = 'security-key-modal';
    $modalTitle = 'Action Required: Setup Your Digital Signature';
    $modalSize = 4; // max-w-lg
    $hideCloseButton = true;
    
    $modalContent = '
        <p class="mb-2 text-gray-600 dark:text-gray-400">
            To interact with documents, you must generate a cryptographic Ed25519 digital signature. 
            This ensures non-repudiation and secures the blockchain-like document ledger against tampering.
        </p>
        <form action="/security-key" method="POST" id="security-key-form">
            <input type="hidden" name="csrf_token" value="' . ($_SESSION['csrf_token'] ?? '') . '">
            <!-- Hidden submit to safely capture Enter key -->
            <button type="submit" class="hidden" aria-hidden="true"></button>
            <div class="mb-4">
                <label for="pin" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Create a 6-digit Security PIN</label>
                <input type="password" name="pin" id="pin" minlength="6" required class="mt-1 block w-full border-gray-300 dark:border-gray-600 rounded-md shadow-sm sm:text-sm p-2 border bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-accent-1 focus:border-accent-1">
            </div>
            <div class="mb-4">
                <label for="pin_confirm" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Confirm Security PIN</label>
                <input type="password" name="pin_confirm" id="pin_confirm" minlength="6" required class="mt-1 block w-full border-gray-300 dark:border-gray-600 rounded-md shadow-sm sm:text-sm p-2 border bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-accent-1 focus:border-accent-1">
                <p class="mt-2 text-sm text-gray-500">This PIN will be used to encrypt your private key. You will need it every time you approve or process a document.</p>
            </div>
        </form>
    ';
    
    $modalFooter = '
        <form action="/logout" method="POST" class="inline-block m-0">
            <input type="hidden" name="csrf_token" value="' . ($_SESSION['csrf_token'] ?? '') . '">
            <button type="submit" class="inline-flex items-center px-4 py-2 bg-gray-200 dark:bg-gray-700 border border-transparent rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-300 dark:hover:bg-gray-600 focus:outline-none transition">
                Log Out
            </button>
        </form>
        <button type="submit" form="security-key-form" class="inline-flex items-center px-4 py-2 bg-accent-1 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-accent-1-hover active:bg-accent-1-active focus:outline-none focus:border-accent-1-border-active focus:ring focus:ring-accent-1-light transition">
            Generate & Secure Key
        </button>
    ';
    
    require BASE_PATH . '/src/Views/components/modal.php';
    ?>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const secModal = document.getElementById("security-key-modal");
            if (secModal) {
                secModal.classList.remove("hidden");
                document.body.style.overflow = "hidden";
                
                // Prevent closing on backdrop click by removing the triggering class
                const backdrop = secModal.querySelector(".close-modal-backdrop");
                if (backdrop) backdrop.classList.remove("close-modal-backdrop");
            }
        });
    </script>
    <?php endif; ?>

    <!-- Global Confirmation Modal -->
    <?php
    $modalId = 'global-confirmation-modal';
    $modalTitle = 'Confirm Action';
    $modalSize = 3; // max-w-md
    $hideCloseButton = false;
    $modalContent = '<p id="global-confirmation-message" class="text-sm text-gray-600 dark:text-gray-400"></p>';
    $modalFooter = '
        <button type="button" class="close-modal-btn inline-flex items-center px-4 py-2 bg-gray-200 dark:bg-gray-700 border border-transparent rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-300 dark:hover:bg-accent-2 focus:outline-none transition" data-modal="global-confirmation-modal">Cancel</button>
        <button id="global-confirm-btn" class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 active:bg-red-700 focus:outline-none focus:border-red-700 focus:ring focus:ring-red-200 transition">Yes, Proceed</button>
    ';
    require BASE_PATH . '/src/Views/components/modal.php';
    ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            let activeConfirmForm = null;
            const globalConfirmModal = document.getElementById('global-confirmation-modal');
            const globalConfirmMessage = document.getElementById('global-confirmation-message');
            const globalConfirmBtn = document.getElementById('global-confirm-btn');

            document.querySelectorAll('.confirm-action').forEach(form => {
                form.addEventListener('submit', (e) => {
                    // Global confirmation interceptor. Pauses form submission, displays a customizable modal, 
                    // and safely resumes the native submit flow if the user approves.
                    if (form.dataset.confirmed === "true") {
                        // It was confirmed by the modal, let it pass through to other listeners or native submit
                        delete form.dataset.confirmed;
                        return;
                    }
                    e.preventDefault();
                    activeConfirmForm = form;
                    globalConfirmMessage.textContent = form.dataset.message || 'Are you sure you want to proceed?';
                    
                    // Show modal logic
                    globalConfirmModal.classList.remove('hidden');
                    
                    // Allow animation frame
                    requestAnimationFrame(() => {
                        globalConfirmModal.querySelector('.fixed.inset-0.bg-gray-900').classList.add('opacity-100');
                        globalConfirmModal.querySelector('.relative.w-full').classList.add('scale-100', 'opacity-100');
                    });
                });
            });

            if (globalConfirmBtn) {
                globalConfirmBtn.addEventListener('click', () => {
                    if (activeConfirmForm) {
                        globalConfirmModal.classList.add('hidden');
                        activeConfirmForm.dataset.confirmed = "true";
                        // Dispatch submit event so other JS listeners can intercept it, or it will submit natively
                        const submitEvent = new Event('submit', { bubbles: true, cancelable: true });
                        const cancelled = !activeConfirmForm.dispatchEvent(submitEvent);
                        if (!cancelled) {
                            activeConfirmForm.submit(); // Fallback if no listener prevented default
                        }
                    }
                });
            }

            // Close modal logic for global confirmation is handled by close-modal-btn logic which we also need to include or assume exists.
            // Let's add a generic close modal listener since it might not be defined globally yet.
            document.querySelectorAll('.close-modal-btn, .close-modal-backdrop').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const modalId = btn.dataset.modal;
                    if (!modalId) return;
                    
                    // If the click is on the backdrop, ensure we clicked the backdrop itself and not children
                    if (btn.classList.contains('close-modal-backdrop') && e.target !== btn) {
                        return;
                    }

                    const targetModal = document.getElementById(modalId);
                    if (targetModal) {
                        targetModal.classList.add('hidden');
                        if (modalId === 'global-confirmation-modal') {
                            activeConfirmForm = null;
                        }
                    }
                });
            });

            // Generic open modal listener
            document.querySelectorAll('.open-modal-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const modalId = btn.dataset.modal;
                    if (!modalId) return;
                    const targetModal = document.getElementById(modalId);
                    if (targetModal) {
                        targetModal.classList.remove('hidden');
                    }
                });
            });
        });

        // Notification Toggle Logic
        document.addEventListener('DOMContentLoaded', () => {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const notifBtn = document.getElementById('notification-menu-button');
            const notifDropdown = document.getElementById('notification-dropdown-menu');

            if (notifBtn && notifDropdown) {
                notifBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    notifDropdown.classList.toggle('hidden');
                });

                document.addEventListener('click', (e) => {
                    if (!notifBtn.contains(e.target) && !notifDropdown.contains(e.target)) {
                        notifDropdown.classList.add('hidden');
                    }
                });
            }

            // Mark single notification read
            document.querySelectorAll('.mark-notification-read').forEach(btn => {
                btn.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    const notifId = btn.dataset.id;
                    if (!notifId) return;

                    try {
                        const res = await fetch('/api/notifications/mark-read', {
                            method: 'POST',
                            headers: { 
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken
                            },
                            body: JSON.stringify({ id: notifId })
                        });
                        const data = await res.json();
                        if (data.success) {
                            btn.closest('[role="alert"]').remove();
                            updateNotificationBadge();
                        }
                    } catch (err) {
                        console.error('Error marking notification read', err);
                    }
                });
            });

            // Dismiss single session alert
            document.querySelectorAll('.dismiss-alert').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    btn.closest('[role="alert"]').remove();
                });
            });

            // Mark all read
            const markAllBtn = document.getElementById('mark-all-read');
            if (markAllBtn) {
                markAllBtn.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    try {
                        const res = await fetch('/api/notifications/mark-read', {
                            method: 'POST',
                            headers: { 
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken
                            },
                            body: JSON.stringify({})
                        });
                        const data = await res.json();
                        if (data.success) {
                            document.getElementById('notification-list').innerHTML = '<p class="text-sm text-gray-500 dark:text-gray-400 p-4 text-center">No new notifications.</p>';
                            markAllBtn.remove();
                            const badge = document.querySelector('#notification-menu-button span.bg-red-600');
                            if (badge) badge.remove();
                        }
                    } catch (err) {
                        console.error('Error marking all notifications read', err);
                    }
                });
            }

            function updateNotificationBadge() {
                const badge = document.querySelector('#notification-menu-button span.bg-red-600');
                if (badge) {
                    let count = parseInt(badge.innerText, 10);
                    count = isNaN(count) ? 0 : count - 1;
                    if (count <= 0) {
                        badge.remove();
                    } else {
                        badge.innerText = count;
                    }
                }
            }

            // Auto-dismiss session toasts after 5 seconds
            document.querySelectorAll('.toast-message').forEach(toast => {
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.style.transition = 'opacity 0.5s ease-out';
                        toast.style.opacity = '0';
                        setTimeout(() => toast.remove(), 500);
                    }
                }, 5000);
            });
        });
    </script>

    <!-- PJAX Router: must be loaded last so all layout-level DOM is available -->
    <script src="/js/pjax-router.js"></script>
</body>
</html>
