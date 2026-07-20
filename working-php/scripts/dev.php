<?php

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
    // Windows users fall back to the built-in PHP server which is cross-platform
    passthru("php -S 0.0.0.0:$port -t public");
} else {
    echo "🚀 [Linux Detected] Starting NGINX & PHP-FPM on demand...\n";
    
    // Detect installed PHP version to target the correct FPM service
    $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $fpmService = "php{$phpVersion}-fpm";

    echo "▶️  Restarting $fpmService to flush OPcache...\n";
    passthru("sudo service $fpmService restart");

    echo "▶️  Ensuring NGINX is running...\n";
    passthru("sudo service nginx start");

    echo "\n✅ Development Server is now powered by NGINX & PHP-FPM on port $port!\n";
    echo "📋 Watching access logs...\n";
    
    passthru("tail -f /var/log/nginx/dts_access.log");
}
