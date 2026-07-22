# DepEd Iligan - Document Tracking System (DTS) Prototype - Pure PHP

A comprehensive, production-ready prototype for a modern, web-based **Document Tracking System (DTS)** for the **DepEd Division of Iligan City**. Built with **Pure Vanilla PHP** (No Frameworks) and **Tailwind CSS 3**, it digitizes and streamlines the submission, tracking, and management of official documents through a sophisticated role-based workflow and a cryptographically secure audit trail.

---

## 📸 Project Screenshots

*(See documentation for screenshots)*

---

## 🚀 Key Innovations

1.  **The Trust Builder (Independent Hash-Chaining):** An immutable, `sha256`-based chained ledger of all document actions.
2.  **Universal Non-Repudiation (Ed25519):** A high-security enforcement layer where every action is signed using a user's unique digital signature.
3.  **Physical Workflow (QR Codes):** Integrated QR code system for physical document tracking.
4.  **Framework-Free Performance:** Zero overhead from large frameworks. Every route and view is strictly executed via raw PHP and optimized raw SQL queries.
5.  **Centralized DB Operations:** Deprecated fragmented MVC models in favor of a centralized `DocumentWorkflowService` utilizing `PDO` and `SELECT ... FOR UPDATE` row-level locking to handle all heavy database writes safely.
6.  **Component-Driven UI:** Highly reusable UI components (`table.php`, `data-panel.php`, `qr-scanner.php`) mirroring modern frameworks like Laravel and React, but rendered natively in PHP.
7.  **High-Performance Analytics:** Utilizes MySQL 8.0 Window Functions and intelligent caching to provide real-time throughput metrics.

---

## ✨ Core Features

-   **Guest & Tracking Portals:** Public-facing submission forms and a multi-document tracking dashboard.
-   **Role-Based Access Control (RBAC):** Secure, session-based dashboards tailored for **Admins**, **Records Officers**, and **Department Staff**.
-   **Advanced Analytics (Bottleneck Detector):** Real-time business intelligence with time-series charts.
-   **QR Code Integration:** Automatic tracking-form generation and high-speed camera-based scanning.
-   **Cryptographic Security Suite:** Real-time integrity monitoring and Ed25519 digital signature enforcement.

---

## 🛠 Tech Stack

-   **Backend:** Pure Vanilla PHP 8.3
-   **Database:** MySQL 8.0 (Raw PDO SQL Queries, No ORM)
-   **Frontend:** Tailwind CSS 3 (Compiled via PostCSS), Vanilla JS, Chart.js
-   **Routing:** Custom Light-weight Router

---

## ⚡ Quick Start

1.  **Clone & Setup:**
    ```bash
    git clone <repository-url>
    cd working-php
    npm install
    npm run build
    ```
2.  **Initialize Database:**
    - Import the provided SQL schema into your local MySQL database.
    - Copy `.env.example` to `.env` and update it to point to the correct database credentials.

3.  **Launch Local Development Server:**
    ```bash
    composer run dev
    ```

4.  **Launch Background Worker:**
    Open a separate terminal window and run the background job processor:
    ```bash
    composer run worker
    ```

5.  **Database Seeding (Optional):**
    You can populate the database with realistic time-traveled data using the advanced API-driven seeder. It tracks real-time memory usage and elapsed execution time.

```bash
# Basic seed using defaults (10,000 docs, 100 chunk size, 50 concurrency)
composer run seed:dev

# Specify the total number of documents to create
composer run seed:dev 1000

# Specify documents, chunk size, and concurrency
# Arguments: [docs] [chunk_size] [concurrency]
composer run seed:dev 50000 250 50
```
*Note: The seeder performs complex "Time-Travel Retrofitting" directly in the database. Ensure your chunk size is always greater than or equal to your concurrency to prevent memory exhaustion.*

---

## ⏰ Automated Cron Jobs (Production Setup)

For production environments, the system relies on background cron jobs to perform routine maintenance and backups. 

1. Ensure the `cron` daemon is installed and running on your system.
2. Open your crontab editor:
   ```bash
   crontab -e
   ```
3. Add the following rules to automate daily database snapshots and metric rollups (which prevent the database metrics table from growing infinitely):

```bash
# Run daily database backups at 12:00 AM (Midnight)
0 0 * * * php /path/to/working-php/scripts/daily-backup.php >> /path/to/working-php/storage/logs/cron.log 2>&1

# Run database metric rollups at 1:00 AM to aggregate raw 5-minute telemetry into hourly chunks
0 1 * * * php /path/to/working-php/scripts/rollup-metrics.php >> /path/to/working-php/storage/logs/cron.log 2>&1
```
*Note: Replace `/path/to/working-php` with the actual absolute path to your repository.*

---

## 🌐 Mobile Testing & QR Scanning (HTTPS Requirement)

Modern browsers require a secure context (HTTPS) to access camera APIs for QR scanning. If you want to test the QR scanner on multiple devices (like your phone) on your local network without tweaking browser settings on every device, you can use **Method 1: The Infrastructure Approach (Self-Signed SSL on Nginx)**.

Operating within a Linux environment like Bazzite makes setting up a self-signed certificate straightforward.

### Step 1: Generate a Self-Signed Certificate
Open your terminal and use OpenSSL to generate a private key and a certificate.

```bash
sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout /etc/ssl/private/nginx-selfsigned.key -out /etc/ssl/certs/nginx-selfsigned.crt
```
*Note: It will prompt you for details like Country and State. You can just hit Enter to leave them blank or fill them in with dummy data.*

### Step 2: Update Your Nginx Configuration
Edit your project's Nginx server block to listen for SSL traffic on your designated port (or port 443).

```nginx
server {
    # Listen on your custom port with SSL enabled
    listen 8000 ssl;
    server_name your_local_ip_address; # e.g., 192.168.1.5

    # Point to the generated certs
    ssl_certificate /etc/ssl/certs/nginx-selfsigned.crt;
    ssl_certificate_key /etc/ssl/private/nginx-selfsigned.key;

    root /var/www/your_project/public;
    index index.php index.html index.htm;

    # ... keep your existing PHP location blocks down here ...
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
    }
}
```

### Step 3: Restart Nginx

```bash
sudo systemctl restart nginx
```

When you access `https://<your-ip>:8000`, the browser will throw an `ERR_CERT_AUTHORITY_INVALID` warning because the certificate wasn't issued by a recognized authority. Click **Advanced > Proceed to [IP] (unsafe)**. The camera API will now function perfectly because the connection is encrypted.

---

## 🔐 Default Accounts (Password: `password`)

| Role | Email |
|:---|:---|
| **Administrator** | `admin@dts.com` |
| **Records Officer** | `records@dts.com` |
| **Staff: Cash Unit** | `cash.unit@dts.com` |
| **Staff: Admin Unit** | `administrative.unit@dts.com` |
| **Staff: Personnel** | `personnel.unit@dts.com` |
| **Staff: Supply Unit** | `supply.unit@dts.com` |
| **Staff: Budget Unit** | `budget.unit@dts.com` |
| **Staff: Accounting** | `accounting.unit@dts.com` |
| **Staff: Legal Unit** | `legal.unit@dts.com` |
| **Staff: Health & Nutrition** | `health.and.nutrition@dts.com` |
| **Staff: BAC Unit** | `bids.and.awards.committee.unit@dts.com` |
| **Staff: SDS Office** | `schools.division.superintendent.office@dts.com` |
| **Staff: ASDS Office** | `assistant.schools.division.superintendent.office@dts.com` |
| **Staff: CID** | `curriculum.implementation.division@dts.com` |
| **Staff: SGOD** | `school.governance.and.operations.division@dts.com` |

---

## ⏰ Background Jobs (Cron)

For production environments, ensure you set up the nightly metric rollup job to prevent the `database_metrics` table from growing infinitely. Add this to your server's crontab (`crontab -e`):

```bash
# Run the metric rollup script every night at midnight
0 0 * * * php /path/to/your/project/working-php/scripts/rollup-metrics.php >> /var/log/dts-rollup.log 2>&1
```

---

Developed for the **DepEd Division of Iligan City**.
