<?php

namespace Tests\Support;

use DOMDocument;
use DOMNode;
use DOMXPath;
use PHPUnit\Framework\Assert;

/**
 * Reads the messages a rendered page shows, from the element that shows them:
 * toasts (inside #toast-container), the login alert and the Security PIN prompt error.
 * Assertions run against the element's text, not against the whole page.
 */
class PageMessages
{
    /**
     * Every toast on the page, in document order.
     *
     * @return array<int, array{type: string, text: string}>
     */
    public static function toasts(string $html): array
    {
        $xpath = self::query($html);
        $toasts = [];

        // Only items inside the toast container count (the bell dropdown renders the same component)
        foreach ($xpath->query('//*[@id="toast-container"]//*[@data-notification-item]') as $item) {
            $message = $xpath->query('.//p', $item)->item(0);
            $toasts[] = [
                'type' => $item->attributes->getNamedItem('data-notification-type')?->nodeValue ?? '',
                'text' => $message === null ? '' : self::text($message),
            ];
        }

        return $toasts;
    }

    /**
     * Fail unless a toast of the given type contains the text.
     */
    public static function assertToast(string $html, string $type, string $text): void
    {
        $toasts = self::toasts($html);

        // Pass when any toast has the type and contains the text
        foreach ($toasts as $toast) {
            if ($toast['type'] === $type && str_contains($toast['text'], $text)) {
                Assert::assertTrue(true);
                return;
            }
        }

        Assert::fail(
            'No "' . $type . '" toast contains "' . $text . '". Toasts found: '
            . ($toasts === [] ? 'none' : json_encode($toasts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
        );
    }

    /**
     * The text of the login page alert, or null when there is none.
     */
    public static function loginAlert(string $html): ?string
    {
        return self::firstText($html, '//div[@role="alert"]');
    }

    /**
     * Fail unless the login page alert contains the text.
     */
    public static function assertLoginAlert(string $html, string $text): void
    {
        self::assertChannel(self::loginAlert($html), 'login alert', $text);
    }

    /**
     * The text of the Security PIN prompt error, or null when there is none.
     */
    public static function pinPromptError(string $html): ?string
    {
        return self::firstText($html, '//*[@id="pin-setup-error"]');
    }

    /**
     * Fail unless the Security PIN prompt error contains the text.
     */
    public static function assertPinPromptError(string $html, string $text): void
    {
        self::assertChannel(self::pinPromptError($html), 'PIN prompt error', $text);
    }

    // Assert a single-message channel exists and contains the text
    private static function assertChannel(?string $actual, string $channel, string $text): void
    {
        Assert::assertNotNull($actual, 'The page shows no ' . $channel . '.');
        Assert::assertStringContainsString($text, $actual, 'The ' . $channel . ' does not contain the text.');
    }

    // The text of the first element matching the XPath expression, or null
    private static function firstText(string $html, string $expression): ?string
    {
        $node = self::query($html)->query($expression)->item(0);
        return $node === null ? null : self::text($node);
    }

    // The visible text of a node: entities decoded, whitespace collapsed and trimmed
    private static function text(DOMNode $node): string
    {
        return trim(preg_replace('/\s+/u', ' ', $node->textContent));
    }

    // Parse (possibly sloppy) HTML into an XPath query object
    private static function query(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The XML declaration makes libxml read the markup as UTF-8
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
