<?php

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\Database;
use App\Core\IntegrityManager;

// Load config or bootstrap needed stuff
$docsToCreate = isset($argv[1]) ? (int)$argv[1] : 10000;
$chunkSize = 2500; // Safe for MySQL max_allowed_packet to prevent locking

echo "🚀 Starting High-Speed Direct Database Seeder (Hybrid)...\n";
echo "Target: " . number_format($docsToCreate) . " documents.\n";

$db = Database::getInstance();
$conn = $db->getConnection();

// --- Truncate ---
echo "🧹 Cleaning tables...\n";
$conn->exec('SET FOREIGN_KEY_CHECKS = 0');
$conn->exec('TRUNCATE TABLE document_logs');
$conn->exec('TRUNCATE TABLE documents');
$conn->exec('TRUNCATE TABLE database_metrics');
$conn->exec('SET FOREIGN_KEY_CHECKS = 1');

// --- Preload Data ---
$departments = $conn->query("SELECT id, name FROM departments")->fetchAll(PDO::FETCH_ASSOC);
$purposes = $conn->query("SELECT id, is_official, suggested_route FROM purposes")->fetchAll(PDO::FETCH_ASSOC);
$users = $conn->query("SELECT id, department_id, role, private_key FROM users WHERE private_key IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);

if (empty($departments) || empty($users)) {
    die("Error: No departments or users found. Run node seed.js or migrations first to create departments and users.\n");
}

$recordsOfficer = null;
foreach ($users as $u) {
    if ($u['role'] === 'officer') {
        $recordsOfficer = $u;
        break;
    }
}
if (!$recordsOfficer) $recordsOfficer = $users[0];

// --- Decrypt Keys in Memory (The Secret to Speed) ---
echo "🔐 Decrypting user private keys into RAM for high-speed signing...\n";
$decryptedKeys = [];
$pin = 'password';

foreach ($users as $user) {
    $decoded = base64_decode($user['private_key']);
    if (strlen($decoded) === 64) {
        $key = substr(hash('sha256', $pin), 0, 32);
        $iv = str_repeat('0', 16);
        $encryptedPriv = $decoded;
    } elseif (strlen($decoded) === 112) {
        $salt = substr($decoded, 0, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $iv = substr($decoded, SODIUM_CRYPTO_PWHASH_SALTBYTES, 16);
        $encryptedPriv = substr($decoded, SODIUM_CRYPTO_PWHASH_SALTBYTES + 16);
        $key = sodium_crypto_pwhash(32, $pin, $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    } else {
        continue;
    }
    $decryptedPriv = openssl_decrypt($encryptedPriv, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($decryptedPriv !== false) {
        $decryptedKeys[$user['id']] = $decryptedPriv;
    }
}
echo "✅ Cached " . count($decryptedKeys) . " private keys.\n";

$districts = ['East I District', 'East II District', 'South I District', 'South II District', 'West I District', 'North I District', 'North II District', 'North III District', 'City Central District'];

// Set timezone to exactly match the web application so date('c') formatting aligns for hash validation
date_default_timezone_set('Asia/Manila');

$totalProcessed = 0;
$startTime = microtime(true);

// Metrics helper
$hourlyMetrics = [];
function addMetric(&$hourlyMetrics, $timestamp, $isPeak) {
    $hourKey = date('Y-m-d H:00:00', $timestamp);
    if (!isset($hourlyMetrics[$hourKey])) {
        $hourlyMetrics[$hourKey] = ['conns' => [], 'avg' => [], 'slow' => 0];
    }
    $isBusiness = (date('H', $timestamp) >= 8 && date('H', $timestamp) < 17 && date('N', $timestamp) < 6);
    $base = $isBusiness ? rand(10, 50) : rand(2, 10);
    $conns = $isPeak ? $base + rand(20, 50) : $base;
    $avg = $isPeak ? rand(50, 200)/10 : rand(5, 50)/10;
    
    $hourlyMetrics[$hourKey]['conns'][] = $conns;
    $hourlyMetrics[$hourKey]['avg'][] = $avg;
    $hourlyMetrics[$hourKey]['slow'] += $isPeak ? rand(0, 3) : 0;
}

function skipWeekend(&$ts, $forward = true) {
    $day = date('N', $ts);
    if ($day >= 6) { 
        if ($forward) {
            $ts = strtotime("next Monday", $ts) + rand(8*3600, 10*3600);
        } else {
            $ts = strtotime("last Friday", $ts) + rand(15*3600, 17*3600);
        }
    }
}

$globalDocIndex = 0;

while ($totalProcessed < $docsToCreate) {
    $currentChunk = min($chunkSize, $docsToCreate - $totalProcessed);
    
    $docValues = [];
    $docParams = [];
    
    $logValues = [];
    $logParams = [];
    
    $docsToInsert = [];
    
    $conn->beginTransaction();
    
    // 1. GENERATE DOCUMENTS IN MEMORY
    for ($i = 0; $i < $currentChunk; $i++) {
        $globalDocIndex++;
        $purpose = $purposes[array_rand($purposes)];
        $district = $districts[array_rand($districts)];
        $dept = $departments[array_rand($departments)];
        
        // Emulate DocumentWorkflowService::submitDocument tracking code logic ('DEPED-' + 10 chars)
        // Ensure absolute uniqueness by embedding the hex index to prevent birthday paradox collisions at 1M docs
        $hexIndex = strtoupper(dechex($globalDocIndex));
        $randomPad = strtoupper(substr(sha1(uniqid('', true)), 0, 10 - strlen($hexIndex)));
        $trackingCode = 'DEPED-' . $randomPad . $hexIndex;
        $guestInfo = json_encode(['name' => "Fast Guest $globalDocIndex", 'email' => "fast$globalDocIndex@test.com", 'phone' => '0912']);
        
        $isRecent = (mt_rand()/mt_getrandmax()) < 0.4;
        $ts = time();
        if ($isRecent) {
            $ts -= rand(0, 30) * 86400;
        } else {
            $ts -= rand(0, 365 * 3) * 86400;
        }
        $ts += rand(8*3600, 16*3600); 
        skipWeekend($ts, false);
        $createdAt = date('Y-m-d H:i:s', $ts);
        
        $routeNames = [];
        if ($purpose['is_official'] && $purpose['suggested_route']) {
            $parsed = json_decode($purpose['suggested_route'], true);
            if (is_array($parsed)) {
                $routeNames = array_map(function($r) { return is_string($r) ? $r : $r['name']; }, $parsed);
            }
        }
        if (empty($routeNames)) {
            $keys = array_rand($departments, rand(2, 4));
            if (!is_array($keys)) $keys = [$keys];
            foreach ($keys as $k) $routeNames[] = $departments[$k]['name'];
        }
        
        $finalizedRoute = json_encode(array_map(function($n) { return ['name' => $n, 'type' => 'initial']; }, $routeNames));
        
        $fate = mt_rand()/mt_getrandmax();
        $status = 'completed';
        $aimForReleased = true;
        if ($fate < 0.1) { $status = 'pending'; $aimForReleased = false; }
        elseif ($fate < 0.15) { $status = 'declined'; $aimForReleased = false; }
        elseif ($fate < 0.35) { $status = 'processing'; $aimForReleased = false; }
        
        $currentDeptId = null;
        $currentStep = 0;
        $releasedAt = null;
        $releasedByUserId = null;
        $declinedAt = null;
        $declineReason = null;
        
        if ($status == 'processing') {
            $randIdx = array_rand($routeNames);
            $deptName = $routeNames[$randIdx];
            foreach ($departments as $dep) { if ($dep['name'] === $deptName) { $currentDeptId = $dep['id']; break; } }
            $currentStep = $randIdx + 1; // 1-based index
        } elseif ($status == 'completed') {
            $currentStep = count($routeNames) + 1;
            // Determine release timestamp (usually near the end of the timeline)
            $tsEnd = $ts; // Will be advanced during log generation
            // Set fields required for Statistics page visibility
            $releasedAt = date('Y-m-d H:i:s', $ts + 3600); // Approximate
            $releasedByUserId = $recordsOfficer['id'];
        } elseif ($status == 'declined') {
            $declinedAt = date('Y-m-d H:i:s', $ts + rand(5, 30)*60);
            $declineReason = 'Requirements not met.';
        }
        
        $docsToInsert[] = [
            'tracking_code' => $trackingCode,
            'title' => "Fast Seeded Doc $globalDocIndex",
            'guest_info' => $guestInfo,
            'district' => $district,
            'department' => $dept['name'],
            'purpose_id' => $purpose['id'],
            'status' => $status,
            'current_department_id' => $currentDeptId,
            'current_step' => $currentStep,
            'released_at' => $releasedAt,
            'released_by_user_id' => $releasedByUserId,
            'declined_at' => $declinedAt,
            'decline_reason' => $declineReason,
            'finalized_route' => $finalizedRoute,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            '_ts' => $ts,
            '_routeNames' => $routeNames,
            '_aimForReleased' => $aimForReleased,
            '_targetProcessingStep' => $status == 'processing' ? $currentStep : null
        ];
    }
    
    // BULK INSERT DOCUMENTS
    $placeholders = implode(',', array_fill(0, count($docsToInsert), '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'));
    $sql = "INSERT INTO documents (tracking_code, title, guest_info, district, department, purpose_id, status, current_department_id, current_step, released_at, released_by_user_id, declined_at, decline_reason, finalized_route, created_at, updated_at) VALUES $placeholders";
    $stmt = $conn->prepare($sql);
    $flatParams = [];
    foreach ($docsToInsert as $d) {
        array_push($flatParams, $d['tracking_code'], $d['title'], $d['guest_info'], $d['district'], $d['department'], $d['purpose_id'], $d['status'], $d['current_department_id'], $d['current_step'], $d['released_at'], $d['released_by_user_id'], $d['declined_at'], $d['decline_reason'], $d['finalized_route'], $d['created_at'], $d['updated_at']);
    }
    $stmt->execute($flatParams);
    
    $firstInsertId = $conn->lastInsertId();
    
    // 2. GENERATE LOGS IN MEMORY WITH REAL CRYPTO
    for ($i = 0; $i < $currentChunk; $i++) {
        $d = $docsToInsert[$i];
        $docId = $firstInsertId + $i;
        $ts = $d['_ts'];
        $routeNames = $d['_routeNames'];
        
        $docDataForHash = [
            'tracking_code' => $d['tracking_code'],
            'title' => $d['title'],
            'guest_info' => $d['guest_info'],
            'district' => $d['district'],
            'department' => $d['department'],
            'purpose_id' => $d['purpose_id'],
            'finalized_route' => $d['finalized_route'] 
        ];
        
        $prevHash = 'genesis_hash';
        
        $addLog = function($action, $remarks, $userId, $isPeak = false) use (&$logValues, &$logParams, &$prevHash, &$ts, $docId, $docDataForHash, &$hourlyMetrics, $decryptedKeys) {
            $stateHash = IntegrityManager::calculateStateHash($docDataForHash);
            $sqlDate = date('Y-m-d H:i:s', $ts);
            // Must strictly emulate IntegrityManager's exact method of deriving the ISO string from the SQL string
            $isoDate = date('c', strtotime($sqlDate));
            
            $signature = base64_encode("SYSTEM_SIG:{$action}|{$stateHash}");
            if ($userId && isset($decryptedKeys[$userId])) {
                $signatureBytes = sodium_crypto_sign_detached($action . '|' . $stateHash, $decryptedKeys[$userId]);
                $signature = base64_encode($signatureBytes);
            }
            
            $uId = $userId !== null ? $userId : '';
            $dataForLogHash = [$docId, $uId, $action, $isoDate, $prevHash, $stateHash, $signature];
            $newHash = hash('sha256', json_encode($dataForLogHash, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            
            $logValues[] = '(?,?,?,?,?,?,?,?,?,?)';
            array_push($logParams, $docId, $userId, $action, $remarks, $prevHash, $newHash, $stateHash, $signature, $sqlDate, $sqlDate);
            
            $prevHash = $newHash;
            addMetric($hourlyMetrics, $ts, $isPeak);
        };
        
        $addLog('Submitted', 'Document submitted by guest via the public portal.', null);
        
        if ($d['status'] === 'pending') continue;
        
        if ($d['status'] === 'declined') {
            $ts += rand(5, 30)*60; skipWeekend($ts);
            $addLog('Declined', 'Requirements not met.', $recordsOfficer['id'], true);
            continue;
        }
        
        $ts += rand(5, 60)*60; skipWeekend($ts);
        $firstDepartmentName = $routeNames[0] ?? 'Unknown';
        $addLog('Accepted and Document Routing finalized', "Route finalized. In transit to {$firstDepartmentName}.", $recordsOfficer['id']);
        
        $steps = $d['_aimForReleased'] ? count($routeNames) : rand(1, count($routeNames));
        
        for ($s = 0; $s < $steps; $s++) {
            $deptName = $routeNames[$s];
            $deptId = null;
            foreach ($departments as $dep) { if ($dep['name'] === $deptName) { $deptId = $dep['id']; break; } }
            
            $stepUser = null;
            foreach ($users as $u) { if ($u['department_id'] == $deptId) { $stepUser = $u; break; } }
            if (!$stepUser) $stepUser = $recordsOfficer;
            
            $ts += rand(10, 180)*60; skipWeekend($ts);
            $addLog('Received', "Received by $deptName.", $stepUser['id']);
            
            if ($d['status'] === 'processing' && $d['_targetProcessingStep'] !== null && $s === ($d['_targetProcessingStep'] - 1)) {
                // We reached the current processing step. Halt generation here so it appears currently at this department.
                break;
            }
            
            $ts += rand(30, 360)*60; skipWeekend($ts);
            if ($s + 1 < count($routeNames)) {
                $nextDeptName = $routeNames[$s + 1];
                $logRemarks = "Step processed by {$deptName}. In transit to {$nextDeptName}.";
            } else {
                $logRemarks = "Step processed by {$deptName}. In transit to Records Unit for releasing.";
            }
            $addLog('Processing Complete', $logRemarks, $stepUser['id']);
        }
        
        if ($d['_aimForReleased'] && $s === count($routeNames)) {
            $ts += rand(10, 60)*60; skipWeekend($ts);
            $addLog('Ready for Releasing', 'All processing steps completed. Document received by Records Unit for final releasing.', $recordsOfficer['id']);
            
            $ts += rand(10, 60)*60; skipWeekend($ts);
            $addLog('Document Released', 'The document has been released to the client.', $recordsOfficer['id']);
        }
    }
    
    // BULK INSERT LOGS
    if (!empty($logValues)) {
        $logChunks = array_chunk($logValues, 2000); 
        $paramChunks = array_chunk($logParams, 2000 * 10);
        
        foreach ($logChunks as $idx => $vChunk) {
            $pChunk = $paramChunks[$idx];
            $sql = "INSERT INTO document_logs (document_id, user_id, action, remarks, previous_hash, hash, document_state_hash, signature, created_at, updated_at) VALUES " . implode(',', $vChunk);
            $stmt = $conn->prepare($sql);
            $stmt->execute($pChunk);
        }
    }
    
    $conn->commit();
    
    $totalProcessed += $currentChunk;
    $mem = round(memory_get_usage() / 1024 / 1024);
    echo "\r   ⏳ Progress: " . number_format($totalProcessed) . " / " . number_format($docsToCreate) . " documents. | 🧠 RAM: {$mem} MB   ";
}

echo "\n📊 Flushing metrics...\n";
$conn->beginTransaction();
$metricValues = [];
$metricParams = [];
foreach ($hourlyMetrics as $hourKey => $data) {
    $c = count($data['conns']) > 0 ? array_sum($data['conns'])/count($data['conns']) : 0;
    $a = count($data['avg']) > 0 ? array_sum($data['avg'])/count($data['avg']) : 0;
    $s = $data['slow'];
    $metricValues[] = '(?,?,?,?)';
    array_push($metricParams, $c, $a, $s, $hourKey);
}
if (!empty($metricValues)) {
    $mChunks = array_chunk($metricValues, 1000);
    $pChunks = array_chunk($metricParams, 4000);
    foreach ($mChunks as $idx => $vChunk) {
        $sql = "INSERT INTO database_metrics (connections, avg_query_time_ms, slow_queries, created_at) VALUES " . implode(',', $vChunk);
        $stmt = $conn->prepare($sql);
        $stmt->execute($pChunks[$idx]);
    }
}
$conn->commit();

echo "\n🔒 Resetting all digital signatures for first-time login...\n";
$conn->exec("UPDATE user_public_key_histories SET deactivated_at = NOW(), updated_at = NOW() WHERE deactivated_at IS NULL");
$conn->exec("UPDATE users SET public_key = NULL, private_key = NULL, security_key_set_at = NULL");

$elapsed = round(microtime(true) - $startTime, 2);
echo "🎉 Done! Seeded " . number_format($docsToCreate) . " documents in {$elapsed}s.\n";
