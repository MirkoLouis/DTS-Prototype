<?php

namespace App\Modules\Users;

use App\Common\Database\Database;
use App\Common\Validation\Validator;
use App\Modules\Auth\CredentialPolicy;
use App\Modules\System\SystemAuditService;

class UserController
{
    public function index()
    {
        $db = Database::getInstance();
        $page = $_GET['page'] ?? 1;
        
        $where = [];
        $params = [];
        
        if (!empty($_GET['search'])) {
            $where[] = "(name LIKE :search OR email LIKE :search)";
            $params[':search'] = '%' . $_GET['search'] . '%';
        }
        
        // Narrow by role only for a real role; "all" and any other value list every role
        $role = $_GET['role'] ?? '';
        if (is_string($role) && in_array($role, ['admin', 'officer', 'staff'], true)) {
            $where[] = "role = :role";
            $params[':role'] = $role;
        }
        
        $whereSql = count($where) > 0 ? "WHERE " . implode(' AND ', $where) : "";
        
        $countSql = "SELECT COUNT(*) as total FROM users $whereSql";
        $countStmt = $db->query($countSql, $params);
        $totalItems = $countStmt->fetch()['total'] ?? 0;
        
        $paginator = new \App\Common\Utils\Paginator($totalItems, 15, $page, '?page=(:num)&' . http_build_query(array_diff_key($_GET, ['page' => ''])));
        
        $sql = "SELECT id, name, email, role, department_id, created_at, public_key, deactivated_at FROM users $whereSql ORDER BY name ASC LIMIT {$paginator->getLimit()} OFFSET {$paginator->getOffset()}";
        $stmt = $db->query($sql, $params);
        $users = $stmt->fetchAll();
        
        require BASE_PATH . '/src/Views/admin/users/index.php';
    }

    public function create()
    {
        $db = Database::getInstance();
        $departments = $db->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();
        require BASE_PATH . '/src/Views/admin/users/create.php';
    }

    public function store()
    {
        [$errors, $validated] = Validator::validate($_POST, [
            'name' => 'required',
            'email' => 'required',
            'role' => 'required'
        ]);

        if (!empty($errors)) {
            $_SESSION['error'] = validation_error_message($errors);
            header("Location: /users/create");
            exit;
        }

        $name = $validated['name'];
        $email = $validated['email'];
        // A role that is not a plain string (e.g. role[]=x) is treated as invalid by UserService::departmentFor
        $role = is_string($validated['role']) ? $validated['role'] : '';

        // The role decides which department the account needs; a rule violation returns to the form
        try {
            $departmentId = (new UserService())->departmentFor($role, $_POST['department_id'] ?? '');
        } catch (\DomainException $e) {
            $_SESSION['error'] = $e->getMessage();
            header('Location: /users/create');
            exit;
        }

        $db = Database::getInstance();
        
        // Check if email exists
        $stmt = $db->query("SELECT id FROM users WHERE email = :email", [':email' => $email]);
        if ($stmt->fetch()) {
            $_SESSION['error'] = 'Email already in use.';
            header('Location: /users/create');
            exit;
        }

        // A new account starts with the default password and the reminder flag; the user chooses their own at /account/password
        $sql = "INSERT INTO users (name, email, password, must_change_password, role, department_id, created_at, updated_at)
                VALUES (:name, :email, :password, 1, :role, :department_id, NOW(), NOW())";

        $db->query($sql, [
            ':name' => $name,
            ':email' => $email,
            ':password' => UserCredentialService::defaultPasswordHash(),
            ':role' => $role,
            ':department_id' => $departmentId
        ]);

        $_SESSION['success'] = 'User created with the default password "' . CredentialPolicy::DEFAULT_PASSWORD . '". They are reminded to change it when they sign in.';
        header('Location: /users');
        exit;
    }

    public function edit($id)
    {
        $db = Database::getInstance();
        $user = $db->query("SELECT * FROM users WHERE id = :id", [':id' => $id])->fetch();
        
        if (!$user) {
            $_SESSION['error'] = 'User not found.';
            header('Location: /users');
            exit;
        }

        $departments = $db->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();
        require BASE_PATH . '/src/Views/admin/users/edit.php';
    }

    public function update($id)
    {
        [$errors, $validated] = Validator::validate($_POST, [
            'name' => 'required',
            'email' => 'required',
            'role' => 'required'
        ]);

        if (!empty($errors)) {
            $_SESSION['error'] = validation_error_message($errors);
            header("Location: /users/{$id}/edit");
            exit;
        }

        $name = $validated['name'];
        $email = $validated['email'];
        // A role that is not a plain string (e.g. role[]=x) is treated as invalid by UserService::departmentFor
        $role = is_string($validated['role']) ? $validated['role'] : '';

        // The role decides which department the account needs; a rule violation returns to the form
        try {
            $departmentId = (new UserService())->departmentFor($role, $_POST['department_id'] ?? '');
        } catch (\DomainException $e) {
            $_SESSION['error'] = $e->getMessage();
            header("Location: /users/{$id}/edit");
            exit;
        }

        $db = Database::getInstance();

        // Check if email exists for other users
        $stmt = $db->query("SELECT id FROM users WHERE email = :email AND id != :id", [
            ':email' => $email,
            ':id' => $id
        ]);
        
        if ($stmt->fetch()) {
            $_SESSION['error'] = 'Email already in use by another account.';
            header("Location: /users/{$id}/edit");
            exit;
        }

        // An edit never changes a password; an admin changes their own at /account/password
        $db->query("UPDATE users SET name = :name, email = :email, role = :role, department_id = :department_id, updated_at = NOW() WHERE id = :id", [
            ':name' => $name,
            ':email' => $email,
            ':role' => $role,
            ':department_id' => $departmentId,
            ':id' => $id
        ]);

        $_SESSION['success'] = 'User updated successfully.';
        header('Location: /users');
        exit;
    }

    /**
     * Deactivate an account (DELETE /users/{id} and POST /users/{id}/delete); accounts are never deleted.
     */
    public function destroy($id)
    {
        // The service refuses self-deactivation; surface its message as a flash error
        try {
            (new UserService())->deactivate((int) $id, (int) $_SESSION['user_id']);
            $_SESSION['success'] = 'User deactivated. Their signed history is kept and they can no longer sign in.';
        } catch (\DomainException $e) {
            $_SESSION['error'] = $e->getMessage();
        }

        header('Location: /users');
        exit;
    }

    /**
     * Reactivate a deactivated account.
     */
    public function reactivate($id)
    {
        (new UserService())->reactivate((int) $id);

        $_SESSION['success'] = 'User reactivated.';
        header('Location: /users');
        exit;
    }

    /**
     * Reset a user's sign-in password, Security PIN or both (POST /users/{id}/reset-credentials).
     *
     * The admin proves who they are with their own Security PIN, and each reset is recorded in the system audit log.
     */
    public function resetCredentials($id)
    {
        $userId = (int) $id;
        $target = $_POST['target'] ?? null;

        // Only the three known targets are accepted; checked first so a bad request never counts as a wrong PIN
        if (!UserCredentialService::isValidTarget($target)) {
            $_SESSION['error'] = 'Choose what to reset: the sign-in password, the Security PIN, or both.';
            header('Location: /users');
            exit;
        }

        // The account must exist
        $account = Database::getInstance()->query("SELECT name FROM users WHERE id = :id", [':id' => $userId])->fetch();
        if (!$account) {
            $_SESSION['error'] = 'User not found.';
            header('Location: /users');
            exit;
        }

        // The admin's own Security PIN, checked like any signed action (wrong PINs count toward the lockout)
        $submittedPin = is_string($_POST['pin'] ?? null) ? $_POST['pin'] : '';
        $adminId = (int) $_SESSION['user_id'];
        $refusal = PinConfirmationService::refusal($adminId, $submittedPin, "ADMIN: Reset credentials|{$userId}|{$target}");
        if ($refusal !== null) {
            $_SESSION['error'] = $refusal;
            header('Location: /users');
            exit;
        }

        (new UserCredentialService())->reset($userId, $target);
        SystemAuditService::record('user_credentials_reset', ['target_user_id' => $userId, 'target' => $target, 'user_id' => $adminId]);

        $_SESSION['success'] = 'Reset done for ' . $account['name'] . ': ' . self::RESET_SUMMARY[$target] . '.';
        header('Location: /users');
        exit;
    }

    // What each reset target did, for the confirmation message
    private const RESET_SUMMARY = [
        UserCredentialService::TARGET_PASSWORD => 'the sign-in password is now the default password',
        UserCredentialService::TARGET_PIN => 'the Security PIN was cleared and must be created again',
        UserCredentialService::TARGET_BOTH => 'the sign-in password is now the default password and the Security PIN was cleared',
    ];
}
