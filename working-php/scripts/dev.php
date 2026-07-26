<?php

function executeCommand(string $command): void {
    passthru($command, $status);
    if ($status !== 0) {
        throw new RuntimeException("Command failed with exit code $status: $command");
    }
}

try {
    $os = PHP_OS_FAMILY;
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

        echo "▶️  Restarting $fpmService to flush OPcache...\n";
        executeCommand("sudo service $fpmService restart");

        echo "▶️  Ensuring NGINX is running...\n";
        // Using restart so NGINX picks up the new PHP-FPM socket
        executeCommand("sudo service nginx restart");

        echo "⚙️  Starting Async Queue Worker (console.php)...\n";
        $consolePath = realpath(__DIR__ . '/../console.php');
        
        // Find and kill any existing console.php to avoid duplicates
        passthru("pkill -f 'php $consolePath' > /dev/null 2>&1");
        
        // Run console.php in the background and redirect output to a log file
        $logFile = realpath(__DIR__ . '/../storage/logs') . '/worker.log';
        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0777, true);
        }
        exec("nohup php " . escapeshellarg($consolePath) . " > " . escapeshellarg($logFile) . " 2>&1 &");

        echo "\n✅ Development Server is now powered by NGINX & PHP-FPM on port $port!\n";
        echo "📋 Watching access logs...\n";
        
        executeCommand("tail -f /var/log/nginx/dts_access.log");
    }
} catch (Throwable $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
