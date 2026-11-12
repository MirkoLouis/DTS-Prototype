<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once BASE_PATH . '/scripts/server-lib.php';

class WorkerWatchdogTest extends TestCase
{
    private string $dir;
    private string $script;
    private array $procs = [];

    // A stand-in worker script in a path with a space, like "DTS Prototype"
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dts watchdog ' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->script = $this->dir . '/console.php';
        file_put_contents($this->script, '<?php sleep(30);');
    }

    // Stop every stand-in process and remove the script
    protected function tearDown(): void
    {
        foreach ($this->procs as $proc) {
            proc_terminate($proc);
            proc_close($proc);
        }
        @unlink($this->script);
        @rmdir($this->dir);
    }

    // Start the stand-in the way a given command line would, and return its PID
    private function spawn(array $command): int
    {
        $proc = proc_open($command, [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        $this->procs[] = $proc;
        usleep(200_000);
        return proc_get_status($proc)['pid'];
    }

    // No worker running means no PIDs
    public function test_no_worker_means_no_pids(): void
    {
        $this->assertSame([], workerPids($this->script));
    }

    // A worker started as "php <path>" is found
    public function test_finds_worker_started_with_php(): void
    {
        $pid = $this->spawn(['php', $this->script]);
        $this->assertContains($pid, workerPids($this->script));
    }

    // A worker after its planned re-exec ("/usr/bin/php8.x <path>") is still found, so the watchdog never starts a duplicate
    public function test_finds_worker_after_reexec_with_php_binary(): void
    {
        $pid = $this->spawn([PHP_BINARY, $this->script]);
        $this->assertContains($pid, workerPids($this->script));
    }

    // The lookup does not match itself: exactly one PID for one worker
    public function test_lookup_does_not_count_itself(): void
    {
        $pid = $this->spawn(['php', $this->script]);
        $this->assertSame([$pid], workerPids($this->script));
    }
}
