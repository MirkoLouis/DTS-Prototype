<?php

namespace Tests\Http;

use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\TestCase;

/**
 * The tracking slip embeds a print-sized copy of the DepEd seal, so the PDF stays small.
 */
class TrackingSlipPdfTest extends TestCase
{
    // Largest accepted slip: the print copy gives about 76 KB, the 1280 px original gave about 568 KB
    private const MAX_PDF_BYTES = 150000;

    // The slip shows the seal 120 CSS px wide; Dompdf renders at 96 dpi, so that is 1.25 in, and 300 dpi needs 375 px
    private const PRINT_SEAL_WIDTH = 375;

    // The slip PDF of a fresh document, fetched by an officer
    private function slip(): string
    {
        $doc = Fixtures::submit();
        $client = HttpClient::startWithoutRateLimit();
        $client->loginAs('records.unit');
        $response = $client->get('/documents/' . $doc['tracking_code'] . '/print-tracking-form');

        $this->assertSame(200, $response['status']);
        $this->assertStringStartsWith('%PDF', $response['body']);

        return $response['body'];
    }

    // The print copy is 375 px wide with the original's proportions and keeps its transparency; the original is untouched
    public function test_print_copy_has_print_size_and_transparency(): void
    {
        $original = getimagesize(BASE_PATH . '/public/images/DepEd Seal.png');
        $print = getimagesize(BASE_PATH . '/public/images/DepEd Seal (print).png');

        $this->assertSame([1280, 720], [$original[0], $original[1]]);
        $this->assertSame(self::PRINT_SEAL_WIDTH, $print[0]);
        $this->assertEqualsWithDelta($original[1] / $original[0], $print[1] / $print[0], 0.005);

        // Colour type 6 is RGBA: the PNG header stores it in byte 25
        $header = file_get_contents(BASE_PATH . '/public/images/DepEd Seal (print).png', false, null, 0, 26);
        $this->assertSame(6, ord($header[25]));
    }

    // The slip embeds the print copy (an image 375 px wide) and no 1280 px image
    public function test_slip_embeds_the_print_copy(): void
    {
        $pdf = $this->slip();

        $this->assertMatchesRegularExpression('#/Subtype /Image\s*/Width ' . self::PRINT_SEAL_WIDTH . '\b#', $pdf);
        $this->assertDoesNotMatchRegularExpression('#/Subtype /Image\s*/Width 1280\b#', $pdf);
        $this->assertStringContainsString('DepEd Seal (print).png', file_get_contents(BASE_PATH . '/src/Views/general/tracking-form-pdf.php'));
    }

    // The PDF stays below the size limit
    public function test_slip_pdf_is_small(): void
    {
        $this->assertLessThan(self::MAX_PDF_BYTES, strlen($this->slip()));
    }
}
