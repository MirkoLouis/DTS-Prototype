# DTS Operations & DevOps

## Summary
A comprehensive guide for developers and system administrators to manage and deploy the Document Tracking System (DTS). This document covers the multi-threaded dev environment, specialized project commands, and local SSL management for secure cross-device testing.

## Table of Contents
1. [Setup & Installation](#1-setup--installation)
2. [Project Commands Matrix](#2-project-commands-matrix)
3. [Local SSL Management (HTTPS)](#3-local-ssl-management-https)
4. [Deployment Checklist](#4-deployment-checklist)
5. [Data Simulation & Testing](#5-data-simulation--testing)
6. [Glossary of Terms](#6-glossary-of-terms)

---

## 1. Setup & Installation

The DTS is designed for Linux (Ubuntu/Fedora) and Windows (WSL2) environments. It uses a **Multi-Threaded Development Architecture** via CPU Core Pinning.

### The 5-Pillar Dev Environment
Instead of running one big program, the `composer dev` command starts **5 Specialized Teams** (processes) that work together in parallel:
1.  **The Engine (Server)**: Handles the main website (Port 3050).
2.  **The Heavy Lifter (Queue)**: Handles things that take a long time, like generating PDF reports or teaching the AI.
3.  **The Watchman (Logs)**: Shows you exactly what the server is thinking in real-time.
4.  **The Stylist (Vite)**: Instantly updates the website's appearance when you change CSS or JavaScript files.
5.  **The Secretary (Schedule)**: Remembers to do recurring tasks, like deleting old pending documents or taking performance snapshots.

---

## 2. Project Commands Matrix

| Command | Category | Simplified Description |
|:---|:---|:---|
| **`composer dev`** | Daily Work | Starts the 5 pillars. |
| **`composer prod`** | Performance | Test the system as if it's in the real world (multi-threaded). |
| **`composer db:dev`** | Database | Creates 10,000 "fake" documents for stress testing. |
| **`composer db:prod`** | Database | Creates a clean, empty system ready for real work. |
| **`composer proxy`** | Security | Starts the "Secure Bridge" for mobile QR scanning. |
| **`php artisan dts:verify-integrity`** | Security | Runs a full audit of the cryptographic ledger. |
| **`php artisan dts:tune-db`** | Performance | Injects "Turbo" settings into the database memory. |

---

## 3. Local SSL Management (HTTPS)

Modern web browsers (like Chrome and Safari) require a **Secure Connection (HTTPS)** to access your device's camera. This is essential for testing the QR Scanner on your phone.

### Nomadic Infrastructure (mDNS)
We use a **Nomadic Setup** that allows your phone to talk to your computer using its name (e.g., `mirkolouis.local`) instead of its IP address. This works even if your IP changes when you move between different Wi-Fi networks.

### The Secure Development Proxy
Since the built-in development server doesn't support HTTPS directly, we use a **Secure Bridge**:
- **Internal Server**: Runs on a standard "Open" port (3050).
- **Secure Proxy**: Uses `local-ssl-proxy` to wrap the Open port in an HTTPS "Cloak" (Port 3051) using your `mkcert` certificates.

---

## 4. Deployment Checklist

To ensure a stable production deployment for DepEd Iligan:
1.  **SSL First**: Always use **HTTPS**. The camera scanner won't work without it.
2.  **Supervisor**: Use a tool called **Supervisor** to make sure the "Heavy Lifter" (Queue) is always running. If it crashes, Supervisor will restart it instantly.
3.  **Optimization**: Run `php artisan optimize`. This tells the server to "memorize" its configuration, making it up to 2x faster.
4.  **Task Scheduler**: Set up a **Cron Entry** to tell the system to check its "To-Do" list every minute.

---

## 5. Data Simulation & Testing

DTS includes a "Time-Traveling" **Historical Data Simulator**.

### Why Simulate Data?
- **Stress Testing**: We use `DocumentSeeder` to create 10,000 documents spread across the last 5 years.
- **Realistic Trends**: This "fake" data has realistic timestamps. It simulates documents being intaked at 8:00 AM and released at 4:00 PM, allowing us to see realistic graphs in the Admin Dashboard before the system even goes live.

---

## 6. Glossary of Terms

*   **Baseline Scan**: The first security audit performed on a new database to ensure the "Genesis" chain is healthy.
*   **Cron Entry**: A simple instruction to the server's OS to perform a specific task (like running the scheduler) at a specific time or interval.
*   **HMR (Hot Module Replacement)**: A way for the system to update its styling instantly without you needing to press "Refresh."
*   **mDNS**: "Multicast DNS." A way for your computer to say "I am here" to other devices on the same Wi-Fi using a name (like `.local`).
*   **Nomadic Architecture**: A system setup that is designed to work across different locations and devices without needing manual reconfiguration.
*   **Proxy**: A "Middleman" server that sits between the user and the main application, often used to add a layer of security (HTTPS).
*   **SSL/TLS**: The technology that creates the "Padlock" icon in your browser, ensuring all data sent between you and the server is encrypted.
*   **Supervisor**: A program that watches over other programs (like the queue worker) and restarts them if they fail.
*   **Taskset**: The Linux command that pins specific work to specific CPU cores.
