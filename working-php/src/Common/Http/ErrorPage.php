<?php

namespace App\Common\Http;

/**
 * Error responses: a styled page for browsers, a JSON body for scripts.
 *
 * Needs neither the database nor a valid session (a 500 caused by a database outage must still render), and
 * shows only the wording in STATUSES or a message the caller chose, never exception text, paths or traces.
 */
class ErrorPage
{
    // Status => [title, what happened and what the person can do]
    private const STATUSES = [
        403 => ['Forbidden', 'Your account is not allowed to open this page. If you need access, ask an administrator.'],
        404 => ['Not Found', 'We could not find that page. It may have moved, or the address may be mistyped.'],
        405 => ['Method Not Allowed', 'This address does not accept that kind of request. Go back and use the page\'s own buttons.'],
        419 => ['Page Expired', 'The page was open for too long, or your session changed, so the request was not accepted. Reload the page and try again.'],
        429 => ['Too Many Requests', 'There were too many requests in a short time. Wait a moment and try again.'],
        500 => ['Internal Server Error', 'Something went wrong on our side. Try again in a moment; if it keeps happening, tell an administrator.'],
        503 => ['Service Unavailable', 'The system is busy or being maintained. Try again in a few minutes.'],
    ];

    // Used for any status that has no entry above
    private const GENERIC = ['Something went wrong', 'The request could not be completed. Go back and try again.'];

    /**
     * Statuses that have their own wording, in table order.
     *
     * @return list<int>
     */
    public static function knownStatuses(): array
    {
        return array_keys(self::STATUSES);
    }

    /**
     * Page title of a status with its own wording (for example "Not Found" for 404).
     */
    public static function title(int $status): string
    {
        return (self::STATUSES[$status] ?? self::GENERIC)[0];
    }

    /**
     * Send the error response and stop.
     */
    public static function render(int $status, ?string $message = null, ?int $retryAfterSeconds = null): never
    {
        [$title, $sentence] = self::STATUSES[$status] ?? self::GENERIC;
        $sentence = $message !== null && $message !== '' ? $message : $sentence;

        // The status and, when given, how long to wait
        if (!headers_sent()) {
            // A page that failed half-way must not be followed by the error page (this also drops the response
            // cache's buffer, so its shutdown step sees no output and an error is never cached)
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            http_response_code($status);
            if ($retryAfterSeconds !== null) {
                header('Retry-After: ' . max(0, $retryAfterSeconds));
            }
        }

        // Scripts parse JSON, so they must not receive an HTML page
        if (request_expects_json()) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode(['success' => false, 'message' => $sentence]);
            exit;
        }

        // Back to the signed-in user's home, or the start page for a guest
        $signedIn = isset($_SESSION['user_id']);
        $homePath = $signedIn ? role_home_path($_SESSION['role'] ?? null) : '/';
        $homeLabel = $signedIn ? 'Back to your home page' : 'Back to the start page';
        $showReloadHint = $status === 419;

        require BASE_PATH . '/src/Views/errors/error.php';
        exit;
    }
}
