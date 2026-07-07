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
    - Update `config.php` or your environment variables to point to the correct database.

3.  **Launch Local Server:**
    ```bash
    php -S localhost:8000 -t public
    ```

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

Developed for the **DepEd Division of Iligan City**.
