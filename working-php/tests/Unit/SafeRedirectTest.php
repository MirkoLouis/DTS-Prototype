<?php

namespace Tests\Unit;

use App\Common\Http\SafeRedirect;
use PHPUnit\Framework\TestCase;

/**
 * Redirect targets that come from a request header or are passed on by a script are reduced to a local
 * path (and query), or refused: never another host.
 */
class SafeRedirectTest extends TestCase
{
    private const HOST = 'dts.example:8000';

    // Targets that are, or point to, this site become path + query only
    public function test_local_targets_become_path_and_query(): void
    {
        $cases = [
            '/users' => '/users',
            '/a?b=1' => '/a?b=1',
            '/a?b=1#fragment' => '/a?b=1',
            'https://dts.example:8000/x?y=1' => '/x?y=1',
            'HTTPS://DTS.EXAMPLE:8000/x' => '/x',
            'http://dts.example:8000' => '/',
        ];

        foreach ($cases as $target => $expected) {
            $this->assertSame($expected, SafeRedirect::localPath($target, self::HOST), $target);
        }
    }

    // Anything that could lead to another host, or is malformed, is refused
    public function test_foreign_or_malformed_targets_are_refused(): void
    {
        $refused = [
            'https://evil.example/x',
            'https://dts.example/x',
            'https://dts.example:9000/x',
            'https://user@dts.example:8000/x',
            'https://dts.example:8000//evil.example',
            '//evil.example',
            '/\\evil',
            "/\t/evil.example",
            "/a b",
            "/a\nb",
            'javascript:alert(1)',
            'users',
            '',
            null,
        ];

        foreach ($refused as $target) {
            $this->assertNull(SafeRedirect::localPath($target, self::HOST), json_encode($target));
        }
    }

    // Without an explicit host the current request's host is used
    public function test_current_request_host_is_the_default(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost:8000';

        $this->assertSame('/x', SafeRedirect::localPath('http://localhost:8000/x'));
        $this->assertNull(SafeRedirect::localPath('http://other:8000/x'));
    }

    // A Referer is used only when it is usable, otherwise the fallback
    public function test_referer_or_falls_back(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost:8000';

        $_SERVER['HTTP_REFERER'] = 'http://localhost:8000/documents/X?tab=1';
        $this->assertSame('/documents/X?tab=1', SafeRedirect::refererOr('/tasks'));

        $_SERVER['HTTP_REFERER'] = 'https://evil.example/x';
        $this->assertSame('/tasks', SafeRedirect::refererOr('/tasks'));

        unset($_SERVER['HTTP_REFERER']);
        $this->assertSame('/tasks', SafeRedirect::refererOr('/tasks'));
    }

    // The notification link check shares the path rules: only plain relative paths pass
    public function test_relative_path_check(): void
    {
        $this->assertTrue(SafeRedirect::isRelativePath('/account/password'));
        foreach (['https://x', '//x', "/\t/x", '/\\x', '/a b', 'x', ''] as $path) {
            $this->assertFalse(SafeRedirect::isRelativePath($path), json_encode($path));
        }
    }
}
