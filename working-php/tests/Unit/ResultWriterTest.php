<?php

namespace Tests\Unit;

use App\Research\ResultWriter;
use PHPUnit\Framework\TestCase;

class ResultWriterTest extends TestCase
{
    // With DTS_RESULTS_DIR set, rows land in that folder with a header on first write
    public function test_append_writes_into_dts_results_dir(): void
    {
        // Point the writer at a throwaway folder
        $dir = BASE_PATH . '/storage/test-results/rw-test-' . bin2hex(random_bytes(4));
        $previous = getenv('DTS_RESULTS_DIR');
        putenv('DTS_RESULTS_DIR=' . $dir);

        try {
            ResultWriter::append('probe.csv', ['a' => 1, 'b' => 'x']);
            ResultWriter::append('probe.csv', ['a' => 2, 'b' => 'y']);

            // Header once, then both rows
            $this->assertFileExists($dir . '/probe.csv');
            $lines = file($dir . '/probe.csv', FILE_IGNORE_NEW_LINES);
            $this->assertSame(['a,b', '1,x', '2,y'], $lines);
            $this->assertSame($dir, ResultWriter::dir());
        } finally {
            // Restore the environment and remove the throwaway folder
            putenv($previous === false ? 'DTS_RESULTS_DIR' : 'DTS_RESULTS_DIR=' . $previous);
            @unlink($dir . '/probe.csv');
            @rmdir($dir);
        }
    }

    // Run ids that could escape storage/test-results are rejected
    public function test_invalid_run_id_is_rejected(): void
    {
        $this->assertRejected(['DTS_RESULTS_DIR' => '', 'DTS_RUN_ID' => '..'], \InvalidArgumentException::class);
        $this->assertRejected(['DTS_RESULTS_DIR' => '', 'DTS_RUN_ID' => 'a/b'], \InvalidArgumentException::class);
    }

    // A results dir outside storage/test-results is rejected
    public function test_results_dir_outside_storage_is_rejected(): void
    {
        $outside = sys_get_temp_dir() . '/dts-outside-' . bin2hex(random_bytes(3));
        $this->assertRejected(['DTS_RESULTS_DIR' => $outside], \InvalidArgumentException::class);

        // Rejection must not have created the folder
        $this->assertDirectoryDoesNotExist($outside);
    }

    // Reusing a run id whose folder already has run.json is an error
    public function test_existing_run_json_is_not_overwritten(): void
    {
        $id = 'rw-reuse-' . bin2hex(random_bytes(3));
        $dir = BASE_PATH . '/storage/test-results/' . $id;
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/run.json', '{}');
        try {
            $this->assertRejected(['DTS_RESULTS_DIR' => '', 'DTS_RUN_ID' => $id, 'DTS_NO_LATEST' => '1'], \RuntimeException::class);
        } finally {
            unlink($dir . '/run.json');
            rmdir($dir);
        }
    }

    // Call dir() with the given env values and assert it throws the expected exception
    private function assertRejected(array $env, string $exception): void
    {
        // Save, set and restore the environment around the call
        $saved = [];
        foreach ($env as $k => $v) {
            $saved[$k] = getenv($k);
            putenv($k . '=' . $v);
        }
        try {
            try {
                ResultWriter::dir();
                $this->fail('Expected ' . $exception);
            } catch (\Throwable $e) {
                $this->assertInstanceOf($exception, $e);
            }
        } finally {
            foreach ($saved as $k => $v) {
                putenv($v === false ? $k : $k . '=' . $v);
            }
        }
    }
}
