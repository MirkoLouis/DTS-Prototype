<?php

namespace Tests\Unit;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Tests\Support\PageMessages;

/**
 * PageMessages reads the messages a page shows (toast, login alert, PIN prompt error) from the element that shows them.
 */
class PageMessagesTest extends TestCase
{
    // One toast item as notification-alert.php renders it
    private function toastItem(string $type, string $title, string $messageHtml): string
    {
        $role = in_array($type, ['error', 'warning'], true) ? 'alert' : 'status';
        return '<div role="' . $role . '" data-notification-item data-notification-type="' . $type . '" class="rounded-md p-4">'
            . '<div class="flex items-start gap-4"><div class="flex-1 pr-6">'
            . '<strong class="block"> ' . $title . ' </strong>'
            . '<p class="mt-0.5 text-sm">' . "\n                " . $messageHtml . "\n            " . '</p>'
            . '</div></div></div>';
    }

    // A page with the given toast items inside the toast container
    private function pageWithToasts(string ...$items): string
    {
        return '<html><body><main><p>Main content</p></main>'
            . '<div id="toast-container" class="fixed">' . implode('', $items) . '</div></body></html>';
    }

    // The type and the message text of a toast are read
    public function test_toast_type_and_text_are_read(): void
    {
        $html = $this->pageWithToasts($this->toastItem('error', 'Error', 'Password too short.'));

        $this->assertSame([['type' => 'error', 'text' => 'Password too short.']], PageMessages::toasts($html));
        PageMessages::assertToast($html, 'error', 'too short');
    }

    // A toast is found among several
    public function test_toast_finds_one_of_several(): void
    {
        $html = $this->pageWithToasts(
            $this->toastItem('success', 'Done', 'Saved.'),
            $this->toastItem('error', 'Error', 'Nope.')
        );

        $this->assertCount(2, PageMessages::toasts($html));
        PageMessages::assertToast($html, 'error', 'Nope.');
        PageMessages::assertToast($html, 'success', 'Saved.');
    }

    // The same text in the page body is not a toast
    public function test_toast_ignores_text_outside_the_toast_container(): void
    {
        $html = '<html><body><main><p>Password too short.</p></main><div id="toast-container"></div></body></html>';

        $this->expectException(AssertionFailedError::class);
        PageMessages::assertToast($html, 'error', 'Password too short.');
    }

    // A notification item outside the toast container (the bell dropdown) is not a toast
    public function test_toast_ignores_notification_items_outside_the_toast_container(): void
    {
        $html = '<html><body><nav><div id="bell-dropdown">' . $this->toastItem('error', 'Error', 'Password too short.') . '</div></nav>'
            . '<main><p>Main content</p></main><div id="toast-container"></div></body></html>';

        $this->assertSame([], PageMessages::toasts($html));
        $this->expectException(AssertionFailedError::class);
        PageMessages::assertToast($html, 'error', 'Password too short.');
    }

    // A toast of another type does not satisfy the assertion
    public function test_toast_of_the_wrong_type_fails(): void
    {
        $html = $this->pageWithToasts($this->toastItem('success', 'Done', 'Saved.'));

        $this->expectException(AssertionFailedError::class);
        PageMessages::assertToast($html, 'error', 'Saved.');
    }

    // Entities in the message are decoded to the text a user sees
    public function test_toast_text_is_decoded(): void
    {
        $html = $this->pageWithToasts($this->toastItem('error', 'Error', 'Use &lt;b&gt; carefully.'));

        $this->assertSame('Use <b> carefully.', PageMessages::toasts($html)[0]['text']);
        PageMessages::assertToast($html, 'error', '<b>');
    }

    // The login alert is the role="alert" box; field errors (paragraphs) are not
    public function test_login_alert_text_is_read_and_field_errors_are_not(): void
    {
        $html = '<form><p id="email-error" class="text-sm">Email is required.</p></form>'
            . '<div class="mb-4 bg-red-100" role="alert">' . "\n    " . '<span class="block sm:inline">Invalid credentials.</span>' . "\n" . '</div>';

        $this->assertSame('Invalid credentials.', PageMessages::loginAlert($html));
        PageMessages::assertLoginAlert($html, 'Invalid');

        $fieldOnly = '<form><p id="email-error" class="text-sm">Email is required.</p></form>';
        $this->assertNull(PageMessages::loginAlert($fieldOnly));
    }

    // The PIN prompt error is the paragraph with id pin-setup-error
    public function test_pin_prompt_error_is_read(): void
    {
        $html = '<p id="pin-setup-error" role="alert" class="mb-4 text-sm">PIN must be 6 digits.</p>';

        $this->assertSame('PIN must be 6 digits.', PageMessages::pinPromptError($html));
        PageMessages::assertPinPromptError($html, '6 digits');
    }

    // A page without the element yields no message
    public function test_missing_channel_returns_empty(): void
    {
        $html = '<html><body><main><p>Nothing here</p></main></body></html>';

        $this->assertSame([], PageMessages::toasts($html));
        $this->assertNull(PageMessages::loginAlert($html));
        $this->assertNull(PageMessages::pinPromptError($html));
    }
}
