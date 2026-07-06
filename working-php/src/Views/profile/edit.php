<?php ob_start(); ?>
<h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
    Profile
</h2>
<?php $header = ob_get_clean(); ?>

<?php ob_start(); ?>
<div class="py-12">
    <div class="mx-[20vh] sm:px-6 lg:px-8 space-y-6">
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="p-4 bg-success-light text-success-active rounded-lg">
                <?= htmlspecialchars($_SESSION['success']) ?>
                <?php unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="p-4 bg-red-100 text-red-800 rounded-lg">
                <?= htmlspecialchars($_SESSION['error']) ?>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
            <div class="max-w-xl">
                <section>
                    <header>
                        <h2 class="text-lg font-medium text-gray-900">Profile Information</h2>
                        <p class="mt-1 text-sm text-gray-600">Update your account's profile information.</p>
                    </header>

                    <form method="post" action="/profile/update" class="mt-6 space-y-6">
                        <div>
                            <label class="block font-medium text-sm text-gray-700">Name</label>
                            <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" required class="border-gray-300 focus:border-accent-1 rounded-md shadow-sm mt-1 block w-full">
                        </div>

                        <div>
                            <label class="block font-medium text-sm text-gray-700">Email</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required class="border-gray-300 focus:border-accent-1 rounded-md shadow-sm mt-1 block w-full">
                        </div>

                        <div class="flex items-center gap-4">
                            <button type="submit" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">Save</button>
                        </div>
                    </form>
                </section>
            </div>
        </div>

        <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
            <div class="max-w-xl">
                <section>
                    <header>
                        <h2 class="text-lg font-medium text-gray-900">Security Key</h2>
                        <p class="mt-1 text-sm text-gray-600">Update your cryptographic security key for signing documents.</p>
                    </header>

                    <form method="post" action="/security-key" class="mt-6 space-y-6">
                        <div>
                            <label class="block font-medium text-sm text-gray-700">New Security Key (Private Key)</label>
                            <textarea name="private_key" rows="4" class="border-gray-300 focus:border-accent-1 rounded-md shadow-sm mt-1 block w-full" placeholder="-----BEGIN PRIVATE KEY-----..."></textarea>
                        </div>

                        <div class="flex items-center gap-4">
                            <button type="submit" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">Save Security Key</button>
                        </div>
                    </form>
                </section>
            </div>
        </div>

    </div>
</div>
<?php $content = ob_get_clean(); ?>
<?php require BASE_PATH . '/src/Views/layouts/app.php'; ?>
