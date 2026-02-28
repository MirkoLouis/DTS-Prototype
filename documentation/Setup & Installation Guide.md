# Setup & Installation Guide

This document is the primary guide for setting up the Document Tracking System (DTS) Prototype. It covers local development environments for Windows (Native), WSL2, and Linux.

## Table of Contents
1. [Prerequisites](#prerequisites)
2. [Option A: Windows Native (Laragon/XAMPP)](#option-a-windows-native-laragonxampp)
3. [Option B: Windows WSL2 (Recommended for Windows)](#option-b-windows-wsl2-recommended)
4. [Option C: Linux Native](#option-c-linux-native)
5. [Common Configuration (SSL & Git)](#common-configuration-ssl--git)
6. [Running the System: The "Four Pillars"](#running-the-system-the-four-pillars)
7. [Troubleshooting](#troubleshooting)

---

## Prerequisites

Regardless of your OS, ensure you have the following installed:
- **PHP 8.2+** (with `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `json`, `mbstring`, `openssl`, `pcre`, `pdo`, `tokenizer`, `xml`)
- **Composer 2.x**
- **Node.js 20+ & NPM**
- **MySQL 8.0+**
- **Git**
- **mkcert** (For local SSL certificates)

---

## Option A: Windows Native (Laragon/XAMPP)

1.  **Install Laragon (Recommended):** Laragon manages PHP, MySQL, and Nginx/Apache with ease.
2.  **Clone the Repository:**
    ```powershell
    git clone <repository-url>
    cd dts-prototype
    ```
3.  **Git Line Endings (CRITICAL):**
    ```powershell
    git config --global core.autocrlf true
    ```
4.  **Initial Setup:**
    ```powershell
    copy .env.example .env
    composer install
    npm install
    php artisan key:generate
    ```
5.  **Database:** Create a database named `dts_prototype` in Laragon/XAMPP and update `.env`.
6.  **Migrate & Seed:**
    - Development: `php artisan dts:migrate --devseed` (10,000+ docs)
    - Production: `php artisan dts:migrate --prodseed` (Clean slate)

---

## Option B: Windows WSL2 (Recommended)

Running Laravel inside **WSL2 (Ubuntu)** provides a much faster and more Linux-compatible experience on Windows.

1.  **Install WSL2 & Ubuntu:** `wsl --install -d Ubuntu`
2.  **Open Ubuntu Terminal:** Perform all setup inside the Ubuntu environment.
3.  **Install PHP & MySQL:**
    ```bash
    sudo apt update
    sudo apt install php8.2 php8.2-mysql php8.2-curl php8.2-xml php8.2-mbstring mysql-server
    ```
4.  **Follow Linux Setup:** See "Option C" below for the remaining steps.
5.  **Accessing from Windows:** Your files are at `\\wsl$\Ubuntu\home\<user>\dts-prototype`. Open this in VS Code for the best experience.

---

## Option C: Linux Native

1.  **Clone & Configure:**
    ```bash
    git clone <repository-url>
    cd dts-prototype
    cp .env.example .env
    ```
2.  **Automated Setup:**
    ```bash
    composer run setup
    ```
3.  **Database Seeding:**
    - `php artisan dts:migrate --devseed`

---

## Common Configuration (SSL & Git)

### 1. Local SSL Certificate (Required for QR Scanning)
Browsers block camera access unless the site is served over HTTPS.
1.  **Install mkcert:** (Linux: `apt install mkcert` | Windows: `choco install mkcert`)
2.  **Setup CA:** `mkcert -install` (Run as Admin on Windows)
3.  **Generate Certs:** In project root: `mkcert localhost`
    - This creates `localhost.crt` and `localhost.key` for Vite.

### 2. Vite "Priming"
Before the main app works, you **must** visit `https://localhost:5173` in your browser and accept the self-signed certificate. This allows your browser to download the secure CSS/JS assets.

---

## Running the System: The "Four Pillars"

For the system to be fully functional, you need four concurrent processes.

### The Standard Way (4 Terminals)
| Pillar | Command | Why? |
|:---|:---|:---|
| **Web Server** | `php artisan serve` | Handles all HTTP requests. |
| **Vite** | `npm run dev` | Compiles CSS/JS and provides HTTPS assets. |
| **Queue** | `php artisan queue:listen` | Generates PDF reports and runs AI learning. |
| **Scheduler** | `php artisan schedule:work` | Takes DB snapshots every 5 mins. |

### The "All-in-One" Way: `composer run dev`
The project includes a `composer run dev` script that uses **Concurrently** to run all functional pillars in one terminal.

**It runs:**
1.  `server` (`php artisan serve`)
2.  `queue` (`php artisan queue:listen`)
3.  `logs` (`php artisan pail`)
4.  `vite` (`npm run dev`)
5.  `schedule` (`php artisan schedule:work`)

This command ensures that the web server, frontend compiler, background jobs, real-time logs, and the database metrics scheduler are all active and synchronized.

---

## Troubleshooting

- **"Vite manifest not found":** Run `npm run dev` and ensure it's running.
- **"403 Forbidden":** Ensure you are accessing the correct dashboard (Admin vs Staff vs Officer).
- **"Camera error":** Ensure you accepted the SSL certificate at `https://localhost:5173`.
- **Performance Issues on Windows:** If using Windows Native (Option A) and things are slow, switch to Option B (WSL2).
