# Deployment Guide: Document Tracking System (DTS)

This guide outlines the steps required to deploy the Document Tracking System for DepEd Iligan.

## 1. Server Requirements
*   **PHP:** 8.2 or higher
*   **Database:** MySQL 8.0 or MariaDB 10.4+
*   **Web Server:** Nginx/Apache (Linux) or IIS/Apache/XAMPP (Windows)
*   **Composer & Node.js/NPM**

## 2. Environment Configuration
1.  Clone the repository.
2.  Create `.env` from `.env.example`.
3.  Set critical production values:
    *   `APP_ENV=production`
    *   `APP_DEBUG=false`
    *   `APP_URL=https://your-domain.gov.ph`
    *   `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (Use strong credentials)
4.  Generate Key: `php artisan key:generate --force`

## 3. Application Setup
Run these commands in your project root:

```bash
# 1. Install Production PHP Dependencies
composer install --optimize-autoloader --no-dev

# 2. Build Frontend Assets
npm install
npm run build

# 3. Prepare Storage & Database
php artisan storage:link
# Use the custom command for clean production seeding
php artisan dts:migrate --prodseed
```

## 4. Platform-Specific Background Processes

### A. Linux (Ubuntu/Debian)
**1. Queue Worker (Supervisor):**
Create `/etc/supervisor/conf.d/dts-worker.conf`:
```ini
[program:dts-worker]
command=php /var/www/dts/artisan queue:work --sleep=3 --tries=3 --timeout=1200
autostart=true
autorestart=true
user=www-data
```
**2. Scheduler (Cron):**
Add to `crontab -e`:
```bash
* * * * * cd /var/www/dts && php artisan schedule:run >> /dev/null 2>&1
```
**3. Permissions:**
```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### B. Windows Server
**1. Queue Worker:**
You can use **NSSM** (Non-Sucking Service Manager) to run the queue as a Windows Service:
*   Path: `C:\php\php.exe`
Arguments: C:\inetpub\wwwroot\dts\artisan queue:work --sleep=3 --tries=3 --timeout=1200

**2. Scheduler (Task Scheduler):**
Create a Basic Task in Windows Task Scheduler:
*   **Trigger:** Daily (then edit to repeat every 1 minute).
*   **Action:** Start a Program.
*   **Program/script:** `php`
*   **Add arguments:** `C:\inetpub\wwwroot\dts\artisan schedule:run`
*   **Start in:** `C:\inetpub\wwwroot\dts`

**3. Permissions:**
Ensure the `IUSR` or `IIS_IUSRS` group has **Full Control** over the `storage` and `bootstrap/cache` folders.

## 5. Performance Optimizations
Run these after any update to the code:
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

## 6. Security Finalization
1.  **SSL:** Ensure HTTPS is active (Certbot for Linux, IIS Certificates for Windows).
2.  **Passwords:** Log in as `admin@dts.com` (password: `password`) and change it immediately.
3.  **Integrity Check:** Run `php artisan dts:verify-integrity` to confirm the initial database state.
