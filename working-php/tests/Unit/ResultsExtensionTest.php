<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Runs PHPUnit on a tiny fixture suite and checks the files the results extension writes.
 */
class ResultsExtensionTest extends TestCase
{
    // Read a CSV into a list of header-keyed rows
    private function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ',', '"', '\\');
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rows[] = array_combine($header, $row);
        }
        fclose($handle);

        return $rows;
    }

    // Remove a run folder (files only; the extension writes no sub-folders)
    private function removeRun(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($dir);
    }

    public function test_fixture_run_writes_outcomes_summary_and_run_json(): void
    {
        // Run the fixture suite in a subprocess with a known run id; `latest` is left alone
        $runId = 'fixture-' . bin2hex(random_bytes(4));
        $runDir = BASE_PATH . '/storage/test-results/' . $runId;
        $cmd = sprintf(
            'cd %s && DTS_RUN_ID=%s DTS_NO_LATEST=1 DTS_RESULTS_DIR= %s %s -c %s 2>&1',
            escapeshellarg(BASE_PATH),
            escapeshellarg($runId),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(BASE_PATH . '/vendor/bin/phpunit'),
            escapeshellarg(BASE_PATH . '/tests/Fixtures/results-suite/phpunit.xml')
        );

        try {
            exec($cmd, $output, $code);
            $this->assertSame(1, $code, implode("\n", $output));

            // Folder and files exist
            $this->assertDirectoryExists($runDir);
            $outcomes = $this->readCsv($runDir . '/test-outcomes.csv');
            $this->assertCount(3, $outcomes);

            // Statuses per test
            $status = array_column($outcomes, 'status', 'test');
            $this->assertSame('passed', $status['test_passes']);
            $this->assertSame('failed', $status['test_fails']);
            $this->assertSame('skipped', $status['test_skipped']);
            $this->assertSame($runId, $outcomes[0]['run_id']);
            $this->assertStringContainsString('intentional failure', array_column($outcomes, 'message', 'test')['test_fails']);

            // Summary totals
            $summary = array_column($this->readCsv($runDir . '/summary.csv'), null, 'suite');
            $this->assertSame('3', $summary['all']['tests']);
            $this->assertSame('1', $summary['all']['passed']);
            $this->assertSame('1', $summary['all']['failed']);
            $this->assertSame('1', $summary['all']['skipped']);
            $this->assertSame('0', $summary['all']['errored']);

            // run.json parses and lists the files
            $meta = json_decode((string) file_get_contents($runDir . '/run.json'), true);
            $this->assertIsArray($meta);
            $this->assertSame($runId, $meta['run_id']);
            $this->assertContains('test-outcomes.csv', $meta['files']);
            $this->assertArrayHasKey('finished_at', $meta);
        } finally {
            $this->removeRun($runDir);
        }
    }

    // The fixture suite must never be picked up by the real suites
    public function test_fixture_suite_is_not_part_of_main_suites(): void
    {
        $xml = (string) file_get_contents(BASE_PATH . '/phpunit.xml');
        $this->assertStringNotContainsString('Fixtures', $xml);
        $this->assertStringNotContainsString('results-suite', $xml);
    }
}
