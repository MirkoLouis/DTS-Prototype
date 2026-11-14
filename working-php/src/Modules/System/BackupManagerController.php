<?php

namespace App\Modules\System;

use App\Common\Database\Database;

class BackupManagerController
{
    public function index()
    {
        $backupDir = BackupService::directory();
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0777, true);
        }

        $files = array_diff(scandir($backupDir), ['.', '..']);
        $backups = [];

        $search = $_GET['search'] ?? null;

        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'zip') {
                $filePath = $backupDir . '/' . $file;
                $lastModified = date('M d, Y, h:i A', filemtime($filePath));
                
                if ($search && !str_contains(strtolower($file), strtolower($search)) && !str_contains(strtolower($lastModified), strtolower($search))) {
                    continue;
                }

                $backups[] = [
                    'file_path' => $filePath,
                    'file_name' => $file,
                    'file_size' => $this->humanReadableSize(filesize($filePath)),
                    'last_modified_raw' => filemtime($filePath),
                    'last_modified' => $lastModified,
                ];
            }
        }

        usort($backups, function ($a, $b) {
            return $b['last_modified_raw'] <=> $a['last_modified_raw'];
        });

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode($backups);
            exit;
        }

        // Tell the admin when new backups would be written unencrypted
        $encryptionWarning = BackupService::encryptionWarning((string) \App\Common\Config\Config::get('app', 'backup_password', ''));

        require BASE_PATH . '/src/Views/admin/backups.php';
    }

    public function create()
    {
        $db = Database::getInstance();
        $payload = json_encode(['class' => \App\Modules\System\Jobs\CreateBackupJob::class, 'data' => []]);
        $db->query("INSERT INTO jobs (queue, payload, attempts, available_at, created_at) VALUES ('default', :payload, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())", [
            'payload' => $payload
        ]);

        SystemAuditService::record('backup_requested', ['user_id' => (int) $_SESSION['user_id']]);
        $_SESSION['success'] = 'Backup has been queued and will be created in the background.';
        header('Location: /system/backups');
        exit;
    }

    public function download($fileName)
    {
        $safeFileName = basename($fileName);
        $filePath = BackupService::directory() . '/' . $safeFileName;

        if (file_exists($filePath)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="'.basename($filePath).'"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        }

        header('Location: /system/backups');
        exit;
    }

    public function delete($fileName)
    {
        $safeFileName = basename($fileName);

        // Deleting a backup can destroy the only copy of past data, so the admin proves who they are
        if (!$this->confirmedWithPin('Delete backup', $safeFileName)) {
            header('Location: /system/backups');
            exit;
        }

        unlink(BackupService::directory() . '/' . $safeFileName);
        SystemAuditService::record('backup_deleted', ['file' => $safeFileName, 'user_id' => (int) $_SESSION['user_id']]);
        $_SESSION['success'] = "Backup {$safeFileName} deleted.";

        header('Location: /system/backups');
        exit;
    }

    public function restore($fileName)
    {
        $safeFileName = basename($fileName);

        // A restore replaces the whole database, so the admin proves who they are
        if (!$this->confirmedWithPin('Restore backup', $safeFileName)) {
            header('Location: /system/backups');
            exit;
        }

        // The job knows who asked, so it can notify them when the restore has finished
        $userId = (int) $_SESSION['user_id'];
        SystemAuditService::record('backup_restore_requested', ['file' => $safeFileName, 'user_id' => $userId]);
        $payload = json_encode(['class' => \App\Modules\System\Jobs\RestoreBackupJob::class, 'data' => [$safeFileName, $userId]]);
        Database::getInstance()->query("INSERT INTO jobs (queue, payload, attempts, available_at, created_at) VALUES ('default', :payload, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())", [
            'payload' => $payload
        ]);

        $_SESSION['success'] = 'Restore job has been queued. The current database is backed up first, and you will be notified when the restore finishes.';
        header('Location: /system/backups');
        exit;
    }

    /**
     * Check that the backup exists and that the admin entered their Security PIN.
     *
     * The PIN is proved the same way as for any signed action: it must unlock the admin's signing key.
     * Wrong PINs count toward the PIN lockout. On refusal a flash error is set and false is returned.
     */
    private function confirmedWithPin(string $action, string $safeFileName): bool
    {
        // Nothing to act on
        if ($safeFileName === '' || !is_file(BackupService::directory() . '/' . $safeFileName)) {
            $_SESSION['error'] = 'That backup file does not exist.';
            return false;
        }

        // No PIN, or a cached PIN token that is no longer valid
        $pin = \App\Modules\Users\SecurityPinCacheService::resolvePin($_POST['pin'] ?? '');
        if (empty($pin)) {
            $_SESSION['error'] = 'Security PIN is required.';
            return false;
        }

        // Signing fails on a wrong PIN and while the admin is locked out
        try {
            \App\Modules\Integrity\DigitalSignatureService::signAction(
                (int) $_SESSION['user_id'],
                $pin,
                "ADMIN: {$action}|" . hash('sha256', $safeFileName)
            );
        } catch (\Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            return false;
        }

        return true;
    }

    private function humanReadableSize($bytes, $decimals = 2) {
        $size = array('B','kB','MB','GB','TB','PB','EB','ZB','YB');
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$size[$factor];
    }
}
