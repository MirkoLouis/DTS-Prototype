<?php

namespace App\Research;

use RuntimeException;

/**
 * Minimal cookie-aware HTTP session (one browser tab) for black-box tests and the manual live run.
 * Redirects are never followed. The test suite's Tests\Support\HttpClient builds on this class.
 */
class HttpSession
{
    protected string $baseUrl;
    private array $cookies = [];
    private string $lastBody = '';

    /**
     * @param bool $verifyTls false skips certificate and host-name checks (self-signed LAN certificate)
     */
    public function __construct(string $baseUrl, private bool $verifyTls = true)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * GET a path and return status, headers (lower-cased names) and body.
     */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /**
     * POST form fields; the CSRF token from the last page is added unless disabled.
     */
    public function post(string $path, array $fields, bool $withCsrf = true): array
    {
        // Attach the token scraped from the last rendered page
        if ($withCsrf && !isset($fields['csrf_token'])) {
            $fields['csrf_token'] = $this->csrfToken();
        }

        return $this->request('POST', $path, $fields);
    }

    /**
     * Read the CSRF token from the last page body (meta tag or hidden input).
     */
    protected function csrfToken(): string
    {
        // Prefer the layout's meta tag, fall back to a hidden form field
        if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $this->lastBody, $m)
            || preg_match('/name="csrf_token" value="([^"]+)"/', $this->lastBody, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * Perform a request with curl, tracking cookies by hand.
     */
    protected function request(string $method, string $path, ?array $fields = null): array
    {
        $headers = [];
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                // Collect response headers by lower-cased name; Set-Cookie may repeat
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))][] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        // Form body for POSTs
        if ($fields !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }

        // Send back every cookie the server set so far
        if ($this->cookies) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) {
                $pairs[] = "{$k}={$v}";
            }
            curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $pairs));
        }

        // Transport failure (refused connection, TLS error, timeout) is an exception, not a status
        $body = curl_exec($ch);
        if ($body === false) {
            throw new RuntimeException('HTTP request failed: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        // Remember cookies the server set
        foreach ($headers['set-cookie'] ?? [] as $cookie) {
            [$pair] = explode(';', $cookie, 2);
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $this->cookies[trim($k)] = $v;
        }

        // Flatten single-valued headers for convenient access
        $flat = [];
        foreach ($headers as $name => $values) {
            $flat[$name] = $name === 'set-cookie' ? $values : end($values);
        }
        $this->lastBody = $body;

        return ['status' => $status, 'headers' => $flat, 'body' => $body];
    }
}
