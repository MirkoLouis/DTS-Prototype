# Windows Setup Guide for DTS Prototype

This guide will walk you through setting up the Document Tracking System (DTS) Prototype on a Windows machine after cloning the repository.

## 1. Prerequisites

Ensure you have the following installed on your Windows machine:

- **Git:** [Download here](https://git-scm.com/download/win)
- **PHP (8.2 or higher):** We recommend using **Laragon** or **XAMPP**.
  - **Laragon (Recommended):** [Download Laragon](https://laragon.org/download/). It manages PHP, MySQL, and Apache/Nginx easily and handles virtual hosts.
  - **XAMPP:** [Download XAMPP](https://www.apachefriends.org/index.html).
- **Composer:** [Download Composer](https://getcomposer.org/download/)
- **Node.js (v20 or higher) & NPM:** [Download Node.js](https://nodejs.org/)
- **MySQL:** Included with Laragon/XAMPP.

## 2. Verifying Prerequisites

Before proceeding, verify that each tool is correctly installed and added to your System PATH by running these commands in a terminal (PowerShell or Command Prompt):

| Tool | Command | Expected Output |
| :--- | :--- | :--- |
| **PHP** | `php -v` | `PHP 8.2.x` or higher |
| **Composer** | `composer --version` | `Composer version 2.x.x` |
| **Node.js** | `node -v` | `v20.x.x` or higher |
| **NPM** | `npm -v` | `10.x.x` or higher |
| **Git** | `git --version` | `git version 2.x.x.windows.x` |
| **MySQL** | `mysql --version` | `mysql  Ver 8.x.x` (or MariaDB equivalent) |

*Note: If a command is not recognized, you may need to restart your terminal or manually add the tool's installation folder to your Windows System Environment Variables (PATH).*

## 3. Git Configuration (CRITICAL)

To avoid issues with line endings (Windows uses CRLF, Linux uses LF), run this command in your terminal (PowerShell or Git Bash) before or after cloning:

```powershell
git config --global core.autocrlf true
```

## 4. Initial Setup

1.  **Clone the Repository:**
    ```bash
    git clone <repository-url>
    cd dts-prototype
    ```

2.  **Environment File:**
    Copy the example environment file to create your local `.env`.
    ```powershell
    copy .env.example .env
    ```

3.  **Install Dependencies:**
    ```bash
    composer install
    npm install
    ```

4.  **Generate Application Key:**
    ```bash
    php artisan key:generate
    ```

5.  **Database Configuration:**
    - Open your `.env` file and update the `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` to match your local MySQL setup (usually `root` with no password for Laragon/XAMPP).
    - Create the database in your MySQL manager (e.g., HeidiSQL, phpMyAdmin, or Laragon's "Database" button).

6.  **Run Migrations and Seeders:**
    ```bash
    php artisan migrate --seed
    ```

## 5. Local SSL Certificate (Required for Vite/QR Scanner)

The project requires HTTPS for features like the QR code scanner.

1.  **Install mkcert:**
    - **Using Chocolatey (easiest):** `choco install mkcert`
    - **Using Scoop:** `scoop install mkcert`
    - **Manual:** Download the `.exe` from [mkcert GitHub releases](https://github.com/FiloSottile/mkcert/releases), rename it to `mkcert.exe`, and add it to your System PATH.

2.  **Setup the local CA:**
    Open a terminal as **Administrator** and run:
    ```bash
    mkcert -install
    ```

3.  **Generate the Certificate:**
    In the project root directory, run:
    ```bash
    mkcert localhost
    ```
    This will create `localhost.crt` and `localhost.key` in your project folder.

## 6. Running the Application

For the best experience, run the processes in separate terminals:

- **Terminal 1 (PHP Server):** 
  ```bash
  php artisan serve --port=3001
  ```
- **Terminal 2 (Vite):** 
  ```bash
  npm run dev
  ```
- **Terminal 3 (Queue):** 
  ```bash
  php artisan queue:listen
  ```

Alternatively, use the built-in development command:
```bash
composer run dev
```

Access the application at: **`http://localhost:3001`**.

## 7. Troubleshooting

- **"Vite manifest not found":** Run `npm run dev` and keep it running while you browse the site.
- **"Your connection is not private":** Ensure you ran `mkcert -install` as Administrator and generated the `localhost.crt`/`.key` files in the project root.
- **MySQL Connection Refused:** Check that your MySQL service (XAMPP/Laragon) is running and the credentials in `.env` are correct.
