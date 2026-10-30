<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use App\Modules\Integrity\DigitalSignatureService;
use App\Modules\Integrity\HashChainService;
use App\Modules\Integrity\PreActionIntegrityService;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * Guards the 2.4.0 core-module rename: the paper's module names map to real classes, the old
 * classes are gone, and log format v2 stays byte-identical to the values the old IntegrityManager produced.
 */
class CoreModuleStructureTest extends TestCase
{
    // Fixed document used by the golden vectors (non-ASCII and slashes exercise the JSON flags)
    private function goldenDocument(): array
    {
        return [
            'tracking_code' => 'DTS-2026-ÄB/1',
            'title' => 'Payroll / März',
            'guest_info' => json_encode(['name' => 'Juan Dela Cruz', 'email' => 'j@x.ph', 'phone' => '0917']),
            'district' => 'North',
            'department' => 'Cash Unit',
            'purpose_id' => 7,
            'finalized_route' => [['name' => 'Cash Unit'], ['name' => 'Records Unit']],
        ];
    }

    // Old class names must no longer resolve, so no hidden shim exists
    public function test_old_classes_are_removed(): void
    {
        // Each retired class must be unknown to the autoloader
        $retired = [
            'App\Core\IntegrityManager', 'App\Core\SecurityHelper', 'App\Services\RouteSuggestionService', 'App\Middleware\DocumentPolicyMiddleware',
            // 2.6.0 layer-convention rename: Common
            'App\Core\Router', 'App\Core\Database', 'App\Core\Cache', 'App\Core\Config', 'App\Core\Validator', 'App\Core\EventDispatcher',
            'App\Middleware\AuthMiddleware', 'App\Middleware\CacheMiddleware', 'App\Middleware\RoleMiddleware',
            'App\Utils\Paginator', 'App\Utils\CursorPaginator',
            // 2.6.0: Integrity
            'App\Integrity\HashChainMicroShardingBuilder', 'App\Integrity\DigitalSignature', 'App\Integrity\PreActionIntegrityCheck',
            'App\Controllers\IntegrityMonitorController', 'App\Jobs\IntegrityCheckJob', 'App\Jobs\ChainCheckpointJob', 'App\Events\IntegrityCheckFailed',
            // 2.6.0: Routing
            'App\Routing\HeuristicRouter',
            // 2.6.0: Documents
            'App\Controllers\DocumentController', 'App\Controllers\GuestController', 'App\Controllers\IntakeController',
            'App\Controllers\TaskController', 'App\Controllers\ReleasingController', 'App\Controllers\DashboardController',
            'App\Services\DocumentWorkflowService', 'App\Services\DocumentQueryService', 'App\Models\Document',
            'App\Policies\DocumentPolicy', 'App\Core\TrackingCode', 'App\Jobs\CleanupStalePendingDocumentsJob',
            // 2.6.0: Users
            'App\Controllers\UserController', 'App\Controllers\ProfileController', 'App\Controllers\SecurityKeyController',
            'App\Models\User', 'App\Security\SecurityPinCache',
            // 2.6.0: Auth
            'App\Controllers\AuthController', 'App\Core\AttemptThrottle',
            // 2.6.0: Analytics
            'App\Controllers\AdminDashboardController', 'App\Controllers\StatisticsController',
            'App\Services\AdminAnalyticsService', 'App\Services\DepartmentAnalyticsService', 'App\Jobs\GenerateReportJob',
            // 2.6.0: System
            'App\Controllers\SystemHealthController', 'App\Controllers\BackupManagerController', 'App\Services\DatabasePerformanceService',
            'App\Jobs\CreateBackupJob', 'App\Jobs\RestoreBackupJob',
            // 2.6.0: Notifications
            'App\Controllers\NotificationController', 'App\Core\NotificationService',
        ];
        foreach ($retired as $old) {
            $this->assertFalse(class_exists($old), "{$old} should have been removed");
        }
    }

    // Every new module exposes the methods the spec moves into it
    public function test_new_modules_expose_moved_methods(): void
    {
        $expected = [
            HashChainService::class => ['calculateStateHash', 'logTimestamp', 'signedMessage', 'chainHashInput', 'chainHash', 'createLog'],
            DigitalSignatureService::class => ['signAsSystem', 'decryptPrivateKey', 'signAction', 'publicKeyAt', 'verifySignature', 'clearKeyCache'],
            PreActionIntegrityService::class => ['passes', 'freezeTampered'],
            'App\Modules\Users\SecurityPinCacheService' => ['resolvePin'],
            'App\Modules\Routing\HeuristicRoutingService' => ['suggest', 'learnedRoute'],
            // 2.6.0 layer-convention rename: Common
            'App\Common\Http\Router' => ['add', 'get', 'post', 'dispatch'],
            'App\Common\Database\Database' => [],
            'App\Common\Cache\Cache' => [],
            'App\Common\Config\Config' => [],
            'App\Common\Validation\Validator' => [],
            'App\Common\Events\EventDispatcher' => [],
            'App\Common\Middleware\AuthMiddleware' => [],
            'App\Common\Middleware\CacheMiddleware' => [],
            'App\Common\Guards\RoleGuard' => [],
            'App\Common\Utils\Paginator' => [],
            'App\Common\Utils\CursorPaginator' => [],
            // 2.6.0: Integrity
            'App\Modules\Integrity\IntegrityMonitorController' => [],
            'App\Modules\Integrity\Jobs\IntegrityCheckJob' => [],
            'App\Modules\Integrity\Jobs\ChainCheckpointJob' => [],
            'App\Modules\Integrity\Events\IntegrityCheckFailedEvent' => [],
            // 2.6.0: Documents
            'App\Modules\Documents\DocumentController' => [],
            'App\Modules\Documents\GuestController' => [],
            'App\Modules\Documents\IntakeController' => [],
            'App\Modules\Documents\TaskController' => [],
            'App\Modules\Documents\ReleasingController' => [],
            'App\Modules\Documents\DashboardController' => [],
            'App\Modules\Documents\DocumentWorkflowService' => ['submitDocument', 'finalizeIntake', 'scanDocument', 'completeTask'],
            'App\Modules\Documents\DocumentQueryService' => [],
            'App\Modules\Documents\DocumentModel' => ['findById'],
            'App\Modules\Documents\DocumentPolicy' => ['view', 'process', 'manage', 'freeze', 'unfreeze'],
            'App\Modules\Documents\TrackingCodeUtil' => ['generate'],
            'App\Modules\Documents\Jobs\CleanupStalePendingDocumentsJob' => [],
            // 2.6.0: Users
            'App\Modules\Users\UserController' => [],
            'App\Modules\Users\ProfileController' => [],
            'App\Modules\Users\SecurityKeyController' => [],
            'App\Modules\Users\UserModel' => ['findById'],
            // 2.6.0: Auth
            'App\Modules\Auth\AuthController' => [],
            'App\Modules\Auth\AttemptThrottleService' => ['key', 'secondsLocked', 'recordFailure', 'clear'],
            // 2.6.0: Analytics
            'App\Modules\Analytics\AdminDashboardController' => [],
            'App\Modules\Analytics\StatisticsController' => [],
            'App\Modules\Analytics\AdminAnalyticsService' => [],
            'App\Modules\Analytics\DepartmentAnalyticsService' => [],
            'App\Modules\Analytics\Jobs\GenerateReportJob' => [],
            // 2.6.0: System
            'App\Modules\System\SystemHealthController' => [],
            'App\Modules\System\BackupManagerController' => [],
            'App\Modules\System\DatabasePerformanceService' => [],
            'App\Modules\System\Jobs\CreateBackupJob' => [],
            'App\Modules\System\Jobs\RestoreBackupJob' => [],
            // 2.6.0: Notifications
            'App\Modules\Notifications\NotificationController' => [],
            'App\Modules\Notifications\NotificationService' => [],
        ];

        // Check each class and each of its methods
        foreach ($expected as $class => $methods) {
            $this->assertTrue(class_exists($class), "{$class} is missing");
            foreach ($methods as $method) {
                $this->assertTrue(method_exists($class, $method), "{$class}::{$method} is missing");
            }
        }

        // No two paths under src/ may differ only by letter case (they collide on Windows and macOS checkouts)
        $seen = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BASE_PATH . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = substr($file->getPathname(), strlen(BASE_PATH) + 1);
            $key = strtolower($path);
            $other = $seen[$key] ?? '';
            $this->assertArrayNotHasKey($key, $seen, "{$path} collides with {$other} on a case-insensitive filesystem");
            $seen[$key] = $path;
        }
        $this->assertFileExists(BASE_PATH . '/src/Common/Config/app.config.php');

        // Public constants keep their values
        $this->assertSame('Asia/Manila', HashChainService::APP_TIMEZONE);
        $this->assertSame('genesis_hash', HashChainService::GENESIS_HASH);
        $this->assertSame('pt1.', \App\Modules\Users\SecurityPinCacheService::TOKEN_PREFIX);
    }

    // Golden vectors computed with the pre-rename IntegrityManager: log format v2 must not change
    public function test_log_format_v2_is_byte_identical(): void
    {
        $stateHash = HashChainService::calculateStateHash($this->goldenDocument());
        $this->assertSame('be5ffcca318b6a33b12b8858118f5955dd094389e47b165fd7470579c00c4204', $stateHash);

        $epoch = HashChainService::logTimestamp('2026-03-01 08:15:30');
        $this->assertSame(1772324130, $epoch);

        $this->assertSame(
            '[42,"ADMIN: Document frozen.",1772324130,"genesis_hash","' . $stateHash . '","Remarks / ñ \"q\""]',
            HashChainService::signedMessage(42, 'ADMIN: Document frozen.', $epoch, 'genesis_hash', $stateHash, 'Remarks / ñ "q"')
        );
        $this->assertSame(
            '1d3a78bbc2a31d9e492201e9e1498510cbe61314a71a08a3c6f0059ee021667a',
            HashChainService::chainHash(42, null, 'System Auto-Freeze', $epoch, 'genesis_hash', $stateHash, 'Remarks / ñ', 'c2ln')
        );
        $this->assertSame(
            'e1aac74e35ebf7e4227550ed4ccf061ec16612171fad26f49e12d4612a370650',
            HashChainService::chainHash(42, 5, 'Submitted', $epoch, 'abc', $stateHash, 'r', 'c2ln')
        );
    }

    // A tampered row fails the pre-action check, and freezing writes a signed log with the new remark wording
    public function test_tampered_document_is_frozen_with_new_remark(): void
    {
        $db = Database::getInstance();
        $s = Fixtures::submit();
        $db->query('UPDATE documents SET title = :t WHERE id = :id', [':t' => 'Tampered', ':id' => $s['id']]);
        $doc = $db->query('SELECT * FROM documents WHERE id = :id', [':id' => $s['id']])->fetch();

        // Detect the mismatch and freeze
        $this->assertFalse(PreActionIntegrityService::passes($doc));
        PreActionIntegrityService::freezeTampered($doc, 'Pre-Action Verification');

        // The stored action string is unchanged; only the remark wording is new
        $log = $db->query('SELECT * FROM document_logs WHERE document_id = :id ORDER BY id DESC LIMIT 1', [':id' => $s['id']])->fetch();
        $this->assertSame('System Auto-Freeze', $log['action']);
        $this->assertSame('Document automatically frozen by the pre-action integrity check due to an integrity mismatch detected during Pre-Action Verification.', $log['remarks']);
        $this->assertSame('frozen', $db->query('SELECT status FROM documents WHERE id = :id', [':id' => $s['id']])->fetch()['status']);

        // The freeze entry verifies against the system key
        DigitalSignatureService::clearKeyCache();
        $message = HashChainService::signedMessage((int) $s['id'], $log['action'], HashChainService::logTimestamp($log['created_at']), $log['previous_hash'], $log['document_state_hash'], $log['remarks']);
        $this->assertTrue(DigitalSignatureService::verifySignature(null, $log['created_at'], $message, $log['signature'])['valid']);
    }
}
