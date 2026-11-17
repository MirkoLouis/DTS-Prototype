<?php

namespace App\Common\Utils;

use InvalidArgumentException;

/**
 * The ONE way server data reaches a script besides data-* attributes: a non-executable
 * <script type="application/json" id="..."> block that the browser never runs and that public/js/core/json-data.js reads
 * with JSON.parse(element.textContent).
 *
 * The four JSON_HEX_* flags write "<", ">", "&", "'" and '"' as \uXXXX escapes, so a value containing "</script>" or
 * "<!--" can neither end the block nor open a comment inside it.
 */
class JsonDataBlock
{
    // Flags that keep markup characters out of the output (and substitute invalid UTF-8 instead of failing)
    public const FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * The JSON text of a value, safe to place inside an HTML script element.
     */
    public static function encode(mixed $data): string
    {
        // A value that cannot be encoded (NAN, INF, a resource, too deep) must not abort the page in the middle of a
        // layout: the block then holds null, and the reason is logged
        try {
            return json_encode($data, self::FLAGS);
        } catch (\JsonException $e) {
            error_log('JsonDataBlock: value could not be encoded, null used instead: ' . $e->getMessage());

            return 'null';
        }
    }

    /**
     * The complete <script type="application/json"> element for a value.
     *
     * The id is chosen by the developer, never by a request: anything but letters, digits, "-" and "_" is refused.
     */
    public static function render(string $elementId, mixed $data): string
    {
        // A malformed id would break the attribute
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $elementId)) {
            throw new InvalidArgumentException('Invalid JSON data block id.');
        }

        return '<script type="application/json" id="' . $elementId . '">' . self::encode($data) . '</script>';
    }
}
