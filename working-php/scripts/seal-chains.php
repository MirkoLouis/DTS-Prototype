<?php

/**
 * Seal existing data once: entry seals for log entries without a seal and chain seals for documents without one.
 *
 * Usage:
 *   php scripts/seal-chains.php [--database=<name>]
 *   php scripts/seal-chains.php --seal-documents=<tracking code>[,<tracking code>...] --database=<name>
 *   php scripts/seal-chains.php --seal-missing-on-sealed-database --database=<name>
 *   php scripts/seal-chains.php --overwrite-existing-seals --database=<name>
 *
 * Sealing existing data trusts what the database holds, so it VERIFIES first: a document is sealed only after its whole
 * chain verifies (every block hash, link from genesis, signature against the signer's key valid at that time, snapshot
 * against state hash, and the live row against the newest state hash). Documents that do not verify are refused and
 * listed with the reason (exit code 2). Run the full integrity check first as well (see the README, "Chain Seal").
 *
 * Safety:
 *  - Refuses to run without a valid APP_INTEGRITY_KEY.
 *  - --database=<name> must equal the database configured in .env, or the script refuses (it never connects elsewhere).
 *  - Never changes an existing seal. Once any document has a chain seal the database counts as sealed: a document with a
 *    missing chain seal or entry seal is then NOT sealed but listed as "seal missing on a sealed database: review"
 *    (exit code 2), because someone may have removed the seal. A restored backup from before the chain seal has no seals
 *    at all, so it is sealed normally.
 *  - Reviewed exceptions: --seal-documents=<codes> seals only the named documents, --seal-missing-on-sealed-database seals
 *    every document with a missing seal. Both still verify the chain, need --database=<name>, print a warning and are
 *    recorded in the system audit log.
 *  - --overwrite-existing-seals recomputes the seals of documents whose chain verifies (use it after a key rotation). It needs
 *    --database=<name> and is recorded in the system audit log.
 * Idempotent: a second run changes nothing.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/vendor/autoload.php';
date_default_timezone_set(\App\Modules\Integrity\HashChainService::APP_TIMEZONE);

use App\Common\Config\Config;
use App\Common\Database\Database;
use App\Common\Security\IntegrityKey;
use App\Modules\Integrity\ChainSealService;
use App\Modules\System\SystemAuditService;

/**
 * Print a message to standard error and return the exit code.
 */
function sealChainsRefuse(string $message, int $exitCode = 1): int
{
    fwrite(STDERR, "REFUSED: {$message}\n");

    return $exitCode;
}

/**
 * The value of a --name=value argument, or null when it is absent.
 */
function sealChainsOption(array $arguments, string $name): ?string
{
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, "--{$name}=")) {
            return substr($argument, strlen("--{$name}="));
        }
    }

    return null;
}

/**
 * Ids of the documents with the given tracking codes, or null when a code does not exist.
 */
function sealChainsDocumentIds(array $trackingCodes): ?array
{
    $ids = [];
    foreach ($trackingCodes as $code) {
        $row = Database::getInstance()->query('SELECT id FROM documents WHERE tracking_code = :code', [':code' => $code])->fetch();
        if (!$row) {
            return null;
        }
        $ids[] = (int) $row['id'];
    }

    return $ids;
}

/**
 * Run the sealing script for the given arguments and return its exit code.
 */
function sealChainsMain(array $arguments): int
{
    $overwrite = in_array('--overwrite-existing-seals', $arguments, true);
    $sealMissing = in_array('--seal-missing-on-sealed-database', $arguments, true);
    $selectedCodes = sealChainsOption($arguments, 'seal-documents');
    $selectedCodes = $selectedCodes === null ? null : array_values(array_filter(array_map('trim', explode(',', $selectedCodes)), 'strlen'));

    // The named database, when given, must be the one this installation is configured for
    $namedDatabase = sealChainsOption($arguments, 'database');
    $configuredDatabase = (string) Config::get('database', 'dbname', '');
    if ($namedDatabase !== null && $namedDatabase !== $configuredDatabase) {
        return sealChainsRefuse("--database={$namedDatabase} does not match the database configured in .env ({$configuredDatabase}). Nothing was changed.");
    }

    // Every flag that goes beyond the default rules must name the database explicitly
    $exceptionMode = $overwrite || $sealMissing || $selectedCodes !== null;
    if ($exceptionMode && $namedDatabase === null) {
        return sealChainsRefuse(
            "--overwrite-existing-seals, --seal-missing-on-sealed-database and --seal-documents accept data the default rules refuse to seal, or recompute seals, "
            . "so they must be confirmed by naming the database: add --database={$configuredDatabase}. Nothing was changed."
        );
    }

    // No key, no seal
    if (!IntegrityKey::isConfigured()) {
        return sealChainsRefuse(IntegrityKey::missingMessage() . ' Nothing was changed.');
    }

    // Resolve the reviewed exceptions to documents
    $documentIds = null;
    if ($selectedCodes !== null) {
        $documentIds = sealChainsDocumentIds($selectedCodes);
        if ($selectedCodes === [] || $documentIds === null) {
            return sealChainsRefuse('--seal-documents must list existing tracking codes. Nothing was changed.');
        }
    }
    if ($exceptionMode) {
        fwrite(STDERR, "WARNING: {$configuredDatabase}: sealing outside the default rules (overwrite: " . ($overwrite ? 'yes' : 'no')
            . ', seal missing on a sealed database: ' . ($sealMissing || $selectedCodes !== null ? 'yes' : 'no')
            . ($selectedCodes !== null ? ', documents: ' . implode(' ', $selectedCodes) : '') . "). Each chain is still verified first.\n");
    }

    $stats = ChainSealService::sealExisting($overwrite, $documentIds, true, $sealMissing || $selectedCodes !== null);
    SystemAuditService::record('chain_seals_written', [
        'database' => $configuredDatabase,
        'overwrite' => $overwrite,
        'seal_missing_on_sealed_database' => $sealMissing,
        'selected_documents' => $selectedCodes,
        'documents_sealed' => $stats['documents_sealed'],
        'entries_sealed' => $stats['entries_sealed'],
        'refused_documents' => array_column($stats['refused'], 0),
        'seal_missing_documents' => $stats['seal_missing'],
    ]);

    echo "Database: {$configuredDatabase}\n";
    echo "Documents sealed: {$stats['documents_sealed']}\n";
    echo "Entries sealed:   {$stats['entries_sealed']}\n";

    // Everything the script refused to seal is listed, and makes the exit code non-zero
    foreach ($stats['refused'] as [$trackingCode, $reason]) {
        echo "REFUSED DOCUMENT {$trackingCode}: {$reason}\n";
    }
    foreach ($stats['seal_missing'] as $trackingCode) {
        echo "seal missing on a sealed database: review ({$trackingCode})\n";
    }
    $problems = count($stats['refused']) + count($stats['seal_missing']) + $stats['invalid_entry_seals'] + $stats['invalid_chain_seals'];
    if ($problems === 0) {
        echo "Done. Existing seals were left unchanged.\n";

        return 0;
    }
    echo "Left untouched: " . count($stats['refused']) . " document(s) whose chain does not verify, " . count($stats['seal_missing']) . " document(s) with a missing seal on a sealed database, "
        . "{$stats['invalid_entry_seals']} entry seal(s) and {$stats['invalid_chain_seals']} chain seal(s) that do not verify.\n";
    echo "Run the integrity check and investigate before sealing anything again.\n";

    return 2;
}

// Run only when executed directly, so tests and seeders can include the file's helpers if ever needed
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(sealChainsMain(array_slice($argv, 1)));
}
