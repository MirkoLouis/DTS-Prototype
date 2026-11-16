<?php

namespace App\Common\Middleware;

use App\Common\Config\Config;
use App\Common\RateLimit\RateLimitService;

/**
 * Per-IP rate limiting of public routes, attached as RateLimitMiddleware::class . ':' . RateLimitMiddleware::SUBMIT.
 *
 * The route argument is "bucket,max,windowSeconds". The limits of every limited route are defined once, below.
 * The client is REMOTE_ADDR only: a header such as X-Forwarded-For is chosen by the caller and would let anyone
 * dodge the limit. (Behind a reverse proxy REMOTE_ADDR is the proxy, so every visitor would share one counter;
 * the NGINX + PHP-FPM deployment passes the real address in REMOTE_ADDR.)
 */
class RateLimitMiddleware
{
    // Route limits as "bucket,max,windowSeconds", per client IP
    public const SUBMIT = 'submit,10,60';
    public const TRACK = 'track,60,60';
    public const TRACK_MODULE = 'track-module,120,60';
    public const TRACK_POLL = 'track-poll,240,60';
    public const PDF = 'pdf,10,60';

    // Client key used when the server gives no REMOTE_ADDR (never taken from a header)
    private const UNKNOWN_CLIENT = 'unknown';

    // Shown on the 429 page and in the JSON body
    private const MESSAGE = 'Too many requests came from your connection, so this one was not accepted. Please wait %d second(s) and try again.';

    private RateLimitService $service;
    private bool $enabled;

    /**
     * Both arguments are for tests; the router creates the middleware with none.
     */
    public function __construct(?RateLimitService $service = null, ?bool $enabled = null)
    {
        $this->service = $service ?? new RateLimitService();
        $this->enabled = $enabled ?? (bool) Config::get('app', 'rate_limit_enabled', true);
    }

    /**
     * Router entry point: answer 429 with Retry-After when the client is over the limit, otherwise let the request on.
     */
    public function handle(string $bucket = '', string $max = '0', string $windowSeconds = '0'): void
    {
        $retryAfter = $this->retryAfter($bucket, (int) $max, (int) $windowSeconds, self::clientAddress(), time());

        // Allowed
        if ($retryAfter === null) {
            return;
        }

        render_error_page(429, sprintf(self::MESSAGE, $retryAfter), $retryAfter);
    }

    /**
     * Count the hit; null when allowed (or the limiter is off), otherwise the seconds until the window resets.
     */
    public function retryAfter(string $bucket, int $max, int $windowSeconds, string $client, int $now): ?int
    {
        // The switch is off, or the route arguments are unusable: nothing is limited
        if (!$this->enabled || $bucket === '' || $max < 1 || $windowSeconds < 1) {
            return null;
        }

        return $this->service->hit($bucket, $client, $max, $windowSeconds, $now);
    }

    /**
     * The address of the connection that sent the request.
     */
    public static function clientAddress(): string
    {
        $address = $_SERVER['REMOTE_ADDR'] ?? '';

        return is_string($address) && $address !== '' ? $address : self::UNKNOWN_CLIENT;
    }
}
