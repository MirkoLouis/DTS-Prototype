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
    $notifService = new \App\Modules\Notifications\NotificationService();
    
    // Convert flash alerts into persistent notifications for this user
    foreach ($sessionAlerts as $alert) {
        $notifService->notifyUser($_SESSION['user_id'], $alert['title'], $alert['message'], $alert['type']);
    }
    
    $unreadNotifications = $notifService->getUnreadForCurrentUser();

    // Stored notifications marked to pop up on this page (taken once, so none is toasted twice); they also stay in the bell list
    $toastIds = \App\Modules\Notifications\NotificationService::takeToastIds();
    foreach ($unreadNotifications as $unreadNotification) {
        if (in_array((int) $unreadNotification['id'], $toastIds, true)) {
            $sessionAlerts[] = $unreadNotification + ['is_toast' => true];
        }
    }
}

// The role's navigation links, defined once and rendered in both the desktop bar and the mobile menu
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$navLinks = [];
if (($_SESSION['role'] ?? null) === 'admin') {
    $navLinks = [
        ['/admin-dashboard', 'Admin Dashboard', $currentPath === '/admin-dashboard'],
        ['/all-documents', 'All Documents', $currentPath === '/all-documents'],
        ['/system-overview', 'System Overview', $currentPath === '/system-overview'],
        ['/users', 'Users', strpos($currentPath, '/users') === 0],
    ];
} elseif (($_SESSION['role'] ?? null) === 'officer') {
    $navLinks = [
        ['/intake', 'Dashboard', $currentPath === '/intake'],
        ['/tasks', 'Tasks', $currentPath === '/tasks'],
        ['/tasks/completed', 'Completed', $currentPath === '/tasks/completed'],
        ['/releasing', 'Releasing', $currentPath === '/releasing'],
        ['/statistics', 'Statistics', $currentPath === '/statistics'],
    ];
} elseif (($_SESSION['role'] ?? null) === 'staff') {
    $navLinks = [
        ['/tasks', 'Tasks', $currentPath === '/tasks'],
        ['/tasks/completed', 'Completed', $currentPath === '/tasks/completed'],
        ['/statistics', 'Statistics', $currentPath === '/statistics'],
    ];
}

// Link classes: the desktop bar underlines the active link, the mobile menu marks it with a left border
$activeClass = "inline-flex items-center px-1 pt-1 border-b-2 border-accent-1 text-sm font-medium leading-5 text-gray-900 dark:text-gray-100 focus:outline-none focus:border-accent-1-active transition duration-150 ease-in-out";
$inactiveClass = "inline-flex items-center px-1 pt-1 border-b-2 border-transparent hover:border-accent-1-hover text-sm font-medium leading-5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none focus:text-gray-700 dark:focus:text-gray-300 focus:border-gray-300 dark:focus:border-gray-600 transition duration-150 ease-in-out";
$mobileActiveClass = "block pl-3 pr-4 py-2 border-l-4 border-accent-1 text-base font-medium text-gray-900 dark:text-gray-100 bg-gray-50 dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-accent-1-hover transition duration-150 ease-in-out";
$mobileInactiveClass = "block pl-3 pr-4 py-2 border-l-4 border-transparent text-base font-medium text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 hover:border-gray-300 dark:hover:border-gray-600 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-accent-1-hover transition duration-150 ease-in-out";
?>
<!DOCTYPE html>
<html lang="en" class="light overflow-y-scroll">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>">
    <title><?= htmlspecialchars($title ?? 'DTS', ENT_QUOTES, 'UTF-8') ?> — DepEd DTS</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/images/logoipsum-411.png">
    <link rel="apple-touch-icon" href="/images/logoipsum-411.png">

    <!-- Local Tailwind CSS -->
    <link href="/css/tailwind.css" rel="stylesheet">
    <!-- Applies the saved theme before the first paint (a blocking script file: no flash of the wrong theme) -->
    <script src="/js/layout/theme-init.js"></script>
    <!-- Server data for the layout scripts (public/js/layout/server-handoff.js): inert JSON, never executed -->
    <?= \App\Common\Utils\JsonDataBlock::render('dts-user-config', ['id' => $_SESSION['user_id'] ?? null]) ?>
    <style>
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .animate-slide-in-right {
            animation: slideInRight 0.3s ease-out forwards;
        }
    </style>
    
    <!-- Shared modal dialog behaviour (focus trap, Escape, inert page behind); loaded before any page script uses it -->
    <script src="/js/modal-dialog.js"></script>

    <!-- Local Chart.js for Admin dashboard -->
    <script src="/js/chart.min.js"></script>

    <!-- Every script is a file of this origin. They are loaded ONCE per full page load (never again after in-app navigation)
         and deferred, so they run in this order after the page is parsed and before DOMContentLoaded.
         Page and component scripts register their initializers with DtsPage.onLoad() (public/js/core/page-registry.js),
         which runs them on the first load and after every in-app navigation when the page's root element is present. -->
    <script src="/js/core/page-registry.js" defer></script>
    <script src="/js/core/json-data.js" defer></script>
    <script src="/js/core/utils.js" defer></script>
    <script src="/js/core/pin-token.js" defer></script>
    <script src="/js/html5-qrcode.min.js" defer></script>
    <script src="/js/sortable.min.js" defer></script>
    <script src="/js/layout/theme-toggle.js" defer></script>
    <script src="/js/layout/server-handoff.js" defer></script>
    <script src="/js/layout/navigation-menus.js" defer></script>
    <script src="/js/layout/global-confirm.js" defer></script>
    <script src="/js/layout/pin-setup-prompt.js" defer></script>
    <script src="/js/layout/notifications.js" defer></script>
    <script src="/js/components/copy-button.js" defer></script>
    <script src="/js/components/signing-modal.js" defer></script>
    <script src="/js/components/sign-actions.js" defer></script>
    <script src="/js/components/qr-scanner.js" defer></script>
    <script src="/js/pages/backups.js" defer></script>
    <script src="/js/pages/users.js" defer></script>
    <script src="/js/pages/show-document.js" defer></script>
    <script src="/js/pages/manage-documents.js" defer></script>
    <script src="/js/pages/admin-dashboard.js" defer></script>
    <script src="/js/pages/statistics.js" defer></script>
    <script src="/js/pages/system-overview.js" defer></script>
    <!-- The in-app navigation router comes last: it needs everything above and swaps #pjax-content -->
    <script src="/js/pjax-router.js" defer></script>
</head>
<body class="font-sans antialiased bg-gray-100 dark:bg-darkBg text-gray-900 dark:text-gray-100">

    <div class="min-h-screen bg-gray-100 dark:bg-darkBg">
        <!-- Navigation Bar -->
        <nav class="bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700">
            <div class="px-4 sm:px-6 lg:px-8 xl:mx-[20vh]">
                <div class="flex justify-between h-16">
                    <div class="flex">
                        <div class="shrink-0 flex items-center">
                            <a href="/dashboard" class="rounded focus:outline-none focus:ring-2 focus:ring-accent-1-hover">
                                <img src="/images/logoipsum-411.png" alt="DTS Logo" class="h-8 w-auto dark:brightness-200">
                            </a>
                        </div>

                        <!-- Navigation Links based on Role -->
                        <div id="pjax-nav-links" class="hidden space-x-8 lg:-my-px lg:ml-10 lg:flex">
                            <?php foreach ($navLinks as [$href, $label, $isActive]): ?>
                                <a href="<?= $href ?>" class="<?= $isActive ? $activeClass : $inactiveClass ?>"><?= $label ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- User Dropdown (Simplified) -->
                    <div class="flex items-center sm:ml-6">
                        <div class="flex items-center">
                            <button type="button" id="theme-toggle" aria-label="Toggle dark mode" class="mr-2 sm:mr-4 p-2 bg-gray-100 dark:bg-gray-700 rounded-full shadow-md text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 focus:outline-none focus:ring-2 focus:ring-accent-1-hover transition-colors">
                                <!-- Sun icon for light mode -->
                                <svg id="theme-toggle-light-icon" aria-hidden="true" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" fill-rule="evenodd" clip-rule="evenodd"></path></svg>
                                <!-- Moon icon for dark mode -->
                                <svg id="theme-toggle-dark-icon" aria-hidden="true" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path></svg>
                            </button>

                            <!-- Notification Dropdown -->
                            <div class="sm:relative mr-2 sm:mr-4">
                                <button type="button" id="notification-menu-button" aria-expanded="false" aria-controls="notification-dropdown-menu" class="relative p-2 bg-gray-100 dark:bg-gray-700 rounded-full shadow-md text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 focus:outline-none focus:ring-2 focus:ring-accent-1-hover transition-colors">
                                    <!-- The unread count is part of the accessible name (there is no visual badge); updated when notifications are read -->
                                    <span id="notification-button-text" class="sr-only"><?= count($unreadNotifications) > 0 ? 'Notifications, ' . count($unreadNotifications) . ' unread' : 'Notifications' ?></span>
                                    <svg aria-hidden="true" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                                </button>
                                <!-- On phones the panel spans the viewport under the nav bar; from sm up it drops down from the bell as before -->
                                <div id="notification-dropdown-menu" class="hidden fixed inset-x-2 top-16 sm:absolute sm:inset-x-auto sm:top-auto sm:right-0 z-50 mt-2 sm:w-96 rounded-md shadow-lg bg-white dark:bg-gray-800 ring-1 ring-black ring-opacity-5 focus:outline-none py-2 max-h-[80vh] overflow-y-auto">
                                    <div class="px-4 py-2 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                                        <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Notifications</h2>
                                        <?php if (count($unreadNotifications) > 0): ?>
                                        <button type="button" id="mark-all-read" class="rounded focus:outline-none focus:ring-2 focus:ring-accent-1 text-xs text-accent-1 hover:text-accent-1-hover dark:text-accent-2 dark:hover:text-accent-2-hover font-medium">Clear Notifications</button>
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

                            <!-- Desktop user menu; below lg the account links live in the mobile menu -->
                            <div class="relative hidden lg:block">
                                <button type="button" id="user-menu-button" aria-expanded="false" aria-controls="user-dropdown-menu" class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none focus:ring-2 focus:ring-accent-1-hover transition ease-in-out duration-150">
                                    <div><?= htmlspecialchars($_SESSION['user_name'] ?? 'Guest') ?> (<?= htmlspecialchars(ucfirst($_SESSION['role'] ?? '')) ?>)</div>
                                    <div class="ml-1">
                                        <svg aria-hidden="true" class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                </button>

                                <div id="user-dropdown-menu" class="hidden absolute right-0 z-50 mt-2 w-48 rounded-md shadow-lg bg-white dark:bg-gray-800 ring-1 ring-black ring-opacity-5 focus:outline-none py-1">
                                    <a href="<?= e(\App\Modules\Auth\CredentialPolicy::CHANGE_PASSWORD_PATH) ?>" class="block w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition duration-150 ease-in-out">
                                        <?= e(\App\Modules\Auth\CredentialPolicy::CHANGE_PASSWORD_LABEL) ?>
                                    </a>
                                    <form method="POST" action="/logout">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                        <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition duration-150 ease-in-out">Log Out</button>
                                    </form>
                                </div>
                            </div>

                            <!-- Navigation menu button (below lg) -->
                            <button type="button" id="mobile-menu-button" aria-controls="mobile-menu" aria-expanded="false" class="lg:hidden inline-flex items-center justify-center p-2 rounded-md text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-accent-1-hover transition">
                                <span class="sr-only">Open main menu</span>
                                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                    <path id="mobile-menu-open-icon" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                                    <path id="mobile-menu-close-icon" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Navigation menu (below lg): pjax-router.js swaps #pjax-mobile-nav-links so the active link stays current -->
            <div id="mobile-menu" class="hidden lg:hidden border-t border-gray-200 dark:border-gray-700">
                <!-- Everything here depends on the current page (active links) and is swapped as one block; the logout form carries the same session token as the new page's meta tag -->
                <div id="pjax-mobile-nav-links">
                    <div class="pt-2 pb-3 space-y-1">
                        <?php foreach ($navLinks as [$href, $label, $isActive]): ?>
                            <a href="<?= $href ?>" class="<?= $isActive ? $mobileActiveClass : $mobileInactiveClass ?>"><?= $label ?></a>
                        <?php endforeach; ?>
                    </div>
                    <div class="pt-4 pb-3 border-t border-gray-200 dark:border-gray-700">
                        <div class="px-4">
                            <div class="font-medium text-base text-gray-800 dark:text-gray-200"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Guest') ?></div>
                            <div class="font-medium text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars(ucfirst($_SESSION['role'] ?? '')) ?></div>
                        </div>
                        <div class="mt-3 space-y-1">
                            <a href="<?= e(\App\Modules\Auth\CredentialPolicy::CHANGE_PASSWORD_PATH) ?>" class="<?= $currentPath === \App\Modules\Auth\CredentialPolicy::CHANGE_PASSWORD_PATH ? $mobileActiveClass : $mobileInactiveClass ?>">
                                <?= e(\App\Modules\Auth\CredentialPolicy::CHANGE_PASSWORD_LABEL) ?>
                            </a>
                            <form method="POST" action="/logout">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                <button type="submit" class="<?= $mobileInactiveClass ?> w-full text-left">Log Out</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Page Heading -->
        <?php if (isset($header)): ?>
            <!-- pjax-router.js also swaps this element's innerHTML after each navigation -->
            <header id="pjax-header" class="bg-white dark:bg-gray-800 shadow dark:shadow-none border-b border-gray-200 dark:border-gray-700 relative z-10">
                <div class="py-4 sm:py-6 px-4 sm:px-6 lg:px-8 xl:mx-[20vh]">
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
        <div id="pjax-progress-bar" aria-hidden="true" style="
            position: fixed; top: 0; left: 0; z-index: 99999;
            height: 3px; width: 0%; opacity: 0;
            background: linear-gradient(90deg, #6366f1, #a855f7, #ec4899);
            pointer-events: none;
            border-radius: 0 2px 2px 0;
        "></div>

        <main>
            <!-- Toast Notification Container -->
            <div id="toast-container" data-modal-live-region class="flex flex-col gap-3 pointer-events-none fixed bottom-5 left-4 right-4 sm:left-auto sm:right-5 sm:max-w-md" style="z-index: 9999;">
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

    <?php // Session data for public/js/layout/server-handoff.js (a message for the browser console), as an inert JSON block ?>
    <?php if (isset($_SESSION['console_error'])): ?>
        <?= \App\Common\Utils\JsonDataBlock::render('dts-console-error', $_SESSION['console_error']) ?>
        <?php unset($_SESSION['console_error']); ?>
    <?php endif; ?>

    <?php // Hand the encrypted PIN-cache token to the browser, or tell it to discard a stale one (applied by server-handoff.js) ?>
    <?php if (isset($_SESSION[\App\Modules\Users\SecurityPinCacheService::SESSION_ISSUED]) || isset($_SESSION[\App\Modules\Users\SecurityPinCacheService::SESSION_CLEAR])): ?>
        <?= \App\Common\Utils\JsonDataBlock::render('dts-pin-token', [
            'clear' => isset($_SESSION[\App\Modules\Users\SecurityPinCacheService::SESSION_CLEAR]),
            'issued' => $_SESSION[\App\Modules\Users\SecurityPinCacheService::SESSION_ISSUED] ?? null,
        ]) ?>
        <?php unset($_SESSION[\App\Modules\Users\SecurityPinCacheService::SESSION_ISSUED], $_SESSION[\App\Modules\Users\SecurityPinCacheService::SESSION_CLEAR]); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['needs_security_key']) && $_SESSION['needs_security_key']): ?>
    <?php
    $modalId = 'security-key-modal';
    $modalTitle = 'Action Required: Setup Your Digital Signature';
    $modalSize = 4; // max-w-lg
    $hideCloseButton = true;
    $modalDismissible = false; // mandatory: Escape and clicks outside do nothing until the PIN is set or the user logs out

    $pinMinLength = \App\Modules\Auth\CredentialPolicy::MIN_LENGTH;

    // A refusal of the previous PIN setup attempt (taken once): shown in the prompt as an alert linked to both fields
    $pinSetupError = $_SESSION['pin_setup_error'] ?? null;
    unset($_SESSION['pin_setup_error']);
    $pinErrorAttributes = $pinSetupError === null ? '' : ' aria-invalid="true" aria-describedby="pin-setup-error"';
    $pinErrorHtml = $pinSetupError === null ? '' : '<p id="pin-setup-error" role="alert" class="mb-4 text-sm font-medium text-red-600 dark:text-red-400">' . e($pinSetupError) . '</p>';
    $modalContent = '
        <p class="mb-2 text-gray-600 dark:text-gray-400">
            To interact with documents, you must generate a cryptographic Ed25519 digital signature.
            This ensures non-repudiation and secures the blockchain-like document ledger against tampering.
        </p>
        <p class="mb-2 text-gray-600 dark:text-gray-400">
            <strong>Security PIN:</strong> ' . e(\App\Modules\Auth\CredentialPolicy::SECURITY_PIN_DEFINITION) . '
        </p>
        <p class="mb-4 text-gray-600 dark:text-gray-400">' . e(\App\Modules\Auth\CredentialPolicy::SHARED_NOTE) . '</p>
        ' . $pinErrorHtml . '
        <form action="/security-key" method="POST" id="security-key-form">
            <input type="hidden" name="csrf_token" value="' . ($_SESSION['csrf_token'] ?? '') . '">
            <!-- Hidden submit to safely capture Enter key -->
            <button type="submit" class="hidden" aria-hidden="true"></button>
            <div class="mb-4">
                <label for="pin" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Create a Security PIN (at least ' . $pinMinLength . ' characters)</label>
                <input type="password" name="pin" id="pin"' . $pinErrorAttributes . ' autocomplete="new-password" data-1p-ignore data-lpignore="true" minlength="' . $pinMinLength . '" required class="mt-1 block w-full border-gray-300 dark:border-gray-600 rounded-md shadow-sm sm:text-sm p-2 border bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-accent-1 focus:border-accent-1">
            </div>
            <div class="mb-4">
                <label for="pin_confirm" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Confirm Security PIN</label>
                <input type="password" name="pin_confirm" id="pin_confirm"' . $pinErrorAttributes . ' autocomplete="new-password" data-1p-ignore data-lpignore="true" minlength="' . $pinMinLength . '" required class="mt-1 block w-full border-gray-300 dark:border-gray-600 rounded-md shadow-sm sm:text-sm p-2 border bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-accent-1 focus:border-accent-1">
                <p class="mt-2 text-sm text-gray-500">This PIN will be used to encrypt your private key. You will need it every time you approve or process a document.</p>
            </div>
        </form>
    ';
    
    $modalFooter = '
        <form action="/logout" method="POST" class="inline-block m-0">
            <input type="hidden" name="csrf_token" value="' . ($_SESSION['csrf_token'] ?? '') . '">
            <button type="submit" class="inline-flex items-center px-4 py-2 bg-gray-200 dark:bg-gray-700 border border-transparent rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-300 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-accent-1 transition">
                Log Out
            </button>
        </form>
        <button type="submit" form="security-key-form" class="inline-flex items-center px-4 py-2 bg-accent-1 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-accent-1-hover active:bg-accent-1-active focus:outline-none focus:border-accent-1-border-active focus:ring focus:ring-accent-1-light transition">
            Generate & Secure Key
        </button>
    ';
    
    require BASE_PATH . '/src/Views/components/modal.php';
    ?>
    <?php // The mandatory prompt is opened by public/js/layout/pin-setup-prompt.js ?>
    <?php endif; ?>

    <!-- Global Confirmation Modal -->
    <?php
    $modalId = 'global-confirmation-modal';
    $modalTitle = 'Confirm Action';
    $modalSize = 3; // max-w-md
    $hideCloseButton = false;
    $modalContent = '<p id="global-confirmation-message" class="text-sm text-gray-600 dark:text-gray-400"></p>';
    $modalFooter = '
        <button type="button" class="close-modal-btn inline-flex items-center px-4 py-2 bg-gray-200 dark:bg-gray-700 border border-transparent rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-300 dark:hover:bg-accent-2 focus:outline-none focus:ring-2 focus:ring-accent-1 transition" data-modal="global-confirmation-modal" data-modal-initial-focus>Cancel</button>
        <button type="button" id="global-confirm-btn" class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 active:bg-red-700 focus:outline-none focus:border-red-700 focus:ring focus:ring-red-200 transition">Yes, Proceed</button>
    ';
    require BASE_PATH . '/src/Views/components/modal.php';
    ?>
<?php // Behaviour of the confirmation modal: public/js/layout/global-confirm.js; notifications and toasts: public/js/layout/notifications.js ?>
</body>
</html>
