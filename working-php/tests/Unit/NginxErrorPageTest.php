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

    // The page satisfies the enforced Content Security Policy (NGINX adds it to this page): the inline <style> block is
    // allowed by style-src 'unsafe-inline', and there is no script, event handler, other-origin resource, frame or form
    public function test_gateway_page_satisfies_the_content_security_policy(): void
    {
        $html = (string) file_get_contents(BASE_PATH . self::PAGE);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", \App\Common\Http\SecurityHeaders::POLICY);

        // No script of any kind, no event handler attribute, no javascript: URL
        $this->assertStringNotContainsString('<script', $html);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
        $this->assertStringNotContainsString('javascript:', $html);

        // Nothing frames, embeds or posts anywhere; every URL it loads or follows is on this origin
        $this->assertDoesNotMatchRegularExpression('/<(iframe|embed|object|form|base)\b/i', $html);
        preg_match_all('/\b(?:href|src)="([^"]*)"/', $html, $urls);
        foreach ($urls[1] as $url) {
            $this->assertDoesNotMatchRegularExpression('/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i', $url, "URL {$url} leaves this origin");
        }
        $this->assertDoesNotMatchRegularExpression('/url\(\s*[\'"]?(?:https?:|\/\/)/i', $html, 'inline styles load nothing from another origin');
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
