<?php

namespace App\Research;

use InvalidArgumentException;
use PDO;

/**
 * Removes the documents a live concurrency run (scripts/manual/live-concurrency.php) created in the
 * operational database. A row is deleted only when BOTH hold: its id was collected by this run, and
 * its title starts with this run's prefix CONC-LIVE-{runId}-. Anything else is left alone.
 */
class LiveRunCleanup
{
    // Run ids are 'LIVE' plus date('YmdHis'); nothing else is ever accepted
    public const RUN_ID_PATTERN = '/^LIVE\d{14}$/';

    // Escape character for the LIKE pattern; not a backslash, so NO_BACKSLASH_ESCAPES cannot change its meaning
    private const LIKE_ESCAPE = '!';

    /**
     * Throw unless $runId has the exact LIVE + 14 digits shape.
     */
    public static function assertRunId(string $runId): void
    {
        // Reject anything that could widen the title match
        if (preg_match(self::RUN_ID_PATTERN, $runId) !== 1) {
            throw new InvalidArgumentException("Invalid live run id '{$runId}': expected LIVE followed by 14 digits.");
        }
    }

    /**
     * Title prefix every document of the run carries; the worker index follows it.
     */
    public static function titlePrefix(string $runId): string
    {
        self::assertRunId($runId);

        return "CONC-LIVE-{$runId}-";
    }

    /**
     * Delete the run's documents and their logs; return the number of documents deleted.
     *
     * @param list<int|string> $documentIds ids collected by the run (positive integers)
     */
    public static function delete(PDO $pdo, string $runId, array $documentIds): int
    {
        // Validate the run id first so a malformed one never reaches a query
        $pattern = self::likePattern(self::titlePrefix($runId));
        $ids = self::normalizeIds($documentIds);

        // Nothing collected means nothing to delete
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $scope = "id IN ({$placeholders}) AND title LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'";
        $params = array_merge($ids, [$pattern]);

        // Logs first with the same scope (the FK cascade would also remove them; this does not rely on it), then documents
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM document_logs WHERE document_id IN (SELECT id FROM (SELECT id FROM documents WHERE {$scope}) scoped)")->execute($params);
            $statement = $pdo->prepare("DELETE FROM documents WHERE {$scope}");
            $statement->execute($params);
            $pdo->commit();
        } catch (\Throwable $e) {
            // Leave the database as it was on any failure
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $statement->rowCount();
    }

    /**
     * The live script's full cleanup sequence:
     *  1. ignore SIGINT/SIGTERM so a second Ctrl-C cannot abort cleanup halfway (left ignored: the
     *     caller's remaining report and CSV row must finish too);
     *  2. stop the workers ($stopWorkers, e.g. Barrier::killActive) so no child can still be mid-POST;
     *  3. delete the collected ids plus the sweep of this run's rows above $maxIdBefore;
     *  4. ALWAYS wait $settleSeconds for requests the server was still executing, then sweep and delete again.
     *
     * @param list<int> $collectedIds ids the workers reported
     * @param callable|null $stopWorkers fn(): int, returns how many workers it killed
     * @param callable|null $sleep fn(float $seconds): void, injectable for tests
     * @return array{deleted:int, remaining:int, killed:int}
     */
    public static function finish(PDO $pdo, string $runId, array $collectedIds, int $maxIdBefore, ?callable $stopWorkers = null, ?callable $sleep = null, float $settleSeconds = 3.0): array
    {
        // Validate before touching signals or workers
        self::assertRunId($runId);
        self::ignoreInterrupts();

        // No child may keep submitting once cleanup has begun
        $killed = $stopWorkers ? (int) $stopWorkers() : 0;

        // First pass: everything reported or already visible
        $deleted = self::delete($pdo, $runId, array_values(array_unique(array_merge(
            self::normalizeIds($collectedIds),
            self::findRunDocumentIds($pdo, $runId, $maxIdBefore)
        ))));

        // Second pass after the settle delay, for submissions the server committed after the first pass
        ($sleep ?? fn(float $s) => usleep((int) ($s * 1_000_000)))($settleSeconds);
        $deleted += self::delete($pdo, $runId, self::findRunDocumentIds($pdo, $runId, $maxIdBefore));

        return [
            'deleted' => $deleted,
            'remaining' => count(self::findRunDocumentIds($pdo, $runId, $maxIdBefore)),
            'killed' => $killed,
        ];
    }

    /**
     * Ignore SIGINT and SIGTERM for the rest of this process (no-op without pcntl).
     */
    public static function ignoreInterrupts(): void
    {
        // Only possible with the pcntl extension
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, SIG_IGN);
            pcntl_signal(SIGTERM, SIG_IGN);
        }
    }

    /**
     * Ids of documents above $afterId whose title carries the run prefix: the run's rows, including
     * those of a worker that died before reporting its redirect.
     *
     * @return list<int>
     */
    public static function findRunDocumentIds(PDO $pdo, string $runId, int $afterId): array
    {
        // Same escaped prefix match as delete(), bounded below by the highest id seen before the run
        $statement = $pdo->prepare("SELECT id FROM documents WHERE id > ? AND title LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "' ORDER BY id");
        $statement->execute([$afterId, self::likePattern(self::titlePrefix($runId))]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Checkpoint files in $dir modified at or after $since (Unix time). Their recorded chain heads
     * may include run documents that cleanup has just deleted.
     *
     * @return list<string> paths
     */
    public static function checkpointsWrittenSince(string $dir, float $since): array
    {
        // Keep files whose modification time is not before the run start
        $found = [];
        foreach (glob(rtrim($dir, '/') . '/*.json') ?: [] as $file) {
            clearstatcache(true, $file);
            if (filemtime($file) >= (int) floor($since)) {
                $found[] = $file;
            }
        }
        sort($found);

        return $found;
    }

    /**
     * LIKE pattern matching the literal prefix followed by anything.
     */
    private static function likePattern(string $prefix): string
    {
        // Escape the escape character itself, then the two LIKE wildcards
        $e = self::LIKE_ESCAPE;
        $literal = str_replace([$e, '%', '_'], [$e . $e, $e . '%', $e . '_'], $prefix);

        return $literal . '%';
    }

    /**
     * Positive integer ids, de-duplicated; anything else is a caller bug and throws.
     *
     * @return list<int>
     */
    private static function normalizeIds(array $documentIds): array
    {
        // Accept ints and digit strings only
        $ids = [];
        foreach ($documentIds as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[$id] = $id;
                    continue;
                }
            }
            throw new InvalidArgumentException('Document ids must be positive integers; got ' . var_export($id, true) . '.');
        }

        return array_values($ids);
    }
}
