<?php $title = 'User Management'; ob_start(); ?>
<h1 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
    User Management
</h1>
<?php $header = ob_get_clean(); ?>

<?php ob_start(); ?>
<div class="py-10">
    <div class="px-4 sm:px-6 lg:px-8 xl:mx-[20vh] space-y-6">

        <?php if (isset($_SESSION['success'])): ?>
            <div class="bg-success-light border border-green-400 text-green-700 px-4 py-3 rounded relative" role="status">
                <span class="block sm:inline"><?= htmlspecialchars($_SESSION['success']) ?></span>
                <?php unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                <span class="block sm:inline"><?= htmlspecialchars($_SESSION['error']) ?></span>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg" id="users-section">
            <div class="p-6 text-gray-900 dark:text-gray-100">
                <div class="flex flex-col gap-3 sm:flex-row sm:justify-between sm:items-center mb-6">
                    <h2 class="text-2xl font-bold">All User Accounts</h2>
                    <a href="/users/create" class="inline-flex items-center px-4 py-2 bg-accent-1 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-accent-1-hover active:bg-accent-1-active focus:outline-none focus:border-accent-1-border-active focus:ring focus:ring-accent-1-light disabled:opacity-25 transition">
                        Create User
                    </a>
                </div>
                
                <?php 
                    $filterConfig = [
                        'search' => ['type' => 'text', 'label' => 'Search by Name or Email', 'placeholder' => 'Search...'],
                        'role' => [
                            'type' => 'select', 
                            'label' => 'Role', 
                            'options' => [
                                'all' => 'All Roles',
                                'admin' => 'Admin',
                                'officer' => 'Officer',
                                'staff' => 'Staff'
                            ]
                        ]
                    ];
                    require BASE_PATH . '/src/Views/components/table-filters.php'; 
                ?>

                <div id="users-table-body">
                    <?php
                        // Render one confirmed POST action (deactivate / reactivate) with the CSRF token
                        $userActionForm = function (string $action, string $message, string $label, string $classes): string {
                            return sprintf(
                                '<form action="%s" method="POST" class="inline-block ml-2 confirm-action" data-message="%s"><input type="hidden" name="csrf_token" value="%s"><button type="submit" class="%s">%s</button></form>',
                                htmlspecialchars($action),
                                htmlspecialchars($message),
                                htmlspecialchars($_SESSION['csrf_token'] ?? ''),
                                $classes,
                                htmlspecialchars($label)
                            );
                        };

                        foreach ($users as &$user) {
                            $roleColor = $user['role'] === 'admin' ? 'bg-red-100 text-red-800' : ($user['role'] === 'officer' ? 'bg-blue-100 text-blue-800' : 'bg-success-light text-success-active');
                            $user['role_html'] = sprintf('<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full %s uppercase">%s</span>', $roleColor, htmlspecialchars($user['role']));
                            
                            $user['account_html'] = sprintf('
                                <div class="flex items-center space-x-3">
                                    %s
                                    <div class="flex flex-col">
                                        <span class="font-medium text-gray-900 dark:text-gray-100">%s</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400 break-all">%s</span>
                                    </div>
                                </div>
                            ', copy_button($user['email'], 'Copy Email'), htmlspecialchars($user['name']), htmlspecialchars($user['email']));
                            
                            if (!empty($user['public_key'])) {
                                $user['signature_html'] = '
                                    <div class="flex items-center text-green-600">
                                        <svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="h-4 w-4 mr-1" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                        </svg>
                                        <span>Active</span>
                                    </div>
                                ';
                            } else {
                                $user['signature_html'] = '<span class="text-gray-400 italic text-xs">Not set</span>';
                            }

                            // Align action links to the left to maintain visual alignment with the column header
                            $userId = (int) $user['id'];
                            $actions = sprintf('<a href="/users/%d/edit" class="text-accent-1 hover:text-accent-1-active">Edit</a>', $userId);

                            // Deactivated accounts get a badge and can be reactivated
                            if (!empty($user['deactivated_at'])) {
                                $user['role_html'] .= ' <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300 uppercase">Deactivated</span>';
                                $actions .= $userActionForm("/users/{$userId}/reactivate", 'Reactivate this user? They will be able to sign in again.', 'Reactivate', 'text-accent-1 hover:text-accent-1-active');
                            } else {
                                // Every active account can have its credentials reset (opens the reset modal below)
                                $actions .= sprintf(
                                    '<button type="button" class="reset-credentials-trigger ml-2 text-red-500 hover:text-red-700" data-reset-user-id="%d" data-reset-user-name="%s">Reset</button>',
                                    $userId,
                                    htmlspecialchars($user['name'], ENT_QUOTES)
                                );

                                // Accounts other than the signed-in admin can be deactivated (never deleted)
                                if ($_SESSION['user_id'] != $userId) {
                                    $actions .= $userActionForm("/users/{$userId}/delete", 'Deactivate this user? They will no longer be able to sign in, but their signed history is kept.', 'Deactivate', 'text-red-600 hover:text-red-900');
                                }
                            }
                            $user['actions_html'] = '<div class="space-x-2 text-left">' . $actions . '</div>';
                        }
                        unset($user);

                        $tableConfig = [
                            'wrapper_classes' => 'overflow-x-auto mt-4',
                            'columns' => [
                                ['key' => 'account_html', 'label' => 'Account', 'width' => 'w-[52%]', 'type' => 'raw'],
                                ['key' => 'role_html', 'label' => 'Role', 'width' => 'w-1/12', 'type' => 'raw'],
                                ['key' => 'signature_html', 'label' => 'Signature', 'width' => 'w-2/12', 'type' => 'raw'],
                                ['key' => 'created_at', 'label' => 'Registered', 'width' => 'w-[6%]', 'type' => 'date'],
                                ['key' => 'actions_html', 'label' => 'Actions', 'width' => 'w-2/12', 'type' => 'raw']
                            ],
                            'data' => $users,
                            'empty_message' => 'No users match your criteria.'
                        ];
                        require BASE_PATH . '/src/Views/components/table.php';
                    ?>

                    <div class="mt-4">
                        <?php if (isset($paginator)) require BASE_PATH . '/src/Views/components/pagination.php'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Reset credentials modal: choose what to reset, then the admin's Security PIN is asked for (SigningModal) -->
<div id="reset-credentials-modal" role="dialog" aria-modal="true" aria-labelledby="reset-credentials-title" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900 bg-opacity-75">
    <div class="bg-white dark:bg-gray-800 p-6 mx-4 rounded-lg shadow-xl max-w-md w-full text-gray-900 dark:text-gray-100">
        <form id="reset-credentials-form" method="POST" class="sign-action" data-message="Enter your own Security PIN to confirm this reset." data-keeps-page="true">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="pin" class="sign-pin-input">
            <h2 id="reset-credentials-title" class="text-lg font-medium">Reset credentials</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">Reset for <strong id="reset-credentials-user-name"></strong>:</p>
            <fieldset class="mt-4 space-y-3">
                <legend class="sr-only">What to reset</legend>
                <label class="flex items-start gap-2 text-sm">
                    <input type="radio" name="target" value="password" required class="mt-1">
                    <span><strong>Sign-in password</strong>: sets it to the default password "<?= e(\App\Modules\Auth\CredentialPolicy::DEFAULT_PASSWORD) ?>"; the user is reminded to change it.</span>
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <input type="radio" name="target" value="pin" class="mt-1">
                    <span><strong>Security PIN</strong>: clears it; the user creates a new one at their next page view.</span>
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <input type="radio" name="target" value="both" class="mt-1">
                    <span><strong>Both</strong>: does both of the above.</span>
                </label>
            </fieldset>
            <div class="mt-6 flex justify-end space-x-2">
                <button type="button" id="reset-credentials-cancel" class="close-modal-btn px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 rounded-md focus:outline-none focus:ring-2 focus:ring-accent-1">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md focus:outline-none focus:ring-2 focus:ring-red-500">Confirm reset</button>
            </div>
        </form>
    </div>
</div>

<?php require BASE_PATH . '/src/Views/partials/signing-modal.php'; ?>
<?php $content = ob_get_clean(); ?>

<?php require BASE_PATH . '/src/Views/layouts/app.php'; ?>
