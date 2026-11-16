<?php

namespace Tests\Support;

use App\Research\HttpSession;
use RuntimeException;

/**
 * Cookie-aware HTTP client for black-box tests: starts (or attaches to) the server under test.
 * Requests, cookies and CSRF scraping live in App\Research\HttpSession. Redirects are never followed.
 */
class HttpClient extends HttpSession
{
    // Parallel workers given to the built-in server (PHP_CLI_SERVER_WORKERS)
    public const CLI_SERVER_WORKERS = 16;

    // Built-in servers this process started, by whether rate limiting is on: [processes, urls]
    private static array $processes = [];
    private static array $startedUrls = [];

    /**
     * Start the PHP built-in server (multi-worker) with rate limiting on, or attach to TEST_BASE_URL when set.
     */
    public static function start(): self
    {
        return self::launch(true);
    }

    /**
     * Like start(), but the server runs with RATE_LIMIT_ENABLED=false, for suites that send more guest requests
     * from one IP than the per-IP limits allow (many submissions, benchmark loops). With TEST_BASE_URL the external
     * server's own setting applies, so it must be started with RATE_LIMIT_ENABLED=false for those suites.
     */
    public static function startWithoutRateLimit(): self
    {
        return self::launch(false);
    }

    /**
     * Start (or reuse) the server for the given rate-limit setting, or attach to TEST_BASE_URL when set.
     */
    private static function launch(bool $rateLimited): self
    {
        // An external server (nginx/FPM) takes priority
        $external = getenv('TEST_BASE_URL');
        if ($external) {
            // Refuse to attach unless the server proves it runs the testing env on a _test database
            $client = new self($external);
            $probe = $client->get('/__test/env');
            self::assertProbeSafe($probe['status'], $probe['body']);
            return $client;
        }

        // Reuse a server this process already started
        $slot = $rateLimited ? 'limited' : 'unlimited';
        if (isset(self::$startedUrls[$slot])) {
            return new self(self::$startedUrls[$slot]);
        }

        // Find a free port by binding port 0 and releasing it
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $port = (int) explode(':', stream_socket_get_name($probe, false))[1];
        fclose($probe);

        // Launch the server with the testing environment and parallel workers
        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'PHP_CLI_SERVER_WORKERS' => (string) self::CLI_SERVER_WORKERS]);
        $env['RATE_LIMIT_ENABLED'] = $rateLimited ? 'true' : 'false';
        $cmd = [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', 'public', 'public/index.php'];
        $process = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, BASE_PATH, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the PHP built-in server.');
        }
        self::$processes[$slot] = $process;
        register_shutdown_function([self::class, 'stop']);

        // Wait until the port accepts connections
        for ($i = 0; $i < 100; $i++) {
            $sock = @fsockopen('127.0.0.1', $port, $e, $s, 0.1);
            if ($sock) {
                fclose($sock);
                self::$startedUrls[$slot] = "http://127.0.0.1:{$port}";
                return new self(self::$startedUrls[$slot]);
            }
            usleep(50000);
        }

        self::stop();
        throw new RuntimeException('PHP built-in server did not start in time.');
    }

    /**
     * Throw unless a /__test/env probe response reports env "testing" and a database ending in _test.
     */
    public static function assertProbeSafe(int $status, string $body): void
    {
        // A missing probe (404) means the server is not running the testing environment
        $data = $status === 200 ? json_decode($body, true) : null;
        if (!is_array($data) || !is_string($data['env'] ?? null) || !is_string($data['db'] ?? null)) {
            throw new RuntimeException("Refusing TEST_BASE_URL: the server has no valid /__test/env probe (HTTP {$status}); run it with APP_ENV=testing.");
        }

        // Reuse the suite's env and database-name guard
        TestGuard::assertSafe($data['env'], $data['db']);
    }

    /**
     * Stop the servers this class started, if any.
     */
    public static function stop(): void
    {
        // Terminate each child process and forget it
        foreach (self::$processes as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        self::$processes = [];
        self::$startedUrls = [];
    }

    /**
     * Send a DELETE request (no body; the router checks CSRF on POST only).
     */
    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    /**
     * Log in through the real login form as a seeded user.
     */
    public function loginAs(string $username): void
    {
        // Load the form first so the session and CSRF token exist
        $this->get('/login');
        $response = $this->post('/login', ['email' => $username, 'password' => TestDatabase::PASSWORD]);
        if ($response['status'] !== 302 || str_contains($response['headers']['location'] ?? '', '/login')) {
            throw new RuntimeException("Login failed for {$username} (HTTP {$response['status']}).");
        }

        // Load a page so the new session's token is the one cached
        $this->get($response['headers']['location']);
    }
}
