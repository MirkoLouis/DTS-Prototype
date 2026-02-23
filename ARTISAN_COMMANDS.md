# Project Commands Guide

This guide provides an overview of the essential commands used to run, maintain, and develop this Laravel project.

## Composer Scripts

These scripts are defined in `composer.json` and provide convenient shortcuts for common development tasks.

### `composer setup`
```bash
composer setup
```
**What it does:** This command automates the entire project setup process. It runs the following commands in sequence:
1. `composer install`: Installs all PHP dependencies.
2. Creates a `.env` file from the `.env.example` template if it doesn't exist.
3. `php artisan key:generate`: Generates a new application encryption key.
4. `php artisan migrate --force`: Runs the database migrations.
5. `npm install`: Installs all Node.js dependencies.
6. `npm run build`: Compiles and bundles all frontend assets for production.

**When to use it:** Use this command after cloning the project for the first time to get your development environment ready.

### `composer run dev`
```bash
composer run dev
```
**What it does:** This single command uses `concurrently` to run all necessary development servers and watchers at once in a single terminal, with color-coded output for each process.
- `php artisan serve`: The PHP backend server.
- `php artisan queue:listen`: The queue worker for background jobs.
- `php artisan pail`: The real-time log viewer.
- `npm run dev`: The Vite frontend server.

**When to use it:** This is the recommended way to run the development environment, as it provides an integrated view of all system components.

### `composer test`
```bash
composer test
```
**What it does:** Runs the project's automated test suite using PHPUnit. It first clears the configuration cache to ensure tests run with the latest application state.

## Frontend Assets (npm)

This project uses [Vite](https://laravel.com/docs/vite) to handle its frontend assets (JavaScript and CSS/Sass).

### `npm run dev`
```bash
npm run dev
```
**What it does:** Starts the Vite development server to compile and refresh frontend assets during development. It watches for changes in your `resources/js` and `resources/css` files and instantly updates them in the browser.

### `npm run build`
```bash
npm run build
```
**What it does:** This command compiles and bundles all your frontend assets for production. It takes the code from your `resources` directory, optimizes it, and places the final, public-ready files in the `public/build` directory.

**When to use it:**
- Before deploying your application to a production server.
- After pulling new changes that include updates to JavaScript or CSS if you are not using the `dev` command.

## Artisan Commands (php artisan)

### Development Server

#### `php artisan serve`
```bash
php artisan serve
# Or with a specific host and port
php artisan serve --host=0.0.0.0 --port=8080
```
**What it does:** This command starts Laravel's built-in development server.
- `--host=0.0.0.0`: Makes the server accessible from other devices on your network.
- `--port=8080`: Changes the port from the default 8000.

### Database

#### `php artisan migrate`
```bash
php artisan migrate
```
**What it does:** Runs any pending migrations to update your database schema without affecting existing data.

#### `php artisan migrate:fresh --seed`
```bash
php artisan migrate:fresh --seed
```
**What it does:** This is a **destructive** development command. It drops all tables from your database, runs all migrations from the beginning, and then runs the database seeders to populate your database with initial data.
**When to use it:** During development to reset your database to a clean, predictable state. **Never run this in a production environment.**

### Caching & Optimization

*   `php artisan config:clear`: Clears the configuration cache.
*   `php artisan route:clear`: Clears the route cache.
*   `php artisan view:clear`: Clears the compiled view files.
*   `php artisan cache:clear`: Flushes the application's general cache.

### Queue Management

Background jobs (like report generation, database backups, and restoration) require a queue worker to be running to process tasks in the background.

*   `php artisan queue:work`: Starts a high-performance queue worker. It loads the application into memory once. **Crucial:** You must run `php artisan queue:restart` after making any code changes, or the worker will continue running old code from its memory.
*   `php artisan queue:listen`: Starts a worker that reloads the entire application for every single job. While slightly slower than `work`, it is **highly recommended for development** because it automatically picks up your code changes and is more stable for memory-intensive tasks like large PDF generation.
*   `php artisan queue:restart`: Signals all active `queue:work` processes to gracefully exit after they finish their current job, allowing them to be restarted with fresh code.

### Project-Specific Commands

This project includes several custom Artisan commands to manage specific features.

*   `php artisan documents:prune-pending`: A scheduled task (runs daily) to delete pending documents older than two weeks.
*   `php artisan dts:verify-integrity`: Verifies the integrity of the entire document log hash-chain. This is the "Trust Builder" tool.
*   `php artisan dts:corrupt-log {logId}`: **(For Testing)** Intentionally corrupts a `DocumentLog` entry to test the integrity verification.
*   `php artisan dts:rebuild-chain {logId}`: An administrative tool to rebuild the hash-chain for a document starting from a specific log ID.
*   `php artisan backup:run`: Triggers an on-demand database backup via a background job.
*   `php artisan dts:restore-database {filename}`: A custom command that dispatches a background job to restore the database from a specific backup file. This is typically initiated via the Backup Manager UI, not run manually.
*   **Report Generation**: The system uses a background job (`GenerateReportJob`) to process PDF and CSV exports. This ensures that large reports (e.g., 10,000+ documents) do not block the web server or time out.

### Running Integrity Tests
To verify the hash-chaining security mechanism, you can run the dedicated PHPUnit test suite:
```bash
php artisan test
```
This test will first verify a clean database, then intentionally corrupt a log using `dts:corrupt-log`, and finally assert that `dts:verify-integrity` correctly reports the tampering.

