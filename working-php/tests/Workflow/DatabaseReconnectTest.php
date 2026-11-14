<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * A long-running process re-opens its database connection after MySQL has dropped it.
 */
class DatabaseReconnectTest extends TestCase
{
    // The id MySQL gives the shared connection
    private function connectionId(): int
    {
        return (int) Database::getInstance()->query('SELECT CONNECTION_ID() AS id')->fetch()['id'];
    }

    // Close the shared connection from the server side, as an idle timeout or a MySQL restart would
    private function killSharedConnection(): void
    {
        $config = (require BASE_PATH . '/src/Common/Config/app.config.php')['database'];
        $admin = new PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']}",
            $config['user'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $admin->exec('KILL ' . $this->connectionId());
    }

    // After the server drops the connection, ensureConnected() opens a new one on Philippine time
    public function test_ensure_connected_reopens_a_killed_connection(): void
    {
        $db = Database::getInstance();
        $before = $this->connectionId();
        $this->killSharedConnection();

        // The dropped connection fails on its next query
        try {
            $db->query('SELECT 1');
            $this->fail('The killed connection still answered.');
        } catch (PDOException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        // Reconnect: queries work again, on a new connection, with the session time zone restored
        $db->ensureConnected();
        $this->assertNotSame($before, $this->connectionId());
        $this->assertSame('+08:00', $db->query('SELECT @@session.time_zone AS tz')->fetch()['tz']);
    }

    // On a healthy connection ensureConnected() changes nothing
    public function test_ensure_connected_keeps_a_healthy_connection(): void
    {
        $before = $this->connectionId();
        Database::getInstance()->ensureConnected();

        $this->assertSame($before, $this->connectionId());
    }
}
