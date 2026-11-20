<?php

namespace Tests\Http;

use App\Common\Database\Database;
use DOMDocument;
use DOMXPath;
use Tests\Support\HttpClient;
use Tests\Support\RenderedPages;
use Tests\TestCase;

/**
 * After S9a no rendered page contains executable inline JavaScript, so a later version can enforce
 * `script-src 'self'`: every script is a file of this origin, server data travels as inert JSON blocks or data attributes.
 */
class NoInlineScriptTest extends TestCase
{
    // Rendered pages of every role, the guest pages, the login page and an error page
    private function pages(): array
    {
        return RenderedPages::all($this->fx);
    }

    // Parse a page body into a DOM document
    private function documentFor(string $html): DOMDocument
    {
        $document = new DOMDocument();
        @$document->loadHTML($html);

        return $document;
    }

    // Source files that produce pages or run in them: [absolute path, ...] (libraries are third-party and excluded)
    private function ownSourceFiles(): array
    {
        $files = [];
        foreach ([BASE_PATH . '/src/Views', BASE_PATH . '/public/js'] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = $file->getPathname();
                if (preg_match('/\.(php|js)$/', $path) && !str_ends_with($path, '.min.js')) {
                    $files[] = $path;
                }
            }
        }

        return $files;
    }

    // Only script elements with a src, or inert JSON data blocks, exist; none carries inline code
    public function test_no_rendered_page_has_an_executable_inline_script(): void
    {
        $failures = [];
        foreach ($this->pages() as $name => $html) {
            foreach ($this->documentFor($html)->getElementsByTagName('script') as $script) {
                $isDataBlock = strtolower($script->getAttribute('type')) === 'application/json';
                if ($script->hasAttribute('src') && trim($script->textContent) === '') {
                    continue;
                }
                if ($isDataBlock && !$script->hasAttribute('src')) {
                    continue;
                }
                $failures[] = "{$name}: " . substr(trim($script->textContent) ?: $script->getAttribute('src'), 0, 60);
            }
        }

        $this->assertSame([], $failures);
    }

    // No element has an event handler attribute (onclick, onchange, onsubmit, ...)
    public function test_no_rendered_page_has_an_event_handler_attribute(): void
    {
        $failures = [];
        foreach ($this->pages() as $name => $html) {
            foreach ((new DOMXPath($this->documentFor($html)))->query('//@*') as $attribute) {
                if (str_starts_with(strtolower($attribute->name), 'on')) {
                    $failures[] = "{$name}: {$attribute->name} on <{$attribute->ownerElement->tagName}>";
                }
            }
        }

        $this->assertSame([], $failures);
    }

    // No attribute holds a javascript: URL
    public function test_no_rendered_page_has_a_javascript_url(): void
    {
        $failures = [];
        foreach ($this->pages() as $name => $html) {
            foreach ((new DOMXPath($this->documentFor($html)))->query('//@*') as $attribute) {
                if (preg_match('/^[\s\x00-\x20]*javascript:/i', $attribute->value)) {
                    $failures[] = "{$name}: {$attribute->name}={$attribute->value}";
                }
            }
        }

        $this->assertSame([], $failures);
    }

    // Every script and stylesheet is a local file that exists under public/; nothing is loaded from another origin
    public function test_scripts_and_stylesheets_are_local_files_that_exist(): void
    {
        $failures = [];
        $scriptCount = 0;
        foreach ($this->pages() as $name => $html) {
            $xpath = new DOMXPath($this->documentFor($html));
            $references = [];
            foreach ($xpath->query('//script[@src]') as $script) {
                $references[] = $script->getAttribute('src');
                $scriptCount++;
            }
            foreach ($xpath->query('//link[@rel="stylesheet" or @rel="preload" or @rel="modulepreload"]') as $link) {
                $references[] = $link->getAttribute('href');
            }

            foreach ($references as $reference) {
                // Same-origin absolute path only ("/js/x.js"): no scheme, no "//host", no relative path
                if (!preg_match('#^/(?!/)[^\s\\\\]*$#', $reference)) {
                    $failures[] = "{$name}: not a local absolute path: {$reference}";
                    continue;
                }
                $path = BASE_PATH . '/public' . parse_url($reference, PHP_URL_PATH);
                if (!is_file($path) || !str_starts_with(realpath($path), realpath(BASE_PATH . '/public') . '/')) {
                    $failures[] = "{$name}: missing file {$reference}";
                }
            }
        }

        $this->assertSame([], $failures);
        $this->assertGreaterThan(50, $scriptCount, 'the pages load their scripts as files');
    }

    // A script tag inside #pjax-content would run on a hard load but never after in-app navigation (the router never runs scripts)
    public function test_no_script_file_is_referenced_from_inside_the_swapped_content(): void
    {
        $failures = [];
        foreach ($this->pages() as $name => $html) {
            $content = $this->documentFor($html)->getElementById('pjax-content');
            if ($content === null) {
                continue;
            }
            foreach ($content->getElementsByTagName('script') as $script) {
                $failures[] = "{$name}: <script " . ($script->getAttribute('src') ?: $script->getAttribute('type')) . '> inside #pjax-content';
            }
        }

        $this->assertSame([], $failures);
    }

    // Server data reaches scripts through ONE helper: no hand-written JSON block and no json_encode inside a script tag in a view
    public function test_views_use_the_json_helper_for_data_blocks(): void
    {
        $offenders = [];
        foreach ($this->ownSourceFiles() as $path) {
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            $source = (string) file_get_contents($path);
            if (preg_match('/type=["\']application\/json["\']/i', $source)) {
                $offenders[] = 'hand-written JSON block: ' . str_replace(BASE_PATH . '/', '', $path);
            }
            if (preg_match('/<script\b(?:(?!<\/script>).)*?json_encode/is', $source)) {
                $offenders[] = 'json_encode inside a script tag: ' . str_replace(BASE_PATH . '/', '', $path);
            }
        }

        $this->assertSame([], $offenders);
    }

    // The data-dependent branches of the views are among the scanned pages (the seeded data really reached them)
    public function test_data_dependent_branches_are_rendered_and_scanned(): void
    {
        $pages = $this->pages();
        $expectations = [
            'backups' => ['restore-backup-trigger', 'data-restore-url="/system/backups/restore/odd+name%2B1.zip"'],
            'system-overview' => ['Example failure', 'data-copy-text', 'debug-log-btn', 'autoresolve'],
            'intake' => ['Unread info', 'mark-notification-read'],
            'admin-dashboard-toast' => ['toast-message'],
            'tasks-refused-action' => ['toast-message', 'This document is still pending intake'],
            'tasks-pin-token' => ['id="dts-pin-token"'],
            'security-pin-prompt' => ['id="security-key-modal"'],
            'guest-success' => ['QR Code for'],
            'guest-track-declined' => ['document-card'],
            'admin-document-declined' => ['Document declined'],
            'manage-documents-not-pending' => ['Management Unauthorized'],
        ];

        $missing = [];
        foreach ($expectations as $name => $needles) {
            foreach ($needles as $needle) {
                if (!isset($pages[$name]) || !str_contains($pages[$name], $needle)) {
                    $missing[] = "{$name}: {$needle}";
                }
            }
        }
        $this->assertSame([], $missing);
    }

    // The tracking form template (rendered to HTML, then turned into a PDF) has no script, handler or javascript: URL either
    public function test_tracking_form_template_has_no_executable_content(): void
    {
        $document = \Tests\Support\Fixtures::submit(['title' => '<script>alert(1)</script> title']);
        $row = Database::getInstance()->query('SELECT d.*, p.name AS purpose_name FROM documents d LEFT JOIN purposes p ON d.purpose_id = p.id WHERE d.id = :id', [':id' => $document['id']])->fetch();

        // The variables the controller prepares before it includes the template
        $_SERVER['HTTP_HOST'] = '127.0.0.1';
        $_SERVER['SERVER_PORT'] = 80;
        $variables = ['document' => $row, 'qrCodeBase64' => ''];
        $render = static function (array $variables): string {
            extract($variables);
            ob_start();
            require BASE_PATH . '/src/Views/general/tracking-form-pdf.php';

            return (string) ob_get_clean();
        };
        $html = $render($variables);

        $this->assertStringContainsString('Document Tracking Form', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $dom = $this->documentFor($html);
        $this->assertSame(0, $dom->getElementsByTagName('script')->count());
        foreach ((new DOMXPath($dom))->query('//@*') as $attribute) {
            $this->assertFalse(str_starts_with(strtolower($attribute->name), 'on'), $attribute->name);
            $this->assertDoesNotMatchRegularExpression('/^\s*javascript:/i', $attribute->value);
        }
    }

    // A script file is referenced once per page (a second tag would run it twice and bind its listeners twice)
    public function test_no_page_loads_a_script_file_twice(): void
    {
        $failures = [];
        foreach ($this->pages() as $name => $html) {
            preg_match_all('/<script\s+src="([^"]+)"/', $html, $matches);
            foreach (array_keys(array_filter(array_count_values($matches[1]), fn(int $count) => $count > 1)) as $source) {
                $failures[] = "{$name}: {$source}";
            }
        }

        $this->assertSame([], $failures);
    }

    // The views and scripts contain nothing that would create an inline handler, a javascript: URL or evaluated code at runtime
    public function test_sources_do_not_create_inline_script_at_runtime(): void
    {
        $patterns = [
            // A handler attribute written inside a template string (HTML in PHP or JavaScript)
            'inline handler attribute' => '/\bon[a-z]{3,}\s*=\s*\\\\?["\']/i',
            'setAttribute of a handler' => '/setAttribute\(\s*[\'"]on/i',
            'javascript: URL' => '/javascript:/i',
            'inline script element' => '/<script\b(?![^>]*(\bsrc=|type="application\/json"))/i',
            'eval' => '/\beval\s*\(/',
            'Function constructor' => '/(?<![\w$.])(new\s+)?Function\s*\(/',
            'unquoted handler attribute' => '/<[a-z][^>]*\son[a-z]{3,}\s*=\s*[^\s"\'=>]/i',
            'srcdoc' => '/\bsrcdoc\b/i',
            'string passed to a timer' => '/\bset(Timeout|Interval)\s*\(\s*([\'"`]|[\w$.]+\s*\+)/',
            'string-typed timer argument (indirect eval)' => '/\(\s*0\s*,\s*eval\s*\)/',
            'script element created at runtime' => '/createElement\(\s*[\'"]script[\'"]/i',
            'document.write' => '/document\.write(ln)?\s*\(/',
        ];

        $offenders = [];
        foreach ($this->ownSourceFiles() as $path) {
            $source = (string) file_get_contents($path);
            foreach ($patterns as $label => $pattern) {
                if (!preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                foreach ($matches[0] as [, $offset]) {
                    $offenders[] = "{$label}: " . str_replace(BASE_PATH . '/', '', $path) . ':' . (substr_count(substr($source, 0, $offset), "\n") + 1);
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    // Text in a JSON data block cannot break out of it: a hostile purpose requirement stays data on the guest welcome page
    public function test_hostile_text_in_a_json_block_cannot_break_out(): void
    {
        $requirements = ['</script><script>alert(1)</script>', '<!-- hostile', 'say "hi" & \'bye\' </SCRIPT >'];
        Database::getInstance()->query(
            "INSERT INTO purposes (name, is_official, requirements, suggested_route, created_at, updated_at) VALUES ('Hostile purpose', 1, :r, '[]', NOW(), NOW())",
            [':r' => json_encode($requirements)]
        );
        $purposeId = (string) Database::getInstance()->query("SELECT id FROM purposes WHERE name = 'Hostile purpose'")->fetch()['id'];

        $body = HttpClient::start()->get('/')['body'];

        // Neither the closing tag nor the comment start of the data appears in the markup
        $this->assertStringNotContainsString('</script><script>alert(1)', $body);
        $this->assertStringNotContainsString('<script>alert(1)', $body);
        $this->assertStringNotContainsString('<!-- hostile', $body);
        $this->assertStringNotContainsStringIgnoringCase('</SCRIPT >', $body);

        // The DOM has no script that runs the data; the data block parses back to exactly the requirements
        $document = $this->documentFor($body);
        foreach ($document->getElementsByTagName('script') as $script) {
            $this->assertTrue($script->hasAttribute('src') || $script->getAttribute('type') === 'application/json', 'unexpected executable script');
        }
        $block = $document->getElementById('purpose-requirements');
        $this->assertNotNull($block);
        $this->assertSame('application/json', $block->getAttribute('type'));
        $this->assertSame($requirements, json_decode($block->textContent, true)[$purposeId]);
    }
}
