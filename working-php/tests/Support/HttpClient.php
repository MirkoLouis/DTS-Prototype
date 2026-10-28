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

    // Server process handle, set only when this client started the built-in server itself
    private static $process = null;
    private static ?string $startedUrl = null;

    /**
     * Start the PHP built-in server (multi-worker) or attach to TEST_BASE_URL when set.
     */
    public static function start(): self
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
        if (self::$startedUrl !== null) {
            return new self(self::$startedUrl);
        }

        // Find a free port by binding port 0 and releasing it
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $port = (int) explode(':', stream_socket_get_name($probe, false))[1];
        fclose($probe);

        // Launch the server with the testing environment and parallel workers
        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'PHP_CLI_SERVER_WORKERS' => (string) self::CLI_SERVER_WORKERS]);
        $cmd = [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', 'public', 'public/index.php'];
        self::$process = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, BASE_PATH, $env);
        if (!is_resource(self::$process)) {
            throw new RuntimeException('Could not start the PHP built-in server.');
        }
        register_shutdown_function([self::class, 'stop']);

        // Wait until the port accepts connections
        for ($i = 0; $i < 100; $i++) {
            $sock = @fsockopen('127.0.0.1', $port, $e, $s, 0.1);
            if ($sock) {
                fclose($sock);
                self::$startedUrl = "http://127.0.0.1:{$port}";
                return new self(self::$startedUrl);
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
     * Stop the server this class started, if any.
     */
    public static function stop(): void
    {
        // Terminate the child process and forget it
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }
        self::$process = null;
        self::$startedUrl = null;
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
