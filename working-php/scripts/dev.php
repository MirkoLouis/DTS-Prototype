<?php

require_once __DIR__ . '/server-lib.php';

// Enable asynchronous signal handling
pcntl_async_signals(true);

// Populated once the Linux branch detects the PHP version
$GLOBALS['fpmService'] = 'php-fpm';

// Worker script path, used by the watchdog and the shutdown handler
$GLOBALS['consolePath'] = realpath(__DIR__ . '/../console.php');

// Catch Ctrl+C and kill the services before exiting
pcntl_signal(SIGINT, function() {
    $fpmService = $GLOBALS['fpmService'];
    echo "\n🛑 Shutting down development server...\n";
    stopWorker($GLOBALS['consolePath']);
    shell_exec("sudo service nginx stop");
    shell_exec("sudo service $fpmService stop");
    shell_exec('pkill -f nginx'); // Catch any escapees
    shell_exec('pkill -f php-fpm');
    echo "✅ NGINX & PHP-FPM stopped cleanly. Goodbye!\n";
    exit(0);
});

try {
    $os   = PHP_OS_FAMILY;
    $port = 8000;

    echo "🧹 Clearing application cache...\n";
    $cacheDir = __DIR__ . '/../cache';
    if (is_dir($cacheDir)) {
        $files = glob($cacheDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        echo "✅ Local cache cleared.\n\n";
    }

    if ($os === 'Windows') {
        echo "🚀 [Windows Detected] Starting PHP Built-in Development Server on port $port...\n";
        executeCommand("php -S 0.0.0.0:$port -t public");
    } else {
        echo "🚀 [Linux Detected] Starting NGINX & PHP-FPM on demand...\n";

        // Detect installed PHP version to target the correct FPM service
        $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $fpmService = "php{$phpVersion}-fpm";
        $GLOBALS['fpmService'] = $fpmService; // Make available to SIGINT handler

        // Detect current LAN IP so the SSL cert and NGINX config cover it
        $lanIp = detectLanIp();
        echo "🌐 Detected LAN IP: $lanIp\n";

        echo "▶️  Restarting $fpmService to flush OPcache...\n";
        executeCommand("sudo service $fpmService restart");

        // Regenerate SSL cert if LAN IP has changed (or cert doesn't exist yet)
        ensureSslCert($lanIp);

        // Install the project NGINX config (substitutes placeholders)
        installNginxConfig(realpath(__DIR__ . '/..'), $phpVersion, $lanIp);

        echo "▶️  Ensuring NGINX is running...\n";
        // Using restart so NGINX picks up the new PHP-FPM socket and updated cert
        executeCommand("sudo service nginx restart");

        echo "⚙️  Starting Async Queue Worker (console.php)...\n";
        $consolePath = $GLOBALS['consolePath'];

        // Stop any existing worker (found by path, also after its re-exec) to avoid duplicates
        stopWorker($consolePath);

        // Run console.php in the background, appending its output to the worker log
        $logDir = __DIR__ . '/../storage/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $logFile = realpath($logDir) . '/worker.log';
        startWorker($consolePath, $logFile);

        echo "\n✅ Development Server is now powered by NGINX & PHP-FPM!\n";
        echo "   Local:   https://localhost:$port\n";
        echo "   Network: https://$lanIp:$port\n";
        echo "   ⚠️  Other devices: open the URL, click Advanced → Accept the risk.\n\n";
        echo "📋 Watching access logs (the worker is restarted automatically if it exits)...\n";

        // Stream the access log in the background
        $tail = proc_open(['tail', '-f', '/var/log/nginx/dts_access.log'], [['pipe', 'r'], STDOUT, STDERR], $tailPipes);

        // Watchdog: relaunch the worker within ~5 s whenever it is no longer running
        while (true) {
            sleep(5);
            if (workerPids($consolePath) === []) {
                echo "⚠️  Queue worker is not running; restarting it (see storage/logs/worker.log)...\n";
                startWorker($consolePath, $logFile);
            }
        }
    }
} catch (Throwable $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
