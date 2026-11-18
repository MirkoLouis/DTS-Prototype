<?php

namespace Tests\Unit;

use App\Common\Http\SecurityHeaders;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestFiles;

require_once BASE_PATH . '/scripts/server-lib.php';

/**
 * The security headers on everything NGINX serves itself (static files, the gateway error page, NGINX's own
 * error responses) and the way the NGINX template and PHP share one policy text.
 *
 * Text-level checks always run. When the nginx binary exists, a rendered copy of the template is also checked
 * with `nginx -t` and run as a temporary instance (non-root, a free high port on 127.0.0.1, every path under
 * .test/, no PHP-FPM behind it), and the real response headers are asserted. Live NGINX + PHP-FPM is not covered.
 */
class NginxSecurityHeadersTest extends TestCase
{
    private const TEMPLATE = '/nginx/site.conf';

    // Where the nginx binary may be (sbin is often not on a normal user's PATH)
    private const NGINX_BINARIES = ['/usr/sbin/nginx', '/usr/bin/nginx', '/usr/local/sbin/nginx', '/usr/local/bin/nginx'];

    // The running temporary instance, if any
    private $process = null;
    private string $workDir = '';

    // Stop the temporary NGINX instance
    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }

    // The unrendered template
    private function template(): string
    {
        return (string) file_get_contents(BASE_PATH . self::TEMPLATE);
    }

    // The template with every placeholder filled in, as installNginxConfig() would install it
    private function rendered(): string
    {
        return renderNginxConfig($this->template(), BASE_PATH, '8.5', '192.0.2.10');
    }

    // The path of the nginx binary, or null when it is not installed
    private function nginxBinary(): ?string
    {
        foreach (self::NGINX_BINARIES as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    // Split a config into its top-level text (outside any location block) and its location blocks
    private function splitLocations(string $config): array
    {
        preg_match_all('/^\s*location\s[^{]*\{[^}]*\}/m', $config, $matches);
        $outside = str_replace($matches[0], '', $config);

        return [$outside, $matches[0]];
    }

    // The four headers are added with "always" at server level, with the policy text of the PHP constant
    public function test_rendered_config_adds_every_header_with_the_php_policy(): void
    {
        $config = $this->rendered();

        // One add_header line per header, always, with exactly the value PHP sends
        foreach (SecurityHeaders::HEADERS as $name => $value) {
            $this->assertStringContainsString("add_header {$name} \"{$value}\" always;", $config, $name);
        }
        $this->assertStringContainsString(SecurityHeaders::POLICY, $config);
        $this->assertCount(4, SecurityHeaders::HEADERS);

        // The template itself carries placeholders, the rendered text none
        $this->assertStringContainsString('__SECURITY_HEADERS__', $this->template());
        $this->assertDoesNotMatchRegularExpression('/__[A-Z][A-Z_]*__/', $config);
    }

    // The add_header lines are outside every location, so static files, the gateway page and NGINX's own errors inherit them
    public function test_headers_are_set_at_server_level_only(): void
    {
        [$outside, $locations] = $this->splitLocations($this->rendered());

        $this->assertSame(count(SecurityHeaders::HEADERS), preg_match_all('/^\s*add_header\s/m', $outside));
        $this->assertNotSame([], $locations);
        foreach ($locations as $location) {
            // A location with its own add_header would stop inheriting the server-level ones
            $this->assertStringNotContainsString('add_header', preg_replace('/#.*$/m', '', $location), 'a location must not define add_header');
        }
    }

    // The PHP location drops PHP's copy, so a PHP response carries each header once (from NGINX), and drops X-Powered-By
    public function test_php_location_hides_the_headers_php_sends(): void
    {
        [, $locations] = $this->splitLocations($this->rendered());
        $phpLocation = implode("\n", array_filter($locations, fn (string $block) => str_contains($block, 'fastcgi_pass')));
        $this->assertNotSame('', $phpLocation);

        foreach ([...array_keys(SecurityHeaders::HEADERS), 'X-Powered-By'] as $name) {
            $this->assertMatchesRegularExpression('/^\s*fastcgi_hide_header\s+' . preg_quote($name, '/') . ';/m', $phpLocation, $name);
        }
    }

    // The version is not announced
    public function test_server_tokens_are_off(): void
    {
        $this->assertMatchesRegularExpression('/^\s*server_tokens\s+off;/m', $this->template());
    }

    // HSTS is deliberately not sent (self-signed LAN certificate: browsers ignore it, and honouring it would remove the warning bypass)
    public function test_no_strict_transport_security(): void
    {
        $this->assertStringNotContainsStringIgnoringCase('Strict-Transport-Security', $this->rendered());
        $this->assertArrayNotHasKey('Strict-Transport-Security', SecurityHeaders::HEADERS);
    }

    // A placeholder nobody fills in is an error, never an installed config
    public function test_unfilled_placeholder_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('__NEW_PLACEHOLDER__');
        renderNginxConfig("server_name __LAN_IP__;\nroot __NEW_PLACEHOLDER__;", BASE_PATH, '8.5', '192.0.2.10');
    }

    // A template that is missing or empty is an error before anything is installed
    public function test_missing_and_empty_templates_are_refused(): void
    {
        $directory = \Tests\Support\TestFiles::dir('nginx-template');
        $empty = $directory . '/empty.conf';
        file_put_contents($empty, "  \n");

        foreach ([$directory . '/missing.conf' => 'cannot be read', $empty => 'is empty'] as $path => $message) {
            try {
                readNginxTemplate($path);
                $this->fail("{$path} was accepted");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        // The renderer itself refuses an empty text too
        $this->expectException(\RuntimeException::class);
        renderNginxConfig('', BASE_PATH, '8.5', '192.0.2.10');
    }

    // Any __NAME__ token that is not a known placeholder is refused, a digit in the name included (a typo such as __PHP8_VERSION__)
    public function test_unknown_tokens_are_refused_including_digits(): void
    {
        foreach (['__PHP8_VERSION__', '__UNKNOWN_THING__', '__V2__'] as $token) {
            try {
                renderNginxConfig("server_name __LAN_IP__;\nroot {$token};", BASE_PATH, '8.5', '192.0.2.10');
                $this->fail("{$token} was accepted");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($token, $e->getMessage());
            }
        }
    }

    // A header value with a line break is refused (it would end the directive and inject another)
    public function test_header_value_with_a_line_break_is_refused(): void
    {
        // Every character that is special in an NGINX string is refused; a plain value passes
        foreach (["a\nb", "a\rb", 'a"b', 'a\\b', 'a$b'] as $hostile) {
            try {
                SecurityHeaders::nginxDirectiveFor('X-Test', $hostile);
                $this->fail('accepted ' . json_encode($hostile));
            } catch (\LogicException $e) {
                $this->assertStringContainsString('X-Test', $e->getMessage());
            }
        }
        $this->assertSame('add_header X-Test "plain value" always;', SecurityHeaders::nginxDirectiveFor('X-Test', 'plain value'));
    }

    // The generator refuses a header value that would break an NGINX string
    public function test_directive_generator_output_matches_the_constants(): void
    {
        $lines = explode("\n", SecurityHeaders::nginxAddHeaderDirectives(''));
        $this->assertCount(count(SecurityHeaders::HEADERS), $lines);
        $this->assertSame('add_header X-Frame-Options "DENY" always;', $lines[1]);
        $this->assertStringContainsString('fastcgi_hide_header X-Powered-By;', SecurityHeaders::nginxHiddenHeaderDirectives(''));
    }

    // Make the rendered config runnable by a normal user on a high port with every file under .test/
    private function sandboxedConfig(string $siteConfig, int $port): string
    {
        $replacements = [
            '/^\s*listen\s+8000 ssl;/m' => "    listen 127.0.0.1:{$port};",
            '/^\s*ssl_[a-z_]+\s+[^;]*;\n/m' => '',
            '/^\s*access_log\s+[^;]*;/m' => "    access_log \"{$this->workDir}/access.log\";",
            '/^\s*error_log\s+[^;]*;/m' => "    error_log \"{$this->workDir}/site-error.log\";",
            '/^\s*include\s+snippets\/fastcgi-php\.conf;/m' => '        include /etc/nginx/fastcgi_params;',
            // A socket that does not exist: no PHP-FPM, whatever runs on this machine
            '/^\s*fastcgi_pass\s+[^;]*;/m' => "        fastcgi_pass \"unix:{$this->workDir}/no-php-fpm.sock\";",
        ];
        foreach ($replacements as $pattern => $replacement) {
            $siteConfig = preg_replace($pattern, $replacement, $siteConfig, -1, $count);
            $this->assertGreaterThan(0, $count, "template line not found: {$pattern}");
        }

        $dir = $this->workDir;
        // NGINX would otherwise use /var/log, /var/lib and /run, which a normal user cannot write
        return <<<CONF
        daemon off;
        pid "{$dir}/nginx.pid";
        error_log "{$dir}/error.log";
        events { worker_connections 16; }
        http {
            include /etc/nginx/mime.types;
            client_body_temp_path "{$dir}/body";
            proxy_temp_path "{$dir}/proxy";
            fastcgi_temp_path "{$dir}/fastcgi";
            uwsgi_temp_path "{$dir}/uwsgi";
            scgi_temp_path "{$dir}/scgi";
            access_log "{$dir}/http-access.log";
        {$siteConfig}
        }
        CONF;
    }

    // Write the sandboxed config into a fresh folder under .test/ and return its path
    private function writeSandbox(int $port): string
    {
        $this->workDir = TestFiles::dir('nginx-headers');
        $path = $this->workDir . '/nginx.conf';
        file_put_contents($path, $this->sandboxedConfig($this->rendered(), $port));

        return $path;
    }

    // Run nginx with the sandboxed config: [-t] for a syntax check
    private function nginxArguments(string $binary, string $config, bool $syntaxCheckOnly): array
    {
        $arguments = [$binary, '-p', $this->workDir . '/', '-c', $config, '-e', $this->workDir . '/error.log'];

        return $syntaxCheckOnly ? [...$arguments, '-t'] : $arguments;
    }

    // The rendered template is accepted by nginx itself
    public function test_rendered_config_passes_nginx_syntax_check(): void
    {
        $binary = $this->nginxBinary();
        if ($binary === null) {
            $this->markTestSkipped('The nginx binary is not installed: the syntax check (nginx -t) was not run.');
        }

        $config = $this->writeSandbox(18999);
        $process = proc_open($this->nginxArguments($binary, $config, true), [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $status = proc_close($process);

        $this->assertSame(0, $status, "nginx -t failed:\n" . $output);
        $this->assertStringContainsString('syntax is ok', $output);
    }

    // A real request: [status, headers (lowercased name => list of values), body]
    private function request(int $port, string $path): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5, 'follow_location' => 0]]);
        $body = (string) @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
        $headers = [];
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            // The status line, then "Name: value" lines
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m)) {
                $status = (int) $m[1];
                continue;
            }
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[strtolower(trim($name))][] = trim($value);
        }

        return [$status, $headers, $body];
    }

    // Start the temporary instance and wait until it answers; returns the port
    private function startInstance(string $binary): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) explode(':', stream_socket_get_name($probe, false))[1];
        fclose($probe);

        $config = $this->writeSandbox($port);
        $this->process = proc_open($this->nginxArguments($binary, $config, false), [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);

        // Wait until the port accepts connections
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($socket) {
                fclose($socket);
                return $port;
            }
            usleep(50_000);
        }

        $this->fail('The temporary nginx did not start; see ' . $this->workDir . '/error.log');
    }

    // Every header exactly once with the PHP value, nothing that names the software
    private function assertSecurityHeadersOnce(array $headers, string $what): void
    {
        foreach (SecurityHeaders::HEADERS as $name => $value) {
            $this->assertSame([$value], $headers[strtolower($name)] ?? [], "{$what}: {$name}");
        }
        $this->assertArrayNotHasKey('strict-transport-security', $headers, $what);
        $this->assertArrayNotHasKey('x-powered-by', $headers, $what);
        $this->assertSame(['nginx'], $headers['server'] ?? [], "{$what}: Server header names no version");
    }

    // A temporary NGINX serves a static file, the gateway page (502: no PHP-FPM behind it) and its own 403 with the headers
    public function test_live_nginx_sends_the_headers_on_what_it_answers_itself(): void
    {
        $binary = $this->nginxBinary();
        if ($binary === null) {
            $this->markTestSkipped('The nginx binary is not installed: no temporary instance was started.');
        }
        $port = $this->startInstance($binary);

        // A static file from public/
        [$status, $headers] = $this->request($port, '/css/tailwind.css');
        $this->assertSame(200, $status);
        $this->assertStringStartsWith('text/css', $headers['content-type'][0] ?? '');
        $this->assertSecurityHeadersOnce($headers, 'static file');

        // A PHP path with nothing behind it: NGINX answers 502 itself and shows the static gateway page
        [$status, $headers, $body] = $this->request($port, '/login');
        $this->assertSame(502, $status);
        $this->assertStringContainsString('The system is temporarily unavailable', $body);
        $this->assertSecurityHeadersOnce($headers, 'gateway page (502)');

        // The gateway page cannot be fetched directly (internal), and NGINX's own 404/403 carry the headers too
        [$status, $headers] = $this->request($port, '/errors/gateway.html');
        $this->assertSame(404, $status);
        $this->assertSecurityHeadersOnce($headers, 'NGINX 404');
        [$status, $headers] = $this->request($port, '/.env');
        $this->assertSame(403, $status);
        $this->assertSecurityHeadersOnce($headers, 'NGINX 403');
    }
}
