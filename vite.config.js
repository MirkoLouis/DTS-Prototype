import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/scss/bootstrap.scss', 'resources/js/bootstrap_public.js', 'resources/js/admin-dashboard.js'],
            refresh: true,
        }),
    ],
});
