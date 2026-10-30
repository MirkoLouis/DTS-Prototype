<?php

namespace Tests\Support;

use App\Common\Database\Database;
use App\Modules\Users\UserModel;
use App\Modules\Documents\DocumentWorkflowService;
use InvalidArgumentException;

/**
 * Builders that drive the real workflow service so every fixture carries a valid signed chain.
 */
class Fixtures
{
    /**
     * Submit a guest document. Overrides replace any default field (e.g. purpose_id, title).
     *
     * @return array{id:int, tracking_code:string}
     */
    public static function submit(array $overrides = []): array
    {
        $db = Database::getInstance();

        // Default to the first official purpose and the Records Unit as the receiving department
        $purposeId = $db->query('SELECT id FROM purposes WHERE is_official = 1 ORDER BY id LIMIT 1')->fetch()['id'];
        $data = array_merge([
            'title' => 'Test Document',
            'guest_name' => 'Juan Dela Cruz',
            'guest_email' => 'juan@example.com',
            'guest_phone' => '09171234567',
            'district' => 'Iligan City',
            'department' => 'Records Unit',
            'purpose_id' => $purposeId,
        ], $overrides);

        $result = (new DocumentWorkflowService())->submitDocument($data);

        return ['id' => (int) $result['document_id'], 'tracking_code' => $result['tracking_code']];
    }

    /**
     * Load a seeded user by username.
     */
    public static function user(string $username): UserModel
    {
        $row = Database::getInstance()->query('SELECT id FROM users WHERE email = :e', [':e' => $username])->fetch();

        return UserModel::findById((int) $row['id']);
    }

    /**
     * Walk a pending document through the real workflow up to the requested status.
     * Valid statuses: in_transit, processing, ready_for_release, completed, declined.
     */
    public static function advanceTo(int $documentId, string $status, array $route = ['Cash Unit']): void
    {
        $service = new DocumentWorkflowService();
        $pin = TestDatabase::PIN;
        $records = self::user('records.unit');

        // Declining happens at intake, before any routing
        if ($status === 'declined') {
            $service->declineDocument($documentId, 'Declined by test fixture.', $records, $pin);
            return;
        }

        if (!in_array($status, ['in_transit', 'processing', 'ready_for_release', 'completed'], true)) {
            throw new InvalidArgumentException("Unsupported target status: {$status}");
        }

        // Intake finalizes the route
        $service->finalizeIntake($documentId, $route, $records, $pin);
        if ($status === 'in_transit') {
            return;
        }

        $trackingCode = Database::getInstance()->query('SELECT tracking_code FROM documents WHERE id = :id', [':id' => $documentId])->fetch()['tracking_code'];

        // Each department receives and completes in turn; the last step stops at 'processing' when requested
        foreach ($route as $index => $departmentName) {
            $staff = self::userForDepartment($departmentName);
            $service->scanDocument($trackingCode, $staff, $pin);

            if ($status === 'processing' && $index === count($route) - 1) {
                return;
            }
            $service->completeTask($documentId, '', $staff, $pin);
        }

        // Records receives the finished document for release
        $service->scanDocument($trackingCode, $records, $pin);
        if ($status === 'ready_for_release') {
            return;
        }

        $service->releaseDocument($documentId, $records, $pin);
    }

    /**
     * Find a user belonging to a department (the baseline seeds Cash, Personnel and Records).
     */
    private static function userForDepartment(string $departmentName): UserModel
    {
        $row = Database::getInstance()->query('SELECT u.id FROM users u JOIN departments d ON d.id = u.department_id WHERE d.name = :n ORDER BY u.id LIMIT 1', [':n' => $departmentName])->fetch();
        if (!$row) {
            throw new InvalidArgumentException("No seeded user for department {$departmentName}");
        }

        return UserModel::findById((int) $row['id']);
    }
}
