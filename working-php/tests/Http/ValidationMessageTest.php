<?php

namespace Tests\Http;

use Tests\Support\HttpClient;
use Tests\TestCase;

/**
 * Validation errors reach the page as plain text; every sink escapes them once.
 */
class ValidationMessageTest extends TestCase
{
    // The helper joins messages with one space and returns an empty string for no errors
    public function test_validation_error_message_joins_with_spaces(): void
    {
        $this->assertSame('A is required. B is required.', validation_error_message(['A is required.', 'B is required.']));
        $this->assertSame('', validation_error_message([]));
    }

    // Several errors on the user form show as text, with no <br> markup, escaped or not
    public function test_user_form_errors_show_no_br_markup(): void
    {
        // Submit the create-user form with every field empty
        $client = HttpClient::start();
        $client->loginAs('admin');
        $client->get('/users/create');
        $response = $client->post('/users', ['name' => '', 'email' => '', 'password' => '', 'password_confirmation' => '', 'role' => '']);
        $this->assertSame(302, $response['status']);

        // The error box on the form lists the messages as plain text
        $body = $client->get('/users/create')['body'];
        $this->assertMatchesRegularExpression('/role="alert".*?<\/div>/s', $body);
        preg_match('/role="alert".*?<\/div>/s', $body, $match);
        $this->assertStringNotContainsString('&lt;br&gt;', $match[0]);
        $this->assertStringNotContainsString('<br>', $match[0]);
        $this->assertGreaterThanOrEqual(2, substr_count(strtolower($match[0]), 'required'));
    }
}
