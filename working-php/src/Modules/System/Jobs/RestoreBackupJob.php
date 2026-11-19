<?php

namespace App\Modules\System\Jobs;

use App\Common\Config\Config;
use App\Common\Database\Database;
use App\Common\Database\MysqlCli;
use App\Common\Database\SqlScriptRunner;
use App\Common\Security\IntegrityKey;
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
        // Restored chain seals on the old version (v1) are upgraded here, by the same code as --upgrade-chain-seals
        $seals = $this->upgradeRestoredChainSeals();
        SystemAuditService::record('backup_restored', [
            'file' => $this->fileName,
            'user_id' => $this->userId,
            'safety_backup' => $safetyBackup,
            'documents_before' => $before['documents'],
            'documents_after' => $after['documents'],
            'logs_before' => $before['logs'],
            'logs_after' => $after['logs'],
        ] + ($notices !== [] ? ['migration_notices' => $notices] : []) + ($unsealed > 0 ? ['unsealed_documents' => $unsealed, 'sealing_required' => true, 'seal_command' => $sealCommand] : [])
            + ($seals !== [] ? ['chain_seals_upgraded' => $seals['upgraded'], 'chain_seals_left_untouched' => $seals['left_untouched']] + ($seals['refused'] !== null ? ['chain_seal_upgrade_refused' => $seals['refused']] : []) : []));

        $lost = max(0, $before['documents'] - $after['documents']);
        $this->notify(
            'Backup restored',
            "Restored {$this->fileName}. Documents: {$before['documents']} before, {$after['documents']} after"
                . ($lost > 0 ? " ({$lost} newer document(s) are no longer in the database)" : '')
                . ". The previous state was saved as {$safetyBackup}."
                . ($notices !== [] ? ' Migration notice: ' . implode(' ', $notices) : '')
                . ($unsealed > 0 ? " {$unsealed} restored document(s) are not sealed: actions on them are refused until an administrator runs the sealing step (run the Integrity Check first, then {$sealCommand})." : '')
                . $this->chainSealUpgradeMessage($seals),
            $lost > 0 || $unsealed > 0 || $this->chainSealUpgradeNeedsReview($seals) || $notices !== [] ? 'warning' : 'success'
        );
    }

    /**
     * Upgrade the restored data's v1 chain seals to v2 (the restore means trusting the backup's content, so this adds no trust).
     *
     * Runs the shared upgrade in restore mode, which is allowed after the upgrade was recorded. Without a key nothing can be
     * verified, so nothing is done. Returns [] when it did not run, otherwise the counts.
     *
     * @return array{upgraded: int, left_untouched: int, refused: ?string}|array{}
     */
    private function upgradeRestoredChainSeals(): array
    {
        // No key, no seal verification: the restore itself must not fail because of it
        if (!IntegrityKey::isConfigured()) {
            return [];
        }
        $stats = ChainSealService::upgradeChainSeals(true);

        // Documents without any seal are the sealing step's business (counted as unsealed above)
        $leftUntouched = count(array_filter($stats['untouched'], fn ($untouched) => $untouched[1] !== ChainSealService::REASON_NO_SEAL));

        return ['upgraded' => $stats['upgraded'], 'left_untouched' => $leftUntouched, 'refused' => $stats['refused']];
    }

    /**
     * Whether the restore-time upgrade left something an administrator must look at.
     */
    private function chainSealUpgradeNeedsReview(array $seals): bool
    {
        return $seals !== [] && ($seals['left_untouched'] > 0 || $seals['refused'] !== null);
    }

    /**
     * The sentence for the notification about the restore-time upgrade of chain seals (empty when there is nothing to say).
     */
    private function chainSealUpgradeMessage(array $seals): string
    {
        if ($seals === []) {
            return '';
        }
        $message = $seals['upgraded'] > 0 ? " {$seals['upgraded']} restored chain seal(s) on the old version were upgraded." : '';
        if ($seals['left_untouched'] > 0) {
            $message .= " {$seals['left_untouched']} restored document(s) have a chain seal that verifies under neither version and were left untouched: run the Integrity Check and investigate.";
        }
        if ($seals['refused'] !== null) {
            $message .= " The chain seals were not upgraded: {$seals['refused']}";
        }

        return $message;
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
