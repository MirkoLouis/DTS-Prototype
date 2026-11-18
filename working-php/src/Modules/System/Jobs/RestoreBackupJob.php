<?php

namespace App\Modules\System\Jobs;

use App\Common\Config\Config;
use App\Common\Database\Database;
use App\Common\Database\MysqlCli;
use App\Common\Database\SqlScriptRunner;
use App\Modules\Notifications\NotificationService;
use App\Modules\System\BackupService;
use App\Modules\Integrity\ChainSealService;
use App\Modules\System\SystemAuditService;

class RestoreBackupJob
{
    /**
     * @param string   $fileName name of the backup zip in the backup folder
     * @param int|null $userId   the admin who asked for the restore; they are notified when it finishes
     */
    public function __construct(protected string $fileName, protected ?int $userId = null)
    {
    }

    /**
     * Replace the database with a backup.
     *
     * The current database is saved first, so a restore can itself be undone. Every outcome goes to
     * the system audit log, because the database tables that could hold a record are the ones being replaced.
     */
    public function handle(): void
    {
        ini_set('memory_limit', '2G');
        set_time_limit(BackupService::JOB_TIME_LIMIT_SECONDS);

        // A job that was killed cannot clean up after itself, so the next one clears it once no running job holds it
        BackupService::clearAbandonedWorkingDirectories(time());

        $zipPath = BackupService::directory() . '/' . $this->fileName;

        // Nothing to restore from
        if (!file_exists($zipPath)) {
            $this->recordFailure('file not found');
            return;
        }

        // A restore replaces every table, so first save what is about to be overwritten
        try {
            $safetyBackup = $this->safetyBackup();
        } catch (\Throwable $e) {
            $this->recordFailure($e->getMessage());
            throw $e;
        }

        $before = $this->counts();
        try {
            $notices = $this->import($zipPath);
        } catch (\Throwable $e) {
            $this->recordFailure($e->getMessage(), $safetyBackup);
            $this->notify('Backup restore failed', "Restoring {$this->fileName} failed: {$e->getMessage()} The state before the restore was saved as {$safetyBackup}.", 'error');
            throw $e;
        }
        $after = $this->counts();

        // Messages from the migration (for example duplicate department names) go to the error log too
        foreach ($notices as $notice) {
            error_log("Restore of {$this->fileName}: migration notice: {$notice}");
        }

        // A backup from before the chain seal restores documents without seals; their actions are refused until the sealing step is run
        $unsealed = ChainSealService::countUnsealedDocuments();
        // On a database recorded as sealed, a missing seal counts as tampering: sealing the restored data needs the reviewed-exception flag
        $sealedDatabase = $unsealed > 0 && ChainSealService::databaseIsSealed();
        $sealCommand = $sealedDatabase
            ? 'php scripts/seal-chains.php --seal-missing-on-sealed-database --database=' . (string) Config::get('database', 'dbname', '')
            : 'php scripts/seal-chains.php';
        SystemAuditService::record('backup_restored', [
            'file' => $this->fileName,
            'user_id' => $this->userId,
            'safety_backup' => $safetyBackup,
            'documents_before' => $before['documents'],
            'documents_after' => $after['documents'],
            'logs_before' => $before['logs'],
            'logs_after' => $after['logs'],
        ] + ($notices !== [] ? ['migration_notices' => $notices] : []) + ($unsealed > 0 ? ['unsealed_documents' => $unsealed, 'sealing_required' => true, 'seal_command' => $sealCommand] : []));

        $lost = max(0, $before['documents'] - $after['documents']);
        $this->notify(
            'Backup restored',
            "Restored {$this->fileName}. Documents: {$before['documents']} before, {$after['documents']} after"
                . ($lost > 0 ? " ({$lost} newer document(s) are no longer in the database)" : '')
                . ". The previous state was saved as {$safetyBackup}."
                . ($notices !== [] ? ' Migration notice: ' . implode(' ', $notices) : '')
                . ($unsealed > 0 ? " {$unsealed} restored document(s) are not sealed: actions on them are refused until an administrator runs the sealing step (run the Integrity Check first, then {$sealCommand})." : ''),
            $lost > 0 || $unsealed > 0 || $notices !== [] ? 'warning' : 'success'
        );
    }

    /**
     * Back up the current database before it is replaced, and return the backup's file name.
     * Tests override this to make the safety backup fail.
     */
    protected function safetyBackup(): string
    {
        // No expiry here: it could delete the very backup this job is about to restore
        return (new CreateBackupJob('pre-restore', false))->handle();
    }

    /**
     * Load the backup's dump into the database, then bring the schema up to date.
     *
     * @return string[] the notices the migration raised
     */
    private function import(string $zipPath): array
    {
        // Take the dump out of the archive into a working folder, feed it to the mysql client, and never leave the plain SQL file behind
        $workingDir = BackupService::workingDirectory();
        try {
            $filePath = BackupService::extract($zipPath, $workingDir, (string) Config::get('app', 'backup_password', ''));
            MysqlCli::run('mysql', [], $filePath);
        } finally {
            BackupService::removeWorkingDirectory($workingDir);
        }

        // A backup taken before a schema change restores the old tables; bring them back up to date
        return SqlScriptRunner::runFile(Database::getInstance()->getConnection(), BASE_PATH . '/scripts/migrations/master-migration.sql');
    }

    /**
     * Number of documents and log entries currently in the database.
     *
     * @return array{documents: int, logs: int}
     */
    private function counts(): array
    {
        $db = Database::getInstance();

        return [
            'documents' => (int) $db->query("SELECT COUNT(*) AS c FROM documents")->fetch()['c'],
            'logs' => (int) $db->query("SELECT COUNT(*) AS c FROM document_logs")->fetch()['c'],
        ];
    }

    /**
     * Write a failed restore to the system audit log.
     */
    private function recordFailure(string $reason, ?string $safetyBackup = null): void
    {
        SystemAuditService::record('backup_restore_failed', [
            'file' => $this->fileName,
            'user_id' => $this->userId,
            'safety_backup' => $safetyBackup,
            'reason' => $reason,
        ]);
    }

    /**
     * Tell the admin who asked for the restore how it ended.
     *
     * A notification that cannot be stored never changes the outcome: the restore has already
     * succeeded or failed and is on record in the audit log. This happens when the restored
     * backup predates the admin's own account.
     */
    private function notify(string $title, string $message, string $type): void
    {
        // A restore started from the command line has nobody to notify
        if ($this->userId === null) {
            return;
        }

        try {
            (new NotificationService())->notifyUser($this->userId, $title, $message, $type);
        } catch (\Throwable $e) {
            error_log("Restore of {$this->fileName}: could not notify user {$this->userId}: " . $e->getMessage());
        }
    }
}
