<?php

namespace Tests\Unit;

use App\Common\Utils\JsonDataBlock;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The helper that carries server data to scripts as an inert JSON block cannot be broken out of.
 */
class JsonDataBlockTest extends TestCase
{
    // Strings that would end or disturb a script element if they were written as they are
    private const HOSTILE = [
        '</script><script>alert(1)</script>',
        '</SCRIPT >',
        '<!-- comment start',
        '<script>',
        '"; alert(1); "',
        "it's & that",
        'a & b < c > d',
        "line\nbreak and \u{2028} separator",
    ];

    // A hostile value never appears raw in the markup, and the block parses back to exactly the original value
    public function test_hostile_strings_are_escaped_and_round_trip(): void
    {
        foreach (self::HOSTILE as $hostile) {
            $html = JsonDataBlock::render('data-block', ['text' => $hostile, 'list' => [$hostile]]);

            // The only "<" and ">" of the output are those of the element itself
            $inner = substr($html, strlen('<script type="application/json" id="data-block">'), -strlen('</script>'));
            $this->assertStringNotContainsString('<', $inner, "raw < for: {$hostile}");
            $this->assertStringNotContainsString('>', $inner, "raw > for: {$hostile}");
            $this->assertStringNotContainsString('&', $inner, "raw & for: {$hostile}");
            $this->assertStringNotContainsString("'", $inner, "raw ' for: {$hostile}");
            $this->assertStringNotContainsString('<!--', $html);

            // Exactly one script element: a closing tag inside the data cannot end the block early
            $this->assertSame(1, substr_count(strtolower($html), '</script'));

            // JSON.parse(textContent) in the browser equals json_decode here
            $this->assertSame(['text' => $hostile, 'list' => [$hostile]], json_decode($inner, true));
        }
    }

    // Markup characters and quotes are written as backslash-u escapes, so the text cannot close an attribute or a string either
    public function test_quotes_and_markup_characters_use_unicode_escapes(): void
    {
        $encoded = JsonDataBlock::encode('<a href="x">\'&');

        // One backslash character, followed by the four hex digits of each escaped character
        $backslash = chr(92);
        $expected = '"' . $backslash . 'u003Ca href=' . $backslash . 'u0022x' . $backslash . 'u0022' . $backslash . 'u003E' . $backslash . 'u0027' . $backslash . 'u0026"';
        $this->assertSame($expected, $encoded);
    }

    // Numbers, booleans, null and nested structures keep their types
    public function test_other_types_round_trip(): void
    {
        $data = ['id' => 7, 'ok' => true, 'none' => null, 'nested' => ['a' => [1, 2.5, 'x']]];

        $this->assertSame($data, json_decode(JsonDataBlock::encode($data), true));
    }

    // Invalid UTF-8 is substituted instead of failing the page
    public function test_invalid_utf8_is_substituted(): void
    {
        $decoded = json_decode(JsonDataBlock::encode("bad \xB1 byte"), true);

        $this->assertSame("bad \u{FFFD} byte", $decoded);
    }

    // A value that cannot be encoded gives null and a log line instead of an exception in the middle of a layout
    public function test_unencodable_value_falls_back_to_null_and_logs(): void
    {
        // The log file lives under .test/ like every file a test creates
        $logFile = \Tests\Support\TestFiles::file('jsonblock');
        $previous = ini_set('error_log', $logFile);
        try {
            $html = JsonDataBlock::render('data-block', ['bad' => NAN]);
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertSame('<script type="application/json" id="data-block">null</script>', $html);
        $this->assertStringContainsString('JsonDataBlock: value could not be encoded', (string) file_get_contents($logFile));
        @unlink($logFile);
    }

    // The element id comes from the developer; anything that could break the attribute is refused
    public function test_unsafe_element_ids_are_refused(): void
    {
        foreach (['', '1abc', 'a b', 'a"b', 'a><script>', 'a\'b', "a\nb"] as $id) {
            try {
                JsonDataBlock::render($id, []);
                $this->fail("id accepted: {$id}");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Invalid JSON data block id.', $e->getMessage());
            }
        }

        $this->assertStringContainsString('id="dts-user_config-2"', JsonDataBlock::render('dts-user_config-2', []));
    }
}
