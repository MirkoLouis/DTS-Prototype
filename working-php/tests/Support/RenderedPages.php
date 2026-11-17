<?php

namespace Tests\Support;

use App\Common\Database\Database;
use App\Modules\Integrity\Jobs\IntegrityCheckJob;
use App\Modules\System\BackupService;
use RuntimeException;

/**
 * The rendered HTML of the main pages of every role, the guest pages, the login page and an error page.
 * Built once per test run and shared by the markup tests (accessibility, inline scripts).
 */
class RenderedPages
{
    // [page name => html], built on first use
    private static ?array $pages = null;

    /**
     * Fetch the main pages of every role and the guest pages.
     *
     * @param array $fixtureIds the ids seeded by TestDatabase::seedBaseline() ($this->fx of a test)
     * @return array<string, string> page name => HTML
     */
    public static function all(array $fixtureIds): array
    {
        if (self::$pages !== null) {
            return self::$pages;
        }

        // Documents in two states so the document pages have content
        $pending = Fixtures::submit(['title' => 'Pending document']);
        $inTransit = Fixtures::submit(['title' => 'In transit document']);
        Fixtures::advanceTo($inTransit['id'], 'in_transit');

        // Data that switches on the data-dependent branches of the views (so they are rendered and scanned too)
        $declined = Fixtures::submit(['title' => 'Declined document']);
        Fixtures::advanceTo($declined['id'], 'declined');
        $completed = Fixtures::submit(['title' => 'Completed document']);
        Fixtures::advanceTo($completed['id'], 'completed');
        self::seedSystemOverviewData($completed['id'], $completed['tracking_code']);
        $notifications = new \App\Modules\Notifications\NotificationService();
        // (for the officer account, whose pages are not the ones the bell-name test reads as "no notifications")
        $notifications->notifyUser((int) $fixtureIds['users']['records.unit'], 'Unread info', '<b>markup</b> in a message', 'info');
        $notifications->notifyUser((int) $fixtureIds['users']['records.unit'], 'Unread error', 'Something failed', 'error');

        $pages = [];
        $guest = HttpClient::start();
        foreach ([
            'guest-welcome' => '/',
            'guest-track' => '/track?codes=' . $pending['tracking_code'],
            'guest-track-unknown-code' => '/track?codes=DEPED-0000000000',
            'guest-track-declined' => '/track?codes=' . $declined['tracking_code'],
            'guest-track-completed' => '/track?codes=' . $completed['tracking_code'],
            'guest-success' => '/success?tracking_code=' . $pending['tracking_code'] . '&document_id=' . $pending['id'],
            'login' => '/login',
            'error-404' => '/this-page-does-not-exist',
        ] as $name => $path) {
            $response = $guest->get($path);
            if ($response['body'] === '') {
                throw new RuntimeException("{$path} rendered an empty body (status {$response['status']})");
            }
            $pages[$name] = $response['body'];
        }

        // Role => pages, rendered through the app layout
        foreach ([
            'admin' => [
                'admin-dashboard' => '/admin-dashboard', 'system-overview' => '/system-overview', 'all-documents' => '/all-documents',
                'users-index' => '/users', 'users-create' => '/users/create', 'users-edit' => '/users/' . $fixtureIds['users']['cash.unit'] . '/edit',
                'backups' => '/system/backups', 'admin-document' => '/documents/' . $inTransit['tracking_code'],
                'admin-document-declined' => '/documents/' . $declined['tracking_code'],
                'admin-document-completed' => '/documents/' . $completed['tracking_code'],
            ],
            'records.unit' => [
                'intake' => '/intake', 'tasks-completed' => '/tasks/completed', 'releasing' => '/releasing', 'statistics' => '/statistics',
                'manage-documents' => '/documents/' . $pending['id'] . '/manage',
                'manage-documents-not-pending' => '/documents/' . $inTransit['id'] . '/manage',
                'account-password' => '/account/password',
                'hash-chain' => '/documents/' . $inTransit['tracking_code'] . '/hash-chain',
            ],
            'cash.unit' => ['tasks-staff' => '/tasks', 'statistics-staff' => '/statistics'],
        ] as $username => $rolePages) {
            $client = HttpClient::start();
            $client->loginAs($username);
            foreach ($rolePages as $name => $path) {
                $response = $client->get($path);
                if ($response['status'] !== 200) {
                    throw new RuntimeException("{$path} did not render for {$username} (status {$response['status']})");
                }
                $pages[$name] = $response['body'];
            }
        }

        // A flash message becomes a toast on the next page
        $admin = HttpClient::start();
        $admin->loginAs('admin');
        $admin->get('/admin-dashboard');
        $admin->post('/clear-personal-cache', []);
        $pages['admin-dashboard-toast'] = $admin->get('/admin-dashboard')['body'];

        // A refused action (completing a task the account does not hold) leaves a console message for the browser ("dts-console-error" JSON block)
        $staff = HttpClient::start();
        $staff->loginAs('cash.unit');
        $staff->get('/tasks');
        $staff->post('/tasks/' . $pending['id'] . '/complete', ['pin' => TestDatabase::PIN]);
        $pages['tasks-console-error'] = $staff->get('/tasks')['body'];

        // A signed action with a typed PIN makes the server hand out the PIN cache token ("dts-pin-token" JSON block)
        $signer = HttpClient::start();
        $signer->loginAs('cash.unit');
        $signer->get('/tasks');
        $signer->post('/documents/scan', ['tracking_code' => $inTransit['tracking_code'], 'pin' => TestDatabase::PIN]);
        $pages['tasks-pin-token'] = $signer->get('/tasks')['body'];

        // An account without a signing key sees the mandatory Security PIN prompt
        Database::getInstance()->query('UPDATE users SET public_key = NULL, private_key = NULL, security_key_set_at = NULL WHERE email = :e', [':e' => 'personnel.unit']);
        $keyless = HttpClient::start();
        $keyless->loginAs('personnel.unit');
        $pages['security-pin-prompt'] = $keyless->get('/tasks')['body'];

        return self::$pages = $pages;
    }

    /**
     * Data for System Overview and Backups: a backup row (one name with a space and a plus), a failed job, and an
     * integrity issue naming a log entry and a document.
     */
    private static function seedSystemOverviewData(int $documentId, string $trackingCode): void
    {
        $directory = BackupService::directory();
        file_put_contents($directory . '/example-backup.zip', '');
        file_put_contents($directory . '/odd name+1.zip', '');

        $database = Database::getInstance();
        $database->query(
            'INSERT INTO failed_jobs (uuid, connection, queue, payload, exception) VALUES (:uuid, :connection, :queue, :payload, :exception)',
            [':uuid' => 'rendered-pages-failed-job', ':connection' => 'database', ':queue' => 'default', ':payload' => json_encode(['displayName' => 'ExampleJob']), ':exception' => "Example failure\nwith a second line and <b>markup</b>"]
        );

        $logId = (int) $database->query('SELECT id FROM document_logs WHERE document_id = :id ORDER BY id LIMIT 1', [':id' => $documentId])->fetchColumn();
        $resultPath = IntegrityCheckJob::resultPath();
        if (!is_dir(dirname($resultPath))) {
            mkdir(dirname($resultPath), 0755, true);
        }
        file_put_contents($resultPath, json_encode([
            'verified_percentage' => 50,
            'last_checked' => date('Y-m-d H:i:s'),
            'mismatched_ids' => [$logId],
            'mismatched_document_tracking_codes' => [$trackingCode],
        ]));
    }
}
