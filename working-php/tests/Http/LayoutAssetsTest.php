<?php

namespace Tests\Http;

use App\Common\Database\Database;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\Support\TestDatabase;
use Tests\TestCase;

class LayoutAssetsTest extends TestCase
{
    // The app layout must not reference the removed client-side libsodium
    public function test_app_layout_does_not_load_libsodium(): void
    {
        $client = HttpClient::start();
        $client->loginAs('admin');
        $response = $client->get('/admin-dashboard');

        $this->assertSame(200, $response['status']);
        $this->assertStringNotContainsString('libsodium', $response['body']);
    }

    // Intake finalization (server-side signing) works without any browser crypto
    public function test_signing_still_works_without_client_crypto(): void
    {
        $submitted = Fixtures::submit();
        $client = HttpClient::start();
        $client->loginAs('records');
        $client->get('/intake');

        // Finalize with a route and the PIN
        $response = $client->post("/documents/{$submitted['id']}/finalize", [
            'final_route' => json_encode(['Cash Unit']),
            'pin' => TestDatabase::PIN,
        ]);
        $this->assertSame(302, $response['status']);
        $this->assertSame('/intake', $response['headers']['location']);

        // The newest log entry is the finalization
        $row = Database::getInstance()->query(
            'SELECT action FROM document_logs WHERE document_id = :id ORDER BY id DESC LIMIT 1',
            [':id' => $submitted['id']]
        )->fetch();
        $this->assertSame('Accepted and Document Routing finalized', $row['action']);
    }

    // Parse a page body into an XPath query object
    private function xpathFor(string $html): DOMXPath
    {
        $document = new DOMDocument();
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }

    // The links of one navigation container as [href => isActive]
    private function navLinks(DOMXPath $xpath, string $containerId): array
    {
        $links = [];
        foreach ($xpath->query("//*[@id='{$containerId}']//a") as $anchor) {
            // The active link carries the dark text class in both the desktop bar and the mobile menu
            $links[$anchor->getAttribute('href')] = str_contains($anchor->getAttribute('class'), 'text-gray-900');
        }

        return $links;
    }

    // Role => the path whose link must be active and the links expected in the navigation
    public static function navigationProvider(): array
    {
        return [
            'admin' => ['admin', '/admin-dashboard', ['/admin-dashboard', '/all-documents', '/system-overview', '/users']],
            'officer' => ['records', '/intake', ['/intake', '/tasks', '/tasks/completed', '/releasing', '/statistics']],
            'staff' => ['cash', '/tasks', ['/tasks', '/tasks/completed', '/statistics']],
        ];
    }

    // The menu button controls an existing, initially closed menu element
    public function test_app_layout_has_a_menu_button_pointing_at_the_mobile_menu(): void
    {
        $client = HttpClient::start();
        $client->loginAs('admin');
        $xpath = $this->xpathFor($client->get('/admin-dashboard')['body']);

        $button = $xpath->query("//button[@id='mobile-menu-button']")->item(0);
        $this->assertNotNull($button, 'The menu button is missing.');
        $this->assertSame('false', $button->getAttribute('aria-expanded'));
        $this->assertNotSame('', trim($xpath->query(".//*[contains(@class, 'sr-only')]", $button)->item(0)?->textContent ?? ''), 'The menu button needs a visually hidden label.');

        // aria-controls must name an element that exists, and it starts hidden
        $target = $xpath->query("//*[@id='" . $button->getAttribute('aria-controls') . "']")->item(0);
        $this->assertNotNull($target, 'aria-controls does not point at an existing element.');
        $this->assertStringContainsString('hidden', $target->getAttribute('class'));
    }

    // The mobile menu lists the same links as the desktop bar and marks the same one active
    #[DataProvider('navigationProvider')]
    public function test_mobile_menu_matches_the_desktop_bar_for_each_role(string $username, string $activePath, array $expectedLinks): void
    {
        $client = HttpClient::start();
        $client->loginAs($username);
        $xpath = $this->xpathFor($client->get($activePath)['body']);

        $desktop = $this->navLinks($xpath, 'pjax-nav-links');
        // The mobile container also holds the account links; the change-password entry is checked in its own test
        $mobile = $this->navLinks($xpath, 'pjax-mobile-nav-links');
        unset($mobile[\App\Modules\Auth\CredentialPolicy::CHANGE_PASSWORD_PATH]);

        $this->assertSame($expectedLinks, array_keys($desktop));
        $this->assertSame($desktop, $mobile, 'Mobile menu links or active state differ from the desktop bar.');
        $this->assertSame([$activePath], array_keys(array_filter($mobile)), 'Exactly the current page must be active.');
    }

    // The change-password entry sits inside the container the router swaps, and is active only on its own page
    public function test_change_password_entry_is_swapped_with_the_menu_and_active_only_on_its_page(): void
    {
        $path = \App\Modules\Auth\CredentialPolicy::CHANGE_PASSWORD_PATH;
        $client = HttpClient::start();
        $client->loginAs('records');

        foreach (['/intake' => false, $path => true] as $page => $expectedActive) {
            $xpath = $this->xpathFor($client->get($page)['body']);
            $link = $xpath->query("//*[@id='pjax-mobile-nav-links']//a[@href='{$path}']")->item(0);
            $this->assertNotNull($link, "Change password is not inside #pjax-mobile-nav-links on {$page}.");
            $this->assertSame($expectedActive, str_contains($link->getAttribute('class'), 'text-gray-900'), "Active state wrong on {$page}.");

            // The logout form is swapped too and carries the session's CSRF token, the same one as the page's meta tag
            $token = $xpath->query("//*[@id='pjax-mobile-nav-links']//form[@action='/logout']/input[@name='csrf_token']/@value")->item(0)?->nodeValue;
            $meta = $xpath->query("//meta[@name='csrf-token']/@content")->item(0)?->nodeValue;
            $this->assertNotEmpty($token);
            $this->assertSame($meta, $token);
        }
    }

    // The shared table component keeps a wide table inside its own horizontally scrollable container
    public function test_table_component_wraps_the_table_in_a_scrollable_container(): void
    {
        // Render the component with and without caller-supplied wrapper classes
        foreach ([[], ['wrapper_classes' => 'mt-4']] as $extra) {
            $tableConfig = $extra + ['columns' => [['label' => 'A', 'key' => 'a']], 'data' => [['a' => 'x']]];
            ob_start();
            require BASE_PATH . '/src/Views/components/table.php';
            $xpath = $this->xpathFor((string) ob_get_clean());

            $wrapper = $xpath->query('//table')->item(0)->parentNode;
            $this->assertStringContainsString('overflow-x-auto', $wrapper->getAttribute('class'));
        }
    }

    // Fixed side margins may only apply on wide screens
    public function test_no_view_uses_an_unconditional_viewport_margin(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BASE_PATH . '/src/Views'));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            // A token like "mx-[20vh]" not preceded by a breakpoint prefix such as "xl:"
            if (preg_match('/(?<![\w:-])mx-\[20vh\]/', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getPathname();
            }
        }
        $this->assertSame([], $offenders, 'Unconditional mx-[20vh] found.');
    }

    // Every responsive class (sm: md: lg: xl: 2xl:) written in a view's class="..." attribute exists in the built stylesheet
    public function test_responsive_classes_in_views_exist_in_the_built_css(): void
    {
        $css = (string) file_get_contents(BASE_PATH . '/public/css/tailwind.css');

        // Collect the tokens of every class attribute under src/Views
        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BASE_PATH . '/src/Views'));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/class="([^"]*)"/', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $attribute) {
                // Drop PHP interpolation, then keep only tokens that are plain class names with a responsive prefix
                $attribute = preg_replace('/<\?.*?\?>/s', ' ', $attribute);
                foreach (preg_split('/\s+/', trim($attribute)) as $token) {
                    if (preg_match('/^(sm|md|lg|xl|2xl):[A-Za-z0-9_:\-\[\]\.\/%]+$/', $token)) {
                        $classes[$token] = $file->getPathname();
                    }
                }
            }
        }
        $this->assertNotEmpty($classes, 'No responsive classes found in the views.');

        // Tailwind escapes every character that is not a letter, digit, "_" or "-" in a selector
        $missing = [];
        foreach ($classes as $class => $path) {
            $escaped = preg_replace('/([^A-Za-z0-9_-])/', '\\\\$1', $class);
            // A leading digit (the 2xl: prefix) is written as a hex escape: ".\32xl\:px-4"
            if (ctype_digit($class[0])) {
                $escaped = '\\3' . $class[0] . substr($escaped, 1);
            }
            if (!str_contains($css, '.' . $escaped)) {
                $missing[] = "{$class} ({$path})";
            }
        }
        $this->assertSame([], $missing, 'Responsive classes missing from public/css/tailwind.css (run npm run build).');
    }
}
