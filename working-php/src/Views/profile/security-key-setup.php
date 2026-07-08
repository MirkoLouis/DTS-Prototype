<?php ob_start(); ?>
<h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
    Setup Digital Signature
</h2>
<?php $header = ob_get_clean(); ?>

<?php ob_start(); ?>
<div class="py-12">
    <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100">
                <h3 class="text-lg font-bold mb-4">Action Required: Setup Your Digital Signature</h3>
                <p class="mb-6 text-gray-600 dark:text-gray-400">
                    To interact with documents, you must generate a cryptographic Ed25519 digital signature. 
                    This signature ensures non-repudiation and secures the blockchain-like document ledger against tampering.
                </p>
                
                <form action="/security-key" method="POST">
                    <div class="mb-4">
                        <label for="pin" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Create a 6-digit Security PIN</label>
                        <input type="password" name="pin" id="pin" minlength="6" required class="mt-1 block w-full border-gray-300 dark:border-gray-600 rounded-md shadow-sm sm:text-sm p-2 border bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-accent-1 focus:border-accent-1">
                    </div>
                    <div class="mb-4">
                        <label for="pin_confirm" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Confirm Security PIN</label>
                        <input type="password" name="pin_confirm" id="pin_confirm" minlength="6" required class="mt-1 block w-full border-gray-300 dark:border-gray-600 rounded-md shadow-sm sm:text-sm p-2 border bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-accent-1 focus:border-accent-1">
                        <p class="mt-2 text-sm text-gray-500">This PIN will be used to encrypt your private key. You will need it every time you approve or process a document.</p>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" class="inline-flex items-center px-4 py-2 bg-accent-1 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-accent-1-hover active:bg-accent-1-active focus:outline-none focus:border-accent-1-border-active focus:ring focus:ring-accent-1-light transition">
                            Generate & Secure Key
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); ?>

<?php require BASE_PATH . '/src/Views/layouts/app.php'; ?>
