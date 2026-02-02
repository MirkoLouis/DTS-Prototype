# Project Commands Guide

This guide provides an overview of the essential `php artisan` and `npm` commands used to run, maintain, and develop this Laravel project.

## Frontend Assets (npm)

This project uses [Vite](https://laravel.com/docs/vite) to handle its frontend assets (JavaScript and CSS/Sass). The source files are located in `resources/js` and `resources/css`, and Vite compiles them into optimized files that the browser can understand.

### `npm run build`
```bash
npm run build
```
**What it does:** This command compiles and bundles all your frontend assets for production. It takes the code from your `resources` directory, optimizes it, and places the final, public-ready files in the `public/build` directory.

**When to use it:**
- After cloning the project for the first time.
- After pulling new changes that include updates to JavaScript or CSS.
- Before deploying your application to a production server.
This command is necessary because browsers cannot directly understand your source `.js` and `.scss` files, especially those containing JSX, Sass, or other pre-processor syntax. `npm run build` creates the final, browser-friendly versions.

### `npm run dev`

Starts the Vite development server to compile and refresh frontend assets during development.

### Cache Clearing Commands

These commands are essential for ensuring your application picks up the latest changes, especially after modifying configuration, routes, or views.

*   `php artisan config:clear`: Clears the configuration cache. Necessary after changes to `.env` or configuration files.
*   `php artisan route:clear`: Clears the route cache. Run this after any modifications to route files (e.g., `routes/web.php`).
*   `php artisan view:clear`: Clears the compiled view files. Useful if Blade templates are not updating after changes.
*   `php artisan cache:clear`: Flushes the application's cache. Useful for clearing any cached data that might be preventing changes from appearing.

### Queue Management Commands

These commands are crucial for managing background jobs processed by the Laravel queue system.

*   `php artisan queue:restart`: Signals all queue workers to gracefully exit after they finish their current job. This is essential for workers to pick up code changes.
*   `php artisan queue:work`: Starts a queue worker process that will process jobs from the default queue. This command will run indefinitely until manually stopped.

### Custom Artisan Commands


---

## Development Server (Artisan)

### `php artisan serve`
```bash
php artisan serve
# Or with a specific host and port
php artisan serve --host=0.0.0.0 --port=8080
```
**What it does:** This command starts Laravel's built-in development server. This is what allows you to access your application in the browser (by default at `http://127.0.0.1:8000`).
- `--host=0.0.0.0`: Makes the server accessible from other devices on your network (e.g., for testing on a mobile phone).
- `--port=8080`: Changes the port from the default 8000 to 8080.

---

## Database (Artisan)

Your application's database structure is defined in "migration" files located in `database/migrations`. These commands allow you to manage that structure.

### `php artisan migrate`
```bash
php artisan migrate
```
**What it does:** This command runs any pending migrations. If you have created a new migration file or pulled changes that include new migrations, this command will execute them to update your database schema without affecting your existing data.

### `php artisan migrate:fresh --seed`
```bash
php artisan migrate:fresh --seed
```
**What it does:** This is a powerful, **destructive** development command. It performs two actions:
1.  **`migrate:fresh`**: Drops (deletes) **all** tables from your database and then runs all migrations from the very beginning. This completely erases all your data.
2.  **`--seed`**: After the database is rebuilt, this flag tells Artisan to run the database "seeders" (located in `database/seeders`). Seeders populate your database with initial data, such as the default admin user, departments, etc.

**When to use it:** This command is extremely useful during development when you want to reset your database to a clean, predictable state. **Never run this command in a production environment.**

---

## Caching & Optimization (Artisan)

For performance, Laravel caches certain parts of the application, such as configuration files, routes, and views. During development, you sometimes need to clear these caches to see your changes.







---

## Project-Specific Commands (Artisan)

This project includes several custom Artisan commands to manage specific features like data integrity and automated tasks.

*   `php artisan documents:prune-pending`: A scheduled task (runs daily) to delete pending documents older than two weeks.
*   `php artisan dts:verify-integrity`: Verifies the integrity of the entire document log hash-chain. This is the "Trust Builder" tool that allows administrators to check for data tampering.
*   `php artisan dts:corrupt-log {logId}`: **(Development/Testing only)** Intentionally corrupts a specific `DocumentLog` entry. This command is used to simulate data tampering and test the effectiveness of the `dts:verify-integrity` tool. Replace `{logId}` with the ID of the log you wish to corrupt.
*   `php artisan dts:rebuild-chain {logId}`: An administrative tool to rebuild the hash-chain for a document starting from a specific log ID.
*   `php artisan backup:run`: Triggers an on-demand database backup using the `spatie/laravel-backup` package.
*   `php artisan dts:restore-database {filename}`: Restores the database from a specific backup file located in the storage directory.

### Running Integrity Tests
To verify the hash-chaining security mechanism, you can run the dedicated PHPUnit test suite:
```bash
php artisan test
```
This test will first verify a clean database, then intentionally corrupt a log using `dts:corrupt-log`, and finally assert that `dts:verify-integrity` correctly reports the tampering.

