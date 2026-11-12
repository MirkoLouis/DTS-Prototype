<?php

namespace Tests\Concurrency;

use App\Common\Database\Database;
use PHPUnit\Framework\Attributes\Group;
use App\Research\Barrier;
use Tests\Support\HttpClient;
use App\Research\ResultWriter;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Simultaneous guest kiosk submissions through the real HTTP endpoint (thesis section 3.6(a)).
 * Knobs: CONCURRENCY_WORKERS (default 100), CONCURRENCY_RUNS (default 1).
 */
#[Group('concurrency')]
class GuestSubmissionConcurrencyTest extends TestCase
{
    // Read a positive integer knob from the environment
    private static function knob(string $name, int $default): int
    {
        $value = (int) getenv($name);

        return $value > 0 ? $value : $default;
    }

    // Label of the server under test for the CSV, so nginx/FPM re-runs are distinguishable
    private static function serverLabel(): string
    {
        $external = getenv('TEST_BASE_URL');

        return $external ?: 'php-cli-server(workers=' . HttpClient::CLI_SERVER_WORKERS . ')';
    }

    // Four simultaneous logins must overlap; a single-threaded server would take about four times one login
    public function test_server_handles_requests_in_parallel(): void
    {
        // A correct-password POST /login is a real endpoint that is slow on purpose (bcrypt cost 10)
        $openForm = function (): HttpClient {
            $client = HttpClient::start();
            $client->get('/login');

            return $client;
        };
        $login = function (HttpClient $client): array {
            $start = microtime(true);
            $response = $client->post('/login', ['email' => 'cash.unit', 'password' => TestDatabase::PASSWORD]);

            return ['start' => $start, 'end' => microtime(true), 'status' => $response['status']];
        };

        // Warm up, then time single logins in isolation and keep the slowest
        $login($openForm());
        $slowest = 0.0;
        for ($i = 0; $i < 4; $i++) {
            $single = $login($openForm());
            $slowest = max($slowest, $single['end'] - $single['start']);
        }

        // The same four logins fired together after the barrier, each with its own session opened beforehand
        $results = Barrier::forkAll(4, fn(int $index, HttpClient $client): array => $login($client), fn(int $index): HttpClient => $openForm());

        // The batch spans from the first start to the last finish
        $batch = max(array_column($results, 'end')) - min(array_column($results, 'start'));
        $this->assertSame([302, 302, 302, 302], array_column($results, 'status'));
        $this->assertLessThan(2 * $slowest, $batch, sprintf('Batch took %.3fs, slowest single request %.3fs: the server is serializing requests.', $batch, $slowest));
    }

    // N simultaneous kiosk submissions each get a distinct code that maps back to their own document
    public function test_100_simultaneous_submissions_get_unique_matching_codes(): void
    {
        $workers = self::knob('CONCURRENCY_WORKERS', 100);
        $runs = self::knob('CONCURRENCY_RUNS', 1);
        $db = Database::getInstance();
        $purposeId = (int) $db->query('SELECT id FROM purposes WHERE is_official = 1 ORDER BY id LIMIT 1')->fetch()['id'];

        // Make sure the server is up before any child needs it
        $warmer = HttpClient::start();

        // Each repetition is one simultaneous batch
        for ($run = 1; $run <= $runs; $run++) {
            // Clear the response cache right before forking so GET / is cold for every kiosk
            foreach (glob(\App\Common\Middleware\CacheMiddleware::cacheDir() . '/*') ?: [] as $cached) {
                @unlink($cached);
            }
            $before = (int) $db->query('SELECT COUNT(*) c FROM documents')->fetch()['c'];

            // Every worker is an independent kiosk session: the form (CSRF) loads before the barrier,
            // so only the POSTs are released together at the shared start
            $openForm = function (int $i): HttpClient {
                $client = HttpClient::start();
                $client->get('/');

                return $client;
            };
            $results = Barrier::forkAll($workers, function (int $i, HttpClient $client) use ($run, $purposeId): array {
                $start = microtime(true);
                $response = $client->post('/submit-document', [
                    'guest_name' => "Concurrent Guest {$i}",
                    'guest_email' => "guest{$i}@example.com",
                    'guest_phone' => '09171234567',
                    'district' => 'N/A',
                    'department' => 'Records Unit',
                    'title' => "CONC-{$run}-{$i}",
                    'purpose_id' => (string) $purposeId,
                ]);

                // The tracking code travels in the redirect to the success page
                $location = $response['headers']['location'] ?? '';
                parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
                $end = microtime(true);

                // On a failed submission, follow the bounce to read the flashed error for the report
                $failure = null;
                if (!str_starts_with($location, '/success')) {
                    $page = $location !== '' ? $client->get($location)['body'] : $response['body'];
                    $failure = preg_match('/(An error occurred[^<]*|Page Expired[^<]*|[^>]*is required[^<]*)/', $page, $m) ? trim($m[1]) : substr(strip_tags($page), 0, 200);
                }

                return [
                    'status' => $response['status'],
                    'location' => $location,
                    'tracking_code' => $query['tracking_code'] ?? null,
                    'start' => $start,
                    'end' => $end,
                    'failure' => $failure,
                ];
            }, $openForm);

            // Wall time runs from the first POST leaving to the last response arriving
            $wall = max(array_column($results, 'end')) - min(array_column($results, 'start'));
            $slowest = max(array_map(fn($r) => $r['end'] - $r['start'], $results));

            // Count successes and look up each returned code
            $codes = array_filter(array_column($results, 'tracking_code'));
            $successes = 0;
            $mismatches = 0;
            $logFaults = 0;
            foreach ($results as $i => $result) {
                // A success is a redirect to /success carrying a code
                if ($result['status'] === 302 && str_starts_with($result['location'], '/success') && $result['tracking_code']) {
                    $successes++;
                }
                $row = $result['tracking_code']
                    ? $db->query('SELECT id, title FROM documents WHERE tracking_code = :c', [':c' => $result['tracking_code']])->fetch()
                    : false;

                // The row behind the code must be this worker's own document
                if (!$row || $row['title'] !== "CONC-{$run}-{$i}") {
                    $mismatches++;
                    continue;
                }

                // Exactly one 'Submitted' log per new document
                $logs = (int) $db->query("SELECT COUNT(*) c FROM document_logs WHERE document_id = :id AND action = 'Submitted'", [':id' => $row['id']])->fetch()['c'];
                if ($logs !== 1) {
                    $logFaults++;
                }
            }
            $after = (int) $db->query('SELECT COUNT(*) c FROM documents')->fetch()['c'];
            $distinct = count(array_unique($codes));

            // Record the evidence row before asserting so a failure is still measured
            ResultWriter::append('concurrency.csv', [
                'timestamp' => date('c'),
                'test' => 'guest_submission',
                'run' => $run,
                'workers' => $workers,
                'successes' => $successes,
                'distinct_codes' => $distinct,
                'mismatches' => $mismatches,
                'documents_added' => $after - $before,
                'submitted_log_faults' => $logFaults,
                'wall_seconds' => round($wall, 3),
                'max_request_seconds' => round($slowest, 3),
                'server' => self::serverLabel(),
                'cache_warmed' => 'false',
                'skew_seconds' => round(Barrier::skew($results), 4),
                'notes' => 'GET / cold cache; CacheMiddleware writes atomically (F3 fixed)',
            ]);

            $this->assertCount($workers, $results);
            $failures = array_filter($results, fn($r) => $r['failure'] !== null);
            $this->assertSame($workers, $successes, "Run {$run}: not every submission redirected to /success: " . json_encode(array_slice($failures, 0, 5, true)));
            $this->assertSame($workers, $distinct, "Run {$run}: tracking codes were not all distinct.");
            $this->assertSame($workers, $after - $before, "Run {$run}: document count did not grow by {$workers}.");
            $this->assertSame(0, $mismatches, "Run {$run}: a tracking code pointed at another worker's document.");
            $this->assertSame(0, $logFaults, "Run {$run}: a document lacks exactly one 'Submitted' log.");
        }
    }
}
