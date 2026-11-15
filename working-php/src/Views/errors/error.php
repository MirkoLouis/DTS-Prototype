<?php
// Inputs: $status (int), $title, $sentence, $homePath, $homeLabel, $showReloadHint (all prepared by ErrorPage::render).
// Standalone page: no layout, database or session data is used, and every dynamic value is escaped.
$escape = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($status . ' ' . $title) ?> — DepEd DTS</title>
    <link rel="icon" type="image/png" href="/images/logoipsum-411.png">
    <link href="/css/tailwind.css" rel="stylesheet">
    <script>
        // Same theme choice as the rest of the app (light, dark, or the device setting)
        (function () {
            let theme = null;
            try { theme = localStorage.getItem('theme'); } catch (e) {}
            if (theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
</head>
<body class="min-h-screen flex items-center justify-center bg-gray-100 dark:bg-gray-900 px-4">
    <main class="max-w-md w-full mx-auto bg-white dark:bg-gray-800 shadow-md rounded-lg p-8 text-center">
        <p class="text-5xl font-bold text-accent-1"><?= $escape($status) ?></p>
        <h1 class="mt-4 text-2xl font-semibold text-gray-800 dark:text-gray-100"><?= $escape($title) ?></h1>
        <p class="mt-4 text-sm text-gray-600 dark:text-gray-400"><?= $escape($sentence) ?></p>
        <?php if ($showReloadHint): ?>
            <p class="mt-2 text-sm text-gray-500">Reload the page (press F5) to get a fresh one.</p>
        <?php endif; ?>
        <a href="<?= $escape($homePath) ?>" class="mt-6 inline-flex items-center px-4 py-2 bg-accent-1 hover:bg-accent-1-hover text-white rounded-md font-semibold text-xs uppercase tracking-widest"><?= $escape($homeLabel) ?></a>
    </main>
</body>
</html>
