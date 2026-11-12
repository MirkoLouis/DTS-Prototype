<?php

namespace Tests\Http;

use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\TestCase;

class TrackingFormPrintTest extends TestCase
{
    // Officer's manage page links to the print form
    public function test_officer_manage_page_links_print_form(): void
    {
        $doc = Fixtures::submit();
        $client = HttpClient::start();
        $client->loginAs('records.unit');
        $response = $client->get('/documents/' . $doc['id'] . '/manage');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('/documents/' . $doc['tracking_code'] . '/print-tracking-form', $response['body']);
    }

    // Officer's document detail page links to the print form
    public function test_officer_document_page_links_print_form(): void
    {
        $doc = Fixtures::submit();
        $client = HttpClient::start();
        $client->loginAs('records.unit');
        $response = $client->get('/documents/' . $doc['tracking_code']);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('/documents/' . $doc['tracking_code'] . '/print-tracking-form', $response['body']);
    }

    // Staff never see the print button
    public function test_staff_document_page_has_no_print_button(): void
    {
        $doc = Fixtures::submit();
        Fixtures::advanceTo($doc['id'], 'in_transit', ['Cash Unit']);
        $client = HttpClient::start();
        $client->loginAs('cash.unit');
        $response = $client->get('/documents/' . $doc['tracking_code']);

        $this->assertSame(200, $response['status']);
        $this->assertStringNotContainsString('print-tracking-form', $response['body']);
    }

    // The print route stays public and serves a PDF
    public function test_print_form_returns_pdf_without_login(): void
    {
        $doc = Fixtures::submit();
        $response = HttpClient::start()->get('/documents/' . $doc['tracking_code'] . '/print-tracking-form');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('application/pdf', json_encode($response['headers'], JSON_UNESCAPED_SLASHES));
        $this->assertStringStartsWith('%PDF', $response['body']);
    }
}
