<?php

namespace Tests\Http;

use App\Common\Database\Database;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Tests\Support\HttpClient;
use Tests\Support\RenderedPages;
use Tests\TestCase;

/**
 * Accessibility of the rendered HTML (the keyboard behaviour is checked in a browser by
 * scripts/manual/accessibility-check.mjs): headings, dialog semantics, accessible names, labels,
 * autocomplete hints, live regions and focus indication.
 */
class AccessibilityMarkupTest extends TestCase
{
    // Fetch the main pages of every role and the guest pages (built once per run, shared with NoInlineScriptTest)
    private function renderedPages(): array
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

    // Whether a node sits inside an element matching the attribute test (an ancestor, or the node itself)
    private function insideElement(DOMNode $node, callable $matches): bool
    {
        for ($current = $node; $current instanceof DOMElement; $current = $current->parentNode) {
            if ($matches($current)) {
                return true;
            }
        }

        return false;
    }

    // Text a node contributes to an accessible name: skips aria-hidden subtrees, uses img alt
    private function nameTextOf(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return $node->textContent;
        }
        if (!$node instanceof DOMElement || $node->getAttribute('aria-hidden') === 'true') {
            return '';
        }
        if ($node->tagName === 'img') {
            return $node->getAttribute('alt');
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= ' ' . $this->nameTextOf($child);
        }

        return $text;
    }

    // The text an aria-labelledby attribute resolves to (empty when an id does not exist)
    private function labelledByText(DOMDocument $document, DOMElement $element): string
    {
        $text = '';
        foreach (preg_split('/\s+/', trim($element->getAttribute('aria-labelledby'))) as $id) {
            $target = $id === '' ? null : $document->getElementById($id);
            $text .= $target ? ' ' . $this->nameTextOf($target) : '';
        }

        return trim($text);
    }

    // Accessible name from aria-labelledby, aria-label, content (or value of a button input), then title
    private function accessibleName(DOMDocument $document, DOMElement $element): string
    {
        $labelledBy = $this->labelledByText($document, $element);
        if ($labelledBy !== '') {
            return $labelledBy;
        }
        if (trim($element->getAttribute('aria-label')) !== '') {
            return trim($element->getAttribute('aria-label'));
        }
        if ($element->tagName === 'input') {
            return trim($element->getAttribute('value'));
        }
        $content = trim(preg_replace('/\s+/', ' ', $this->nameTextOf($element)));

        return $content !== '' ? $content : trim($element->getAttribute('title'));
    }

    // Whether the element is hidden from assistive technology through an aria-hidden ancestor
    private function isAriaHidden(DOMElement $element): bool
    {
        return $this->insideElement($element, fn(DOMElement $node) => $node->getAttribute('aria-hidden') === 'true');
    }

    // Headings of a scope as levels in document order; the page scope leaves out the headings inside dialogs
    private function headingLevels(DOMXPath $xpath, ?DOMElement $dialog): array
    {
        $levels = [];
        foreach ($xpath->query('.//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]', $dialog) as $heading) {
            if ($dialog === null && $this->insideElement($heading, fn(DOMElement $node) => $node->getAttribute('role') === 'dialog')) {
                continue;
            }
            $levels[] = (int) $heading->tagName[1];
        }

        return $levels;
    }

    // Problems in a heading sequence: it may rise by at most one level at a time
    private function skippedLevels(array $levels, int $firstAllowed): array
    {
        $problems = [];
        $previous = 0;
        foreach ($levels as $level) {
            $limit = $previous > 0 ? $previous + 1 : $firstAllowed;
            if ($level > $limit) {
                $problems[] = $previous > 0 ? "h{$previous} -> h{$level}" : "starts at h{$level}";
            }
            $previous = $level;
        }

        return $problems;
    }

    // Exactly one h1 per page, no skipped level below it; dialogs start at h2 and do not skip either
    public function test_every_page_has_one_h1_and_no_skipped_heading_level(): void
    {
        $failures = [];
        foreach ($this->renderedPages() as $name => $html) {
            $document = $this->documentFor($html);
            $xpath = new DOMXPath($document);

            $h1Count = $xpath->query('//h1')->length;
            if ($h1Count !== 1) {
                $failures[] = "{$name}: {$h1Count} h1 elements";
            }
            foreach ($this->skippedLevels($this->headingLevels($xpath, null), 2) as $problem) {
                $failures[] = "{$name}: page heading {$problem}";
            }
            foreach ($xpath->query('//*[@role="dialog"]') as $dialog) {
                $levels = $this->headingLevels($xpath, $dialog);
                if (in_array(1, $levels, true)) {
                    $failures[] = "{$name}: dialog #{$dialog->getAttribute('id')} contains an h1";
                }
                foreach ($this->skippedLevels($levels, 2) as $problem) {
                    $failures[] = "{$name}: dialog #{$dialog->getAttribute('id')} heading {$problem}";
                }
            }
        }

        $this->assertSame([], $failures);
    }

    // The h1 is the header bar title in the app layout, the system name in the guest layout, the status title on the error page
    public function test_the_h1_is_the_page_title_of_each_layout(): void
    {
        $pages = $this->renderedPages();
        $h1Of = fn(string $name) => trim((new DOMXPath($this->documentFor($pages[$name])))->query('//h1')->item(0)->textContent);

        $this->assertSame('Document Tracking System', $h1Of('guest-welcome'));
        $this->assertSame('User Management', $h1Of('users-index'));
        $this->assertSame('Not Found', $h1Of('error-404'));
        // The h1 of an app page sits in the header bar the router swaps
        $xpath = new DOMXPath($this->documentFor($pages['users-index']));
        $this->assertSame(1, $xpath->query('//header[@id="pjax-header"]//h1')->length);
    }

    // Every dialog says it is modal and has a label that resolves to existing text
    public function test_every_dialog_is_modal_and_labelled(): void
    {
        $failures = [];
        $dialogCount = 0;
        foreach ($this->renderedPages() as $name => $html) {
            $document = $this->documentFor($html);
            foreach ((new DOMXPath($document))->query('//*[@role="dialog"]') as $dialog) {
                $dialogCount++;
                $label = "{$name}: #{$dialog->getAttribute('id')}";
                if ($dialog->getAttribute('aria-modal') !== 'true') {
                    $failures[] = "{$label} lacks aria-modal=\"true\"";
                }
                $hasAriaLabel = trim($dialog->getAttribute('aria-label')) !== '';
                if (!$hasAriaLabel && $this->labelledByText($document, $dialog) === '') {
                    $failures[] = "{$label} has no label that resolves to an element with text";
                }
            }
        }

        $this->assertGreaterThan(10, $dialogCount, 'Too few dialogs found; the pages did not render their modals.');
        $this->assertSame([], $failures);
    }

    // Every element that looks like a modal (a fixed full-screen wrapper opened by script) is exposed as a dialog
    public function test_every_modal_wrapper_has_the_dialog_role(): void
    {
        $failures = [];
        foreach ($this->renderedPages() as $name => $html) {
            foreach ((new DOMXPath($this->documentFor($html)))->query('//*[@id][contains(concat(" ", normalize-space(@class), " "), " hidden ")][contains(@class, "fixed") or contains(@class, "qr-modal")]') as $wrapper) {
                if ($wrapper->getAttribute('role') !== 'dialog' && str_contains($wrapper->getAttribute('class'), 'inset-0')) {
                    $failures[] = "{$name}: #{$wrapper->getAttribute('id')}";
                }
            }
        }

        $this->assertSame([], $failures);
    }

    // Every button and link has an accessible name
    public function test_every_button_and_link_has_an_accessible_name(): void
    {
        $failures = [];
        foreach ($this->renderedPages() as $name => $html) {
            $document = $this->documentFor($html);
            foreach ((new DOMXPath($document))->query('//button | //a[@href] | //*[@role="button"] | //input[@type="submit" or @type="button"]') as $control) {
                // A name made only of symbols (×, «, », …, +) does not count: it needs at least one letter or digit
                if ($this->isAriaHidden($control) || preg_match('/[\p{L}\p{N}]/u', $this->accessibleName($document, $control)) === 1) {
                    continue;
                }
                $failures[] = "{$name}: <{$control->tagName} id=\"{$control->getAttribute('id')}\" class=\"" . substr($control->getAttribute('class'), 0, 40) . '">';
            }
        }

        $this->assertSame([], $failures);
    }

    // Decorative SVGs inside named controls are hidden from assistive technology
    public function test_icons_inside_buttons_and_links_are_aria_hidden(): void
    {
        $failures = [];
        foreach ($this->renderedPages() as $name => $html) {
            $document = $this->documentFor($html);
            foreach ((new DOMXPath($document))->query('//button//svg | //a[@href]//svg') as $icon) {
                $control = $icon->parentNode;
                while ($control instanceof DOMElement && !in_array($control->tagName, ['button', 'a'], true)) {
                    $control = $control->parentNode;
                }
                // An icon is fine when it, or an ancestor, is hidden; or when the control has no other name than the icon's title
                if (!$this->isAriaHidden($icon)) {
                    $failures[] = "{$name}: svg in <{$control->tagName} id=\"{$control->getAttribute('id')}\">";
                }
            }
        }

        $this->assertSame([], $failures);
    }

    // Every input, select and textarea (except hidden ones) has a programmatically associated label
    public function test_every_form_control_has_a_label(): void
    {
        $failures = [];
        foreach ($this->renderedPages() as $name => $html) {
            $document = $this->documentFor($html);
            $xpath = new DOMXPath($document);
            foreach ($xpath->query('//input[not(@type="hidden" or @type="submit" or @type="button" or @type="reset" or @type="image")] | //select | //textarea') as $field) {
                $labelled = trim($field->getAttribute('aria-label')) !== '' || $this->labelledByText($document, $field) !== '';
                $id = $field->getAttribute('id');
                // A <label for> pointing at the field, or a label wrapped around it
                if (!$labelled && $id !== '') {
                    foreach ($xpath->query('//label[@for]') as $label) {
                        $labelled = $labelled || ($label->getAttribute('for') === $id && trim($this->nameTextOf($label)) !== '');
                    }
                }
                $labelled = $labelled || $this->insideElement($field, fn(DOMElement $node) => $node->tagName === 'label' && trim($this->nameTextOf($node)) !== '');
                if (!$labelled) {
                    $failures[] = "{$name}: <{$field->tagName} id=\"{$id}\" name=\"{$field->getAttribute('name')}\">";
                }
            }
        }

        $this->assertSame([], $failures);
    }

    // Autocomplete hints and input types of the guest submission form, the tracking form, login and the Security PIN fields
    public function test_forms_carry_autocomplete_and_input_hints(): void
    {
        $pages = $this->renderedPages();
        $attributeOf = function (string $page, string $selectorId, string $attribute) use ($pages): string {
            $field = (new DOMXPath($this->documentFor($pages[$page])))->query("//*[@id='{$selectorId}']")->item(0);
            $this->assertNotNull($field, "#{$selectorId} missing on {$page}");

            return $field->getAttribute($attribute);
        };

        // Guest submission form
        $this->assertSame('name', $attributeOf('guest-welcome', 'guest_name', 'autocomplete'));
        $this->assertSame('email', $attributeOf('guest-welcome', 'guest_email', 'autocomplete'));
        $this->assertSame('email', $attributeOf('guest-welcome', 'guest_email', 'type'));
        $this->assertSame('tel', $attributeOf('guest-welcome', 'guest_phone', 'autocomplete'));
        $this->assertSame('tel', $attributeOf('guest-welcome', 'guest_phone', 'type'));
        $this->assertSame('numeric', $attributeOf('guest-welcome', 'guest_phone', 'inputmode'));

        // Tracking forms (a tracking code is not personal data to remember)
        $this->assertSame('off', $attributeOf('guest-welcome', 'tracking_code_input', 'autocomplete'));
        $this->assertSame('off', $attributeOf('guest-track', 'tracking_code_input', 'autocomplete'));

        // Login
        $this->assertSame('username', $attributeOf('login', 'email', 'autocomplete'));
        $this->assertSame('current-password', $attributeOf('login', 'password', 'autocomplete'));

        // Password change
        $this->assertSame('current-password', $attributeOf('account-password', 'current_password', 'autocomplete'));
        $this->assertSame('new-password', $attributeOf('account-password', 'new_password', 'autocomplete'));

        // Security PIN: new-password when it is created, off when it is only typed to sign (it is not the sign-in password)
        $this->assertSame('new-password', $attributeOf('security-pin-prompt', 'pin', 'autocomplete'));
        $this->assertSame('new-password', $attributeOf('security-pin-prompt', 'pin_confirm', 'autocomplete'));
        $this->assertSame('off', $attributeOf('admin-document', 'signing-modal-pin', 'autocomplete'));
    }

    // The mandatory Security PIN prompt is marked non-dismissible; no other modal is
    public function test_only_the_mandatory_pin_prompt_is_non_dismissible(): void
    {
        $dismissibleFlags = [];
        foreach ($this->renderedPages() as $name => $html) {
            foreach ((new DOMXPath($this->documentFor($html)))->query('//*[@data-modal-dismissible="false"]') as $modal) {
                $dismissibleFlags[] = "{$name}: #{$modal->getAttribute('id')}";
            }
        }

        $this->assertSame(['security-pin-prompt: #security-key-modal'], $dismissibleFlags);
        $prompt = (new DOMXPath($this->documentFor($this->renderedPages()['security-pin-prompt'])))->query('//*[@id="security-key-modal"]')->item(0);
        $this->assertSame('dialog', $prompt->getAttribute('role'));
        $this->assertSame('true', $prompt->getAttribute('aria-modal'));
        $this->assertNotSame('', $prompt->getAttribute('aria-labelledby'));
    }

    // A refused Security PIN setup is shown inside the mandatory prompt (an alert linked to both fields), not only as a toast
    public function test_a_refused_pin_setup_is_shown_inside_the_prompt(): void
    {
        Database::getInstance()->query('UPDATE users SET public_key = NULL, private_key = NULL, security_key_set_at = NULL WHERE email = :e', [':e' => 'personnel']);
        $client = HttpClient::start();
        $client->loginAs('personnel');
        $client->get('/tasks');
        $client->post('/security-key', ['pin' => 'Long-Enough-Pin-1', 'pin_confirm' => 'Different-Pin-22']);
        $body = $client->get('/tasks')['body'];

        $document = $this->documentFor($body);
        $xpath = new DOMXPath($document);
        $alert = $xpath->query('//*[@id="security-key-modal"]//*[@role="alert"][contains(., "do not match")]')->item(0);
        $this->assertNotNull($alert, 'The refusal is not rendered inside the prompt.');
        $this->assertNotSame('', $alert->getAttribute('id'));
        foreach (['pin', 'pin_confirm'] as $fieldId) {
            $field = $document->getElementById($fieldId);
            $this->assertSame('true', $field->getAttribute('aria-invalid'));
            $this->assertStringContainsString($alert->getAttribute('id'), $field->getAttribute('aria-describedby'));
        }

        // The message is shown once: the next page view has no stale refusal
        $this->assertStringNotContainsString('pin-setup-error', $client->get('/tasks')['body']);
    }

    // The toast container is a live region that dialogs leave alone
    public function test_toast_container_is_marked_as_a_live_region_for_dialogs(): void
    {
        $xpath = new DOMXPath($this->documentFor($this->renderedPages()['users-index']));
        $this->assertSame(1, $xpath->query('//*[@id="toast-container"][@data-modal-live-region]')->length);
    }

    // No id appears twice in a rendered page
    public function test_no_page_has_duplicate_ids(): void
    {
        $failures = [];
        foreach ($this->renderedPages() as $name => $html) {
            preg_match_all('/<[a-zA-Z][^>]*?\sid="([^"]+)"/', $html, $matches);
            foreach (array_keys(array_filter(array_count_values($matches[1]), fn(int $count) => $count > 1)) as $id) {
                $failures[] = "{$name}: {$id}";
            }
        }

        $this->assertSame([], $failures);
    }

    // The shared dialog module is loaded once by each layout, before the deferred page scripts that use it
    public function test_both_layouts_load_the_shared_modal_module_in_the_head(): void
    {
        $pages = $this->renderedPages();
        foreach (['guest-welcome', 'users-index'] as $name) {
            $tag = '<script src="/js/modal-dialog.js"></script>';
            $this->assertSame(1, substr_count($pages[$name], $tag), "{$name} must load modal-dialog.js exactly once");
            $this->assertLessThan(strpos($pages[$name], '</head>'), strpos($pages[$name], $tag), "{$name} must load it in the head");
        }
        $this->assertFileExists(BASE_PATH . '/public/js/modal-dialog.js');
    }

    // Pages open and close their modals through the shared module, not by toggling classes themselves
    public function test_no_page_script_opens_or_closes_a_modal_by_hand(): void
    {
        $offenders = [];
        $files = array_merge(
            iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BASE_PATH . '/src/Views', \FilesystemIterator::SKIP_DOTS))),
            self::ownScriptFiles()
        );
        foreach ($files as $file) {
            $path = is_string($file) ? $file : $file->getPathname();
            if (str_ends_with($path, '.min.js') || str_ends_with($path, 'modal-dialog.js')) {
                continue;
            }
            // A modal id (or a variable named like a modal) next to a hidden-class or display toggle
            foreach (file($path) as $number => $line) {
                // Both quote styles: classList.add('hidden') and classList.add("hidden")
                if (preg_match('/[Mm]odal[A-Za-z]*(\.classList\.(add|remove|toggle)\([\'"]hidden[\'"]\)|\.style\.display\s*=)/', $line)
                    || preg_match('/getElementById\([\'"][A-Za-z-]*modal[A-Za-z-]*[\'"]\)\.(classList|style)/i', $line)) {
                    $offenders[] = str_replace(BASE_PATH . '/', '', $path) . ':' . ($number + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Open and close modals with ModalDialog.open() / ModalDialog.close().');
    }

    // Our script files (public/js and its folders), libraries excluded by the callers
    private static function ownScriptFiles(): array
    {
        return glob(BASE_PATH . '/public/js/{,*/}*.js', GLOB_BRACE);
    }

    // The confirmation is ONE delegated submit listener: per-form binding at load missed forms that arrive with in-app navigation
    // (the listener moved from the layout's inline script to public/js/layout/global-confirm.js)
    public function test_confirm_action_forms_are_handled_by_one_delegated_listener(): void
    {
        $script = (string) file_get_contents(BASE_PATH . '/public/js/layout/global-confirm.js');
        $this->assertStringNotContainsString("querySelectorAll('.confirm-action')", $script);
        $this->assertStringContainsString("document.addEventListener('submit'", $script);
        $this->assertSame(1, substr_count($script, "classList.contains('confirm-action')"));

        // The layout loads that file once, and nothing else binds to the forms
        $layout = (string) file_get_contents(BASE_PATH . '/src/Views/layouts/app.php');
        $this->assertSame(1, substr_count($layout, '<script src="/js/layout/global-confirm.js" defer></script>'));
        $this->assertStringNotContainsString('confirm-action', $layout);
    }

    // Polling pages stop their timers when the router is about to replace them (their own handler or the teardown the
    // page registry runs on that event), and the layout's Escape handlers ignore an Escape a dialog handled
    public function test_navigation_cleanup_and_escape_guard_are_wired(): void
    {
        $this->assertStringContainsString("'dts:before-swap'", (string) file_get_contents(BASE_PATH . '/public/js/pjax-router.js'));
        $this->assertStringContainsString("'dts:before-swap'", (string) file_get_contents(BASE_PATH . '/public/js/core/page-registry.js'), 'the registry runs the teardowns before a swap');
        // Page scripts do not listen to the event themselves (the registry is the only mechanism); that polling really stops
        // after in-app navigation is proved in a browser by scripts/manual/behavior-check.mjs
        foreach (glob(BASE_PATH . '/public/js/pages/*.js') as $script) {
            $this->assertStringNotContainsString('dts:before-swap', (string) file_get_contents($script), basename($script) . ' must return a teardown to DtsPage.onLoad instead');
        }

        $escapeGuards = 0;
        foreach (['navigation-menus.js', 'notifications.js'] as $script) {
            $source = (string) file_get_contents(BASE_PATH . '/public/js/layout/' . $script);
            $escapeGuards += substr_count($source, 'event.defaultPrevented') + substr_count($source, 'e.defaultPrevented');
        }
        $this->assertGreaterThanOrEqual(2, $escapeGuards);
    }

    // No interactive element removes the outline without a replacement focus style
    public function test_no_interactive_element_hides_the_focus_outline_without_a_replacement(): void
    {
        $offenders = [];
        $files = array_merge(
            iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BASE_PATH . '/src/Views', \FilesystemIterator::SKIP_DOTS))),
            self::ownScriptFiles()
        );
        foreach ($files as $file) {
            $path = is_string($file) ? $file : $file->getPathname();
            if (str_ends_with($path, '.min.js')) {
                continue;
            }
            $source = (string) file_get_contents($path);
            // Every start tag of an interactive element with a class attribute (PHP interpolation inside a tag is skipped over)
            preg_match_all('/<(a|button|input|select|textarea|summary)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s', $source, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($tags as $tag) {
                if (!preg_match('/class="([^"]*)"/', $tag[2][0], $classMatch)) {
                    continue;
                }
                $classes = $classMatch[1];
                $removesOutline = (bool) preg_match('/(^|\s)(focus:)?outline-none(\s|$)/', $classes);
                $hasReplacement = (bool) preg_match('/(^|\s)(focus|focus-visible):(ring|border|bg|text|underline|shadow)/', $classes);
                if ($removesOutline && !$hasReplacement) {
                    $offenders[] = str_replace(BASE_PATH . '/', '', $path) . ':' . (substr_count(substr($source, 0, $tag[0][1]), "\n") + 1) . " <{$tag[1][0]}>";
                }
            }
        }

        $this->assertSame([], $offenders, 'Add focus:ring-2 focus:ring-accent-1 (or another focus style) next to focus:outline-none.');
    }

    // Nothing that is not a native control is clickable without being reachable by keyboard
    public function test_clickable_non_native_elements_are_keyboard_operable(): void
    {
        $failures = [];
        foreach ($this->renderedPages() as $name => $html) {
            foreach ((new DOMXPath($this->documentFor($html)))->query('//*[@onclick][not(self::a or self::button or self::input or self::select or self::textarea or self::summary)]') as $element) {
                $operable = $element->hasAttribute('tabindex') && in_array($element->getAttribute('role'), ['button', 'link'], true);
                if (!$operable) {
                    $failures[] = "{$name}: <{$element->tagName} id=\"{$element->getAttribute('id')}\">";
                }
            }
        }

        $this->assertSame([], $failures);
    }

    // Errors interrupt (role="alert"), success and information are polite (role="status"); the dismiss button has a name
    public function test_flash_notifications_are_live_regions(): void
    {
        $expectedRoles = ['error' => 'alert', 'warning' => 'alert', 'success' => 'status', 'info' => 'status'];
        foreach ($expectedRoles as $type => $role) {
            $notification = ['type' => $type, 'title' => 'Title', 'message' => 'Message', 'is_toast' => true];
            ob_start();
            require BASE_PATH . '/src/Views/components/notification-alert.php';
            $html = (string) ob_get_clean();

            $document = $this->documentFor($html);
            $xpath = new DOMXPath($document);
            $this->assertSame(1, $xpath->query("//*[@role='{$role}'][@data-notification-item]")->length, "A {$type} notification must be role=\"{$role}\".");
            $dismiss = $xpath->query('//button')->item(0);
            $this->assertNotSame('', $this->accessibleName($document, $dismiss));
            $this->assertTrue($this->isAriaHidden($xpath->query('//button//svg')->item(0)));
        }
    }

    // The bell exposes its state and target, and the unread count is part of its name
    public function test_notification_bell_exposes_state_and_unread_count(): void
    {
        $document = $this->documentFor($this->renderedPages()['users-index']);
        $bell = $document->getElementById('notification-menu-button');

        $this->assertNotNull($bell);
        $this->assertSame('false', $bell->getAttribute('aria-expanded'));
        $this->assertSame('notification-dropdown-menu', $bell->getAttribute('aria-controls'));
        $this->assertNotNull($document->getElementById('notification-dropdown-menu'), 'aria-controls must point at an existing element.');
        $this->assertStringContainsString('Notifications', $this->accessibleName($document, $bell));
        // With no unread notification the name has no count; with some, the count is part of the name (see the next test)
        $this->assertStringNotContainsString('unread', $this->accessibleName($document, $bell));

        // The user menu button states what it controls as well
        $userMenu = $document->getElementById('user-menu-button');
        $this->assertSame('false', $userMenu->getAttribute('aria-expanded'));
        $this->assertNotNull($document->getElementById($userMenu->getAttribute('aria-controls')));
    }

    // With unread notifications the bell's accessible name carries the count
    public function test_notification_bell_name_carries_the_unread_count(): void
    {
        $service = new \App\Modules\Notifications\NotificationService();
        $service->notifyUser((int) $this->fx['users']['admin'], 'First', 'One', 'info');
        $service->notifyUser((int) $this->fx['users']['admin'], 'Second', 'Two', 'info');
        $client = HttpClient::start();
        $client->loginAs('admin');
        $document = $this->documentFor($client->get('/users')['body']);

        $this->assertStringContainsString('2 unread', $this->accessibleName($document, $document->getElementById('notification-menu-button')));
    }

    // A route step's × button is named after the step it removes
    public function test_route_step_remove_buttons_name_their_step(): void
    {
        $page = $this->renderedPages()['manage-documents'];
        $xpath = new DOMXPath($this->documentFor($page));
        $buttons = $xpath->query('//button[contains(@class, "delete-step-btn")]');
        foreach ($buttons as $button) {
            $this->assertMatchesRegularExpression('/^Remove step: \S/', $button->getAttribute('aria-label'));
        }
        // The script that adds steps names its × buttons the same way
        $this->assertStringContainsString("'Remove step: ' + departmentName", (string) file_get_contents(BASE_PATH . '/public/js/pages/manage-documents.js'));
    }

    // A field that failed validation is marked invalid and points at its message; the message is in the page
    public function test_failed_guest_form_fields_are_linked_to_their_error_messages(): void
    {
        $client = HttpClient::start();
        $client->get('/');
        $client->post('/submit-document', ['guest_name' => '', 'guest_phone' => '', 'title' => '']);
        $body = $client->get('/')['body'];

        $document = $this->documentFor($body);
        $invalid = (new DOMXPath($document))->query('//*[@aria-invalid="true"]');
        $this->assertGreaterThan(0, $invalid->length, 'No field was marked aria-invalid after a failed submission.');
        foreach ($invalid as $field) {
            $message = $document->getElementById($field->getAttribute('aria-describedby'));
            $this->assertNotNull($message, "#{$field->getAttribute('id')} points at no error message.");
            $this->assertNotSame('', trim($message->textContent));
        }
    }
}
