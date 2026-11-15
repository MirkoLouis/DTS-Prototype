<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * NGINX answers 502, 503 and 504 itself when PHP-FPM cannot be reached; the static page and the NGINX
 * configuration that serves it are checked here as files. The live NGINX behaviour is not exercised
 * (the suite runs against PHP's built-in server).
 */
class NginxErrorPageTest extends TestCase
{
    private const PAGE = '/public/errors/gateway.html';

    // The page says what happened and what to do, offers a reload, and needs no JavaScript
    public function test_gateway_page_has_the_wording_and_a_reload_control(): void
    {
        $this->assertFileExists(BASE_PATH . self::PAGE);
        $html = (string) file_get_contents(BASE_PATH . self::PAGE);

        $this->assertStringContainsString('The system is temporarily unavailable', $html);
        $this->assertStringContainsString('Wait a moment and reload this page', $html);
        $this->assertStringContainsString('contact the administrator', $html);
        $this->assertMatchesRegularExpression('/<a href="" [^>]*>Reload<\/a>/', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    // The page is readable without the stylesheet (own inline styles, light and dark) and only uses Tailwind classes that exist
    public function test_gateway_page_styles_are_self_contained_and_use_existing_classes(): void
    {
        $html = (string) file_get_contents(BASE_PATH . self::PAGE);
        $this->assertStringContainsString('prefers-color-scheme: dark', $html);

        $css = (string) file_get_contents(BASE_PATH . '/public/css/tailwind.css');
        preg_match_all('/class="([^"]+)"/', $html, $matches);
        foreach (array_unique(preg_split('/\s+/', implode(' ', $matches[1]))) as $class) {
            $this->assertStringContainsString('.' . $class, $css, "Tailwind class {$class} is not in public/css/tailwind.css");
        }
    }

    // Every NGINX configuration routes 502, 503 and 504 to the page and keeps that location internal
    public function test_every_nginx_config_routes_gateway_errors_to_the_page(): void
    {
        $configs = glob(BASE_PATH . '/nginx/*.conf') ?: [];
        $this->assertNotSame([], $configs);

        foreach ($configs as $config) {
            $text = (string) file_get_contents($config);
            $this->assertMatchesRegularExpression('/^\s*error_page\s+502\s+503\s+504\s+\/errors\/gateway\.html;/m', $text, basename($config));
            $this->assertMatchesRegularExpression('/location\s+=\s+\/errors\/gateway\.html\s*\{[^}]*\binternal;/s', $text, basename($config));
        }
    }
}
