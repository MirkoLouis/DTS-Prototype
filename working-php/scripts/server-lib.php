<?php

/**
 * Shared server setup helpers used by dev.php and prod.php.
 */

/**
 * Refuse to start the server (and its worker) when APP_INTEGRITY_KEY is missing or invalid.
 *
 * Without the key no log entry can be sealed or verified, so nothing that depends on it may run.
 *
 * @throws RuntimeException with the operator-facing message
 */
function assertIntegrityKeyConfigured(): void {
    // Load the application's autoloader and base path the same way console.php does
    if (!defined('BASE_PATH')) {
        define('BASE_PATH', dirname(__DIR__));
    }
    require_once BASE_PATH . '/vendor/autoload.php';

    if (!\App\Common\Security\IntegrityKey::isConfigured()) {
        throw new RuntimeException("Not started. " . \App\Common\Security\IntegrityKey::missingMessage());
    }
}

function executeCommand(string $command): void {
    passthru($command, $status);
    if ($status !== 0) {
        throw new RuntimeException("Command failed with exit code $status: $command");
    }
}

/**
 * Detect the machine's primary LAN IPv4 address (first non-loopback inet address).
 * Falls back to 127.0.0.1 if none is found (e.g. no network connection).
 */
function detectLanIp(): string {
    $output = shell_exec("ip -4 addr show scope global | grep -oP '(?<=inet )[\d.]+'");
    if ($output) {
        $ips = array_filter(array_map('trim', explode("\n", $output)));
        if (!empty($ips)) {
            return reset($ips);
        }
    }
    return '127.0.0.1';
}

/**
 * Generate (or regenerate) the self-signed SSL cert with SANs covering:
 *   - localhost
 *   - 127.0.0.1
 *   - the current LAN IP
 *
 * This ensures browsers on other LAN machines show the "proceed anyway" bypass.
 * The cert is regenerated if the LAN IP has changed since last run.
 */
function ensureSslCert(string $lanIp): void {
    $certPath = '/etc/ssl/certs/dts_prototype.crt';
    $keyPath  = '/etc/ssl/private/dts_prototype.key';

    // Check if the existing cert already covers this LAN IP
    if (file_exists($certPath)) {
        $certInfo = shell_exec("openssl x509 -in $certPath -noout -text 2>/dev/null | grep -A1 'Subject Alternative Name'");
        if ($certInfo && str_contains($certInfo, "IP Address:$lanIp")) {
            echo "✅ SSL cert already valid for $lanIp — skipping regeneration.\n";
            return;
        }
    }

    echo "🔐 Generating SSL cert with SANs for localhost + $lanIp...\n";

    // Build a temporary OpenSSL config with SANs
    $opensslCnf = tempnam(sys_get_temp_dir(), 'dts_openssl_');
    file_put_contents($opensslCnf, <<<CNF
    [req]
    default_bits       = 2048
    prompt             = no
    default_md         = sha256
    distinguished_name = dn
    x509_extensions    = v3_req

    [dn]
    CN = DTS Prototype

    [v3_req]
    subjectAltName = @alt_names
    basicConstraints = CA:FALSE
    keyUsage = digitalSignature, keyEncipherment
    extendedKeyUsage = serverAuth

    [alt_names]
    DNS.1 = localhost
    IP.1  = 127.0.0.1
    IP.2  = $lanIp
    CNF);

    $cmd = "sudo openssl req -x509 -nodes -days 825 -newkey rsa:2048"
         . " -keyout $keyPath"
         . " -out $certPath"
         . " -config " . escapeshellarg($opensslCnf)
         . " 2>&1";

    executeCommand($cmd);
    unlink($opensslCnf);

    // Ensure the ssl-cert group can read the key
    executeCommand("sudo chgrp ssl-cert $keyPath");
    executeCommand("sudo chmod 640 $keyPath");

    echo "✅ SSL cert generated (valid 825 days, covers localhost + $lanIp).\n";
}

/**
 * Read the NGINX site template; a missing, unreadable or empty file is an error (never an empty string).
 */
function readNginxTemplate(string $path): string {
    // file_get_contents() returns false for a missing or unreadable file
    $template = is_file($path) ? @file_get_contents($path) : false;
    if ($template === false) {
        throw new RuntimeException("NGINX template cannot be read: {$path}");
    }
    if (trim($template) === '') {
        throw new RuntimeException("NGINX template is empty: {$path}");
    }

    return $template;
}

/**
 * Fill the placeholders of the NGINX site template (nginx/site.conf) and return the config text.
 *
 * The security header lines are generated from App\Common\Http\SecurityHeaders, the single source of the policy
 * text. A placeholder that is still in the result would be installed as literal text and break NGINX (or silently
 * drop a header), so it is an error with the names of the leftovers.
 */
function renderNginxConfig(string $template, string $projectRoot, string $phpVersion, string $lanIp): string {
    // An empty template would pass every check below and be installed as an empty NGINX config
    if (trim($template) === '') {
        throw new RuntimeException('NGINX template is empty.');
    }

    // SecurityHeaders lives in the application (autoloaded from the project the template belongs to)
    require_once $projectRoot . '/vendor/autoload.php';

    $values = [
        '__PROJECT_ROOT__'       => $projectRoot,
        '__PHP_VERSION__'        => $phpVersion,
        '__LAN_IP__'             => $lanIp,
        '__SECURITY_HEADERS__'   => \App\Common\Http\SecurityHeaders::nginxAddHeaderDirectives(),
        '__HIDDEN_PHP_HEADERS__' => \App\Common\Http\SecurityHeaders::nginxHiddenHeaderDirectives(),
    ];
    $config = str_replace(array_keys($values), array_values($values), $template);

    // A leftover placeholder (a new one nobody fills in, or a typo) must never be installed
    if (preg_match_all('/__[A-Z0-9_]+__/', $config, $leftovers) > 0) {
        throw new RuntimeException('NGINX template has unfilled placeholders: ' . implode(', ', array_unique($leftovers[0])));
    }

    return $config;
}

/**
 * Write the resolved NGINX site config from the project template,
 * substituting dynamic values, and ensure the sites-enabled symlink exists.
 */
function installNginxConfig(string $projectRoot, string $phpVersion, string $lanIp): void {
    $nginxTemplate = $projectRoot . '/nginx/site.conf';
    $nginxTarget   = '/etc/nginx/sites-available/dts-prototype';
    $nginxLink     = '/etc/nginx/sites-enabled/dts-prototype';

    // Fill in every placeholder; throws (before anything is written under /etc) when one is left over
    $nginxConf = renderNginxConfig(readNginxTemplate($nginxTemplate), $projectRoot, $phpVersion, $lanIp);

    // Write via sudo tee — /etc/nginx/sites-available/ is root-owned
    $escapedConf = escapeshellarg($nginxConf);
    executeCommand("echo $escapedConf | sudo tee $nginxTarget > /dev/null");

    if (!file_exists($nginxLink)) {
        executeCommand("sudo ln -s $nginxTarget $nginxLink");
    }

    echo "✅ NGINX site config installed from project (LAN IP: $lanIp).\n";
}

/**
 * PIDs of running worker processes for the given console.php path.
 *
 * Matches on the script path only, so a worker is found both as "php <path>" and after its planned
 * re-exec as "<PHP_BINARY> <path>". pgrep runs without a shell, whose own command line would match too.
 *
 * @return list<int>
 */
function workerPids(string $consolePath): array {
    // Escape regex metacharacters in the path (pgrep -f takes an extended regex)
    $pattern = preg_replace('/[.\[\](){}*+?|^$\\\\]/', '\\\\$0', $consolePath) . '( |$)';

    // Run pgrep directly and collect one PID per line
    $proc = proc_open(['pgrep', '-f', '--', $pattern], [['pipe', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($proc)) {
        return [];
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($proc);

    // Never count this process itself
    $pids = array_map('intval', preg_split('/\s+/', trim((string) $output), -1, PREG_SPLIT_NO_EMPTY));
    return array_values(array_filter($pids, fn($pid) => $pid !== getmypid()));
}

/**
 * Start the worker in the background, appending its output to $logFile.
 *
 * Append mode (>>) lets logrotate's copytruncate empty the file without leaving a sparse gap.
 */
function startWorker(string $consolePath, string $logFile): void {
    // Detach from this process; output goes to the worker log
    exec('nohup php ' . escapeshellarg($consolePath) . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &');
}

/**
 * Stop every running worker for the given console.php path (SIGTERM lets it finish the current job).
 */
function stopWorker(string $consolePath): void {
    // Signal each worker found by path
    foreach (workerPids($consolePath) as $pid) {
        posix_kill($pid, SIGTERM);
    }
}
