<?php

namespace Tests\Support;

use App\Core\Database;
use App\Core\IntegrityManager;

/**
 * Rebuilds and seeds the test database. Always runs TestGuard first.
 */
class TestDatabase
{
    public const PIN = '123456';
    public const PASSWORD = 'password';

    // bcrypt of 'password', same hash the production seeder uses
    private const PASSWORD_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public const DEPARTMENTS = [
        'Records Unit', 'Cash Unit', 'Administrative Unit', 'Personnel Unit', 'Supply Unit',
        'Budget Unit', 'Accounting Unit', 'Legal Unit', 'Health and Nutrition',
        'Bids and Awards Committee Unit', 'Schools Division Superintendent Office',
        'Assistant Schools Division Superintendent Office', 'Curriculum Implementation Division',
        'School Governance and Operations Division',
    ];

    // name => suggested route (copied from scripts/seeders/setup-production-db.js)
    public const PURPOSES = [
        'CAV Request (Certification, Authentication, and Verification)' => ['Records Unit', 'Schools Division Superintendent Office'],
        'Request for Certified True Copy (CTC)' => ['Records Unit'],
        'Correction of School Entries' => ['Records Unit', 'Legal Unit'],
        'Submission of Application Documents (Ranking)' => ['Personnel Unit', 'Records Unit'],
        'Request for Service Record' => ['Personnel Unit'],
        'Request for Certificate of Employment (COE)' => ['Personnel Unit'],
        'Request for Approval to Conduct Study/Survey' => ['Records Unit', 'Schools Division Superintendent Office'],
        'Request for Data/Statistics' => ['Records Unit'],
        'Application for Government Permit/Recognition' => ['Records Unit', 'Schools Division Superintendent Office'],
        'Filing of Administrative Complaint' => ['Legal Unit', 'Schools Division Superintendent Office'],
        'Proposal for Partnership (MOA/MOU)' => ['Schools Division Superintendent Office'],
        'Processing of First Salary' => ['Personnel Unit', 'Budget Unit', 'Accounting Unit', 'Cash Unit'],
        'Retirement Claims' => ['Personnel Unit', 'Accounting Unit', 'Schools Division Superintendent Office'],
        'Request for Use of Division Facilities/Vehicle' => ['Administrative Unit', 'Assistant Schools Division Superintendent Office'],
        'Request for Office Supplies/Equipment (RIS)' => ['Supply Unit', 'Administrative Unit'],
        'Submission of Bid Documents (Project Tender)' => ['Bids and Awards Committee Unit', 'Legal Unit'],
        'Submission of Nutritional Status Report (SF13)' => ['Health and Nutrition', 'Curriculum Implementation Division'],
        'Request for Learning Resources/Textbooks' => ['Curriculum Implementation Division'],
        'Submission of School Monitoring and Evaluation Report' => ['School Governance and Operations Division', 'Assistant Schools Division Superintendent Office'],
        'Request for Archived Document Retrieval' => ['Records Unit', 'Legal Unit', 'Schools Division Superintendent Office'],
        'Records Authentication and Verification (RAV)' => ['Records Unit', 'Personnel Unit', 'Administrative Unit'],
        'Disposal of Old Files/Records' => ['Records Unit', 'Supply Unit', 'Accounting Unit'],
    ];

    // username => [display name, role, department name or null]
    private const USERS = [
        'admin' => ['Admin User', 'admin', null],
        'records.unit' => ['Records Officer', 'officer', 'Records Unit'],
        'cash.unit' => ['Cash Unit Staff', 'staff', 'Cash Unit'],
        'personnel.unit' => ['Personnel Unit Staff', 'staff', 'Personnel Unit'],
    ];

    // Per-process cache of generated keypairs (username => [public b64, wrapped private b64]); Argon2id is slow
    private static array $keyCache = [];

    /**
     * Run the safety guard against the live configuration, then return the database.
     */
    private static function guardedDb(): Database
    {
        // Check the same values the connection is about to use, before connecting
        $config = require BASE_PATH . '/src/Config/config.php';
        TestGuard::assertSafe((string) $config['app']['env'], (string) $config['database']['dbname']);

        return Database::getInstance();
    }

    /**
     * Drop every table in the test database and recreate the schema from database.sql.
     */
    public static function reset(): void
    {
        $conn = self::guardedDb()->getConnection();

        // Drop every existing table, including any left behind by older schemas
        $conn->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $conn->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $conn->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $conn->exec('SET FOREIGN_KEY_CHECKS = 1');

        // Strip comments and the CREATE DATABASE / USE lines so the script can only touch the connected database
        $sql = file_get_contents(BASE_PATH . '/database.sql');
        $lines = [];
        foreach (explode("\n", $sql) as $line) {
            $trim = ltrim($line);
            if (str_starts_with($trim, '--') || stripos($trim, 'CREATE DATABASE') === 0 || stripos($trim, 'USE ') === 0) {
                continue;
            }
            $lines[] = $line;
        }

        // Execute statement by statement (database.sql contains no semicolons inside literals)
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            if (trim($statement) !== '') {
                $conn->exec($statement);
            }
        }
    }

    /**
     * Insert departments, purposes and the baseline users. Returns the id maps.
     */
    public static function seedBaseline(): array
    {
        $db = self::guardedDb();
        $conn = $db->getConnection();
        $fx = ['users' => [], 'departments' => [], 'purposes' => []];

        // Departments
        foreach (self::DEPARTMENTS as $name) {
            $db->query('INSERT INTO departments (name, created_at, updated_at) VALUES (:n, NOW(), NOW())', [':n' => $name]);
            $fx['departments'][$name] = (int) $conn->lastInsertId();
        }

        // Official purposes with their suggested routes
        foreach (self::PURPOSES as $name => $route) {
            $db->query("INSERT INTO purposes (name, is_official, requirements, suggested_route, created_at, updated_at) VALUES (:n, 1, '[]', :r, NOW(), NOW())", [
                ':n' => $name,
                ':r' => json_encode($route),
            ]);
            $fx['purposes'][$name] = (int) $conn->lastInsertId();
        }

        // The catch-all non-official purpose
        $db->query("INSERT INTO purposes (name, is_official, requirements, suggested_route, created_at, updated_at) VALUES ('Others', 0, '[]', '[]', NOW(), NOW())");
        $fx['purposes']['Others'] = (int) $conn->lastInsertId();

        // Users, each with a PIN-wrapped Ed25519 key and an open key-history period
        foreach (self::USERS as $username => [$name, $role, $deptName]) {
            [$pub, $priv] = self::keyFor($username);
            $db->query('INSERT INTO users (name, email, password, public_key, private_key, security_key_set_at, department_id, role, created_at, updated_at) VALUES (:name, :email, :pw, :pub, :priv, NOW(), :dept, :role, NOW(), NOW())', [
                ':name' => $name,
                ':email' => $username,
                ':pw' => self::PASSWORD_HASH,
                ':pub' => $pub,
                ':priv' => $priv,
                ':dept' => $deptName ? $fx['departments'][$deptName] : null,
                ':role' => $role,
            ]);
            $userId = (int) $conn->lastInsertId();
            $fx['users'][$username] = $userId;

            $db->query("INSERT INTO user_public_key_histories (user_id, public_key, activated_at, created_at, updated_at) VALUES (:u, :pub, '2000-01-01 00:00:00', NOW(), NOW())", [
                ':u' => $userId,
                ':pub' => $pub,
            ]);
        }

        return $fx;
    }

    /**
     * Build (once per process) the public key and the 112-byte wrapped private key for a user.
     * Format matches SecurityKeyController: base64(salt16 || iv16 || AES-256-CBC(secret key)).
     */
    private static function keyFor(string $username): array
    {
        // Reuse the cached key so a reset never changes a user's identity within one run
        if (!isset(self::$keyCache[$username])) {
            $keypair = sodium_crypto_sign_keypair();
            $secretKey = sodium_crypto_sign_secretkey($keypair);

            // Derive the wrapping key from the PIN with Argon2id and encrypt the secret key
            $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $key = sodium_crypto_pwhash(
                32,
                self::PIN,
                $salt,
                SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
                SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
                SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
            );
            $iv = random_bytes(16);
            $encrypted = openssl_encrypt($secretKey, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

            self::$keyCache[$username] = [
                base64_encode(sodium_crypto_sign_publickey($keypair)),
                base64_encode($salt . $iv . $encrypted),
            ];
        }

        return self::$keyCache[$username];
    }
}
