<?php
use App\Modules\Auth\CredentialPolicy;

$title = 'Change Password';
ob_start();
?>
<h1 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
    Change Password
</h1>
<?php $header = ob_get_clean(); ?>

<?php ob_start(); ?>
<div class="py-10">
    <div class="px-4 sm:px-6 lg:px-8 xl:mx-[20vh] space-y-6">
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100 max-w-xl">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    <strong>Sign-in password:</strong> <?= e(CredentialPolicy::SIGN_IN_PASSWORD_DEFINITION) ?>
                </p>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    Use at least <?= CredentialPolicy::MIN_LENGTH_PASSWORD ?> characters, in any mix of letters, numbers and symbols. Commonly used passwords are refused.
                </p>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    <?= e(CredentialPolicy::SHARED_NOTE) ?>
                </p>

                <form action="<?= e(CredentialPolicy::CHANGE_PASSWORD_PATH) ?>" method="POST" class="mt-6 space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">

                    <div>
                        <label for="current_password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Current password *</label>
                        <input type="password" name="current_password" id="current_password" required autocomplete="current-password" class="mt-1 block w-full border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 rounded-md shadow-sm focus:border-accent-1 focus:ring-accent-1 sm:text-sm p-2 border">
                    </div>

                    <div>
                        <label for="new_password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">New password * (at least <?= CredentialPolicy::MIN_LENGTH_PASSWORD ?> characters)</label>
                        <input type="password" name="new_password" id="new_password" required minlength="<?= CredentialPolicy::MIN_LENGTH_PASSWORD ?>" autocomplete="new-password" class="mt-1 block w-full border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 rounded-md shadow-sm focus:border-accent-1 focus:ring-accent-1 sm:text-sm p-2 border">
                    </div>

                    <div>
                        <label for="new_password_confirmation" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Confirm new password *</label>
                        <input type="password" name="new_password_confirmation" id="new_password_confirmation" required minlength="<?= CredentialPolicy::MIN_LENGTH_PASSWORD ?>" autocomplete="new-password" class="mt-1 block w-full border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 rounded-md shadow-sm focus:border-accent-1 focus:ring-accent-1 sm:text-sm p-2 border">
                    </div>

                    <div class="flex items-center justify-end">
                        <button type="submit" class="inline-flex items-center px-4 py-2 bg-accent-1 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-accent-1-hover focus:outline-none focus:border-accent-1-border-active focus:ring focus:ring-accent-1-light transition">
                            Change password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); ?>

<?php require BASE_PATH . '/src/Views/layouts/app.php'; ?>
