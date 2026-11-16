<?php

namespace Tests\Unit;

use App\Common\Config\Config;
use App\Common\Database\Database;
use App\Common\Middleware\RateLimitMiddleware;
use App\Common\RateLimit\RateLimitService;
use App\Common\RateLimit\RateLimitSwitch;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The rate-limit middleware: the on/off switch, the client taken from REMOTE_ADDR only, and the route limits.
 */
class RateLimitMiddlewareTest extends TestCase
{
    private const NOW = 1_800_000_000;

    // Server variables restored after each test
    private array $serverBackup = [];

    // Remember $_SERVER so a test can set client headers
    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
    }

    // Restore $_SERVER
    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    // Number of rows in rate_limits
    private function rowCount(): int
    {
        return (int) Database::getInstance()->query('SELECT COUNT(*) c FROM rate_limits')->fetch()['c'];
    }

    // With the switch off nothing is limited and nothing is counted
    public function test_switch_off_limits_nothing(): void
    {
        $middleware = new RateLimitMiddleware(null, false);

        for ($i = 0; $i < 50; $i++) {
            $this->assertNull($middleware->retryAfter('submit', 10, 60, '203.0.113.7', self::NOW));
        }
        $this->assertSame(0, $this->rowCount());
    }

    // With the switch on the same calls are refused after the limit
    public function test_switch_on_limits_after_max(): void
    {
        $middleware = new RateLimitMiddleware(null, true);

        for ($i = 0; $i < 10; $i++) {
            $this->assertNull($middleware->retryAfter('submit', 10, 60, '203.0.113.7', self::NOW));
        }
        $this->assertSame(60, $middleware->retryAfter('submit', 10, 60, '203.0.113.7', self::NOW));
    }

    // RATE_LIMIT_ENABLED defaults to true when absent: the configuration reports it on in the test environment
    public function test_limiter_is_on_by_default_in_the_test_environment(): void
    {
        $this->assertTrue(Config::get('app', 'rate_limit_enabled'));
    }

    // The configuration reads the switch from the environment, with absent or unrecognised values meaning on
    public function test_configuration_reads_the_switch(): void
    {
        $read = function (?string $value): bool {
            $previous = getenv('RATE_LIMIT_ENABLED');
            $value === null ? putenv('RATE_LIMIT_ENABLED') : putenv('RATE_LIMIT_ENABLED=' . $value);
            $config = require BASE_PATH . '/src/Common/Config/app.config.php';
            $previous === false ? putenv('RATE_LIMIT_ENABLED') : putenv('RATE_LIMIT_ENABLED=' . $previous);

            return $config['app']['rate_limit_enabled'];
        };

        $this->assertFalse($read('false'));
        $this->assertTrue($read('true'));
        $this->assertTrue($read(null));
        $this->assertTrue($read('not-a-boolean'));

        // An empty value is not an explicit off
        $this->assertTrue($read(''));
    }

    // Only an explicit off value (trimmed, any case) switches the limiter off; empty, blanks and typos leave it on
    #[DataProvider('switchValues')]
    public function test_only_an_explicit_off_value_switches_the_limiter_off(?string $raw, bool $expectedOn): void
    {
        $this->assertSame($expectedOn, RateLimitSwitch::isOn($raw));
    }

    // Raw setting => whether the limiter is on
    public static function switchValues(): array
    {
        return [
            'empty' => ['', true],
            'blank' => [' ', true],
            'zero' => ['0', false],
            'off' => ['off', false],
            'OFF' => ['OFF', false],
            'no' => ['no', false],
            'FALSE' => ['FALSE', false],
            'padded false' => [' false ', false],
            'true' => ['true', true],
            'one' => ['1', true],
            'typo' => ['flase', true],
            'absent' => [null, true],
        ];
    }

    // The client is REMOTE_ADDR; X-Forwarded-For and similar headers are ignored
    public function test_client_is_remote_addr_only(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';
        $_SERVER['HTTP_X_REAL_IP'] = '198.51.100.98';
        $_SERVER['HTTP_CLIENT_IP'] = '198.51.100.97';
        $_SERVER['HTTP_FORWARDED'] = 'for=198.51.100.96';

        $this->assertSame('203.0.113.7', RateLimitMiddleware::clientAddress());

        // Without REMOTE_ADDR there is one shared, fixed client rather than a header-chosen one
        unset($_SERVER['REMOTE_ADDR']);
        $this->assertSame('unknown', RateLimitMiddleware::clientAddress());
    }

    // A hit is recorded under the REMOTE_ADDR key, never under a forwarded address
    public function test_hit_is_recorded_for_remote_addr_not_forwarded_address(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';

        (new RateLimitMiddleware(null, true))->retryAfter('track', 60, 60, RateLimitMiddleware::clientAddress(), self::NOW);

        $keys = Database::getInstance()->query('SELECT rate_key FROM rate_limits')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([RateLimitService::key('track', '203.0.113.7')], $keys);
    }

    // The limits of the five guest routes live in the middleware's constants, as "bucket,max,windowSeconds"
    public function test_route_limits_are_defined_once_in_the_middleware(): void
    {
        $this->assertSame('submit,10,60', RateLimitMiddleware::SUBMIT);
        $this->assertSame('track,60,60', RateLimitMiddleware::TRACK);
        $this->assertSame('track-module,120,60', RateLimitMiddleware::TRACK_MODULE);
        $this->assertSame('track-poll,240,60', RateLimitMiddleware::TRACK_POLL);
        $this->assertSame('pdf,10,60', RateLimitMiddleware::PDF);
    }

    // The five routes are registered with the limiter first, using those constants
    public function test_routes_attach_the_limiter_first(): void
    {
        $routes = file_get_contents(BASE_PATH . '/public/index.php');
        $limiter = 'App\Common\Middleware\RateLimitMiddleware::class . \':\' . App\Common\Middleware\RateLimitMiddleware::';

        foreach ([
            "'/submit-document'" => 'SUBMIT',
            "'/track'" => 'TRACK',
            "'/api/track/module/" => 'TRACK_MODULE',
            "'/api/track/status'" => 'TRACK_POLL',
            "'/documents/(?P<tracking_code>[A-Za-z0-9\\-]+)/print-tracking-form'" => 'PDF',
        ] as $route => $constant) {
            // The route statement starts with the path and lists the limiter as its first middleware
            $start = strpos($routes, $route);
            $this->assertNotFalse($start, "route {$route} not found");
            $statement = substr($routes, $start, strpos($routes, ';', $start) - $start);
            $this->assertStringContainsString('[' . $limiter . $constant, preg_replace('/\[\s+/', '[', $statement), "{$route} must start its middleware list with the limiter");
        }
    }
}
