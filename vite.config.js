import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import fs from 'fs';

export default defineConfig(({ mode }) => {
    // Load ALL environment variables from .env
    const env = loadEnv(mode, process.cwd(), '');

    // Force the Laravel plugin to see our nomad URL in the console
    process.env.APP_URL = env.APP_URL || env.VITE_APP_URL;

    return {
        // Set the base URL for Vite from your .env
        base: env.ASSET_URL || '',
        server: {
            host: '0.0.0.0',
            hmr: {
                host: env.VITE_HMR_HOST || 'localhost',
            },
            https: {
                key: fs.readFileSync('localhost-key.pem'),
                cert: fs.readFileSync('localhost.pem'),
            },
            cors: true,
        },
        plugins: [
            tailwindcss(),
            laravel({
                input: [
                    'resources/css/app.css',
                    'resources/js/app.js',
                    'resources/css/public.css',
                    'resources/js/bootstrap_public.js',
                    'resources/css/fonts.css'
                ],
                // Force Vite to use the URL we defined in .env
                valetTls: false,
                detectTls: false,
                refresh: true,
            }),
        ],
        css: {
            preprocessorOptions: {
                scss: {
                    quietDeps: true,
                },
            },
        },
    };
});
