# DepEd Iligan - Document Tracking System (DTS) Prototype

A modern, production-ready web application for digitizing, tracking, and managing official documents for the **DepEd Division of Iligan City**. It features role-based workflow routing, real-time analytics, physical QR code tracking, and a cryptographically secure audit trail powered by SHA-256 hash-chaining and Ed25519 digital signatures.

---

## ⚡ Architectural Evolution: The Shift to Pure Vanilla PHP

This repository contains two distinct implementations of the system, reflecting a deliberate architectural evolution:

```
DTS Prototype/
├── working-php/     <-- ACTIVE PRODUCTION CODEBASE (Pure Vanilla PHP 8.3)
└── backup-laravel/  <-- HISTORICAL REFERENCE CODEBASE (Original Laravel 12 Build)
```

### Why Port to Pure Vanilla PHP 8.3?

1. **Framework-Free Performance:** Eliminates framework bootstrapping overhead, delivering near-instant page loads and sub-millisecond raw PDO database query execution.
2. **Zero-Dependency Core:** Runs natively using pure PHP 8.3 and MySQL 8.0 without heavy framework runtime dependencies.
3. **Pessimistic Locking & Transaction Safety:** Centralizes database state mutations inside [DocumentWorkflowService.php](file:///home/mirkolouis/Documents/DTS%20Prototype/working-php/src/Services/DocumentWorkflowService.php) using explicit `PDO` transactions and `SELECT ... FOR UPDATE` row-level locking to eliminate race conditions under concurrent loads.
4. **Container-Native Scheduling:** Replaced host `cron` daemons with a unified CLI worker ([console.php](file:///home/mirkolouis/Documents/DTS%20Prototype/working-php/console.php)) featuring an internal task scheduler loop for telemetry sampling, TAT backfilling, and metrics rollups inside containerized environments (Distrobox / Docker).

---

## 📸 System Screenshots

*(Place screenshot images in `working-php/documentation/screenshots/`)*

### Public & Guest Portal
| Public Submission Portal | Multi-Document Tracking Dashboard |
|:---:|:---:|
| ![Public Submission](working-php/documentation/screenshots/guest_submission.png) | ![Tracking Dashboard](working-php/documentation/screenshots/guest_tracking.png) |

### Administrative & Security Suite
| Admin Overview & Analytics | Integrity Monitor & Tamper Control | System Health & Performance |
|:---:|:---:|:---:|
| ![Admin Dashboard](working-php/documentation/screenshots/admin_dashboard.png) | ![Integrity Monitor](working-php/documentation/screenshots/integrity_monitor.png) | ![System Health](working-php/documentation/screenshots/system_health.png) |

### Operational Workflows & Audit Trail
| Records Officer Intake Portal | Digital Hash-Chain Ledger | Department Staff Tasks |
|:---:|:---:|:---:|
| ![Records Intake](working-php/documentation/screenshots/officer_intake.png) | ![Hash Chain Audit](working-php/documentation/screenshots/hash_chain_audit.png) | ![Staff Tasks](working-php/documentation/screenshots/staff_tasks.png) |

---

## 📄 Example Outputs & Generated Reports

Example document tracking forms and administrative export reports are located in [working-php/documentation/examples](file:///home/mirkolouis/Documents/DTS%20Prototype/working-php/documentation/examples):

- **Sample Tracking Form (PDF):** Generated PDF containing submitter information, document requirements, and a scannable QR code. *(Place sample PDF in `working-php/documentation/examples/`)*
- **Sample Release Report (XLSX):** Streaming Excel export of completed document throughput generated via `OpenSpout`. *(Place sample XLSX in `working-php/documentation/examples/`)*

---

## 📖 System Documentation & Manuals

Comprehensive user manuals are provided in [working-php/documentation/user_manuals](file:///home/mirkolouis/Documents/DTS%20Prototype/working-php/documentation/user_manuals):

- 📘 **[Administrator Manual](file:///home/mirkolouis/Documents/DTS%20Prototype/working-php/documentation/user_manuals/admin_manual.md):** Account management, database performance, integrity checks, backups, and snapshot Auto-Resolve.
- 📗 **[Records Officer Manual](file:///home/mirkolouis/Documents/DTS%20Prototype/working-php/documentation/user_manuals/officer_manual.md):** Document intake, route finalization, QR tracking form printing, camera scanning, and releasing.
- 📙 **[Department Staff Manual](file:///home/mirkolouis/Documents/DTS%20Prototype/working-php/documentation/user_manuals/staff_manual.md):** Departmental task processing, physical folder receipt, action remarks, and digital signatures.
- 🔐 **[Cryptographic Logs Explained](file:///home/mirkolouis/Documents/DTS%20Prototype/working-php/documentation/CRYPTOGRAPHIC_LOGS_EXPLAINED.md):** How hash-chain logs work (mechanics), why seals/signatures match administrative chain-of-custody needs, and cryptography vs encryption.
- 🎯 **[Value Proposition & Multi-Stakeholder Pitch](file:///home/mirkolouis/Documents/DTS%20Prototype/working-php/documentation/PITCH_AND_VALUE_PROPOSITION.md):** Architectural value propositions and technical pitches for IT Admins, Executives, Cryptographers, Office Staff, and Citizens.

---

## ⚡ Quick Start (`working-php`)

1. **Navigate to the Active Codebase:**
   ```bash
   cd working-php
   ```

2. **Install Dependencies & Build Assets:**
   ```bash
   npm install
   npm run build
   composer install
   ```

3. **Configure Environment:**
   ```bash
   cp .env.example .env
   # Edit .env to set DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD
   ```

4. **Initialize & Seed Production Database:**
   ```bash
   composer run seed:prod
   ```

5. **Launch Local Server & Background Worker:**
   ```bash
   # Starts the Web Server, PostCSS Watcher, and Async Queue Worker seamlessly
   composer run dev
   ```

---

## 🌱 Database Seeding Commands (`working-php/scripts/seeders`)

- **Production Setup (`composer run seed:prod` / `node scripts/seeders/setup-production-db.js`):** Builds database tables from `database.sql` and seeds 14 DepEd departments, baseline document purposes, and initial user accounts.
- **High-Speed Direct Database Seeder (`composer run seed:fast [docs]` / `php scripts/seeders/fast-seed.php`):** Generates up to 50,000+ document logs directly in MySQL in seconds by pre-decrypting Ed25519 keys into RAM.
- **Slow-Speed API Seeder (`composer run seed:dev [docs] [chunk] [concurrency]` / `node scripts/seeders/seed.js`):** Dispatches live HTTP requests against the web server to simulate multi-user traffic and session handling, also functions as a concurrency test.

---

## 🛠 Script Directory Guide (`working-php/scripts`)

| Command / Script | Purpose |
| :--- | :--- |
| `composer run worker` | Runs [console.php](file:///home/mirkolouis/Documents/DTS%20Prototype/working-php/console.php) background job processor and telemetry scheduler. |
| `composer run dev` | Runs [scripts/dev.php](file:///home/mirkolouis/Documents/DTS%20Prototype/working-php/scripts/dev.php) to start local dev server & Tailwind watcher. |
| `composer run corrupt` | Runs [scripts/corrupt.php](file:///home/mirkolouis/Documents/DTS%20Prototype/working-php/scripts/corrupt.php) to simulate database tampering and test JIT Active Guard auto-freeze. |
| `php scripts/tune-database.php` | Analyzes system memory and outputs optimal MySQL `my.cnf` buffer pool configurations. |
| `php scripts/generate-keys.php` | CLI tool for generating missing Ed25519 digital signature keypairs. |
| `php scripts/concurrency_test.php` | Evaluates pessimistic locking performance under high-concurrency transaction loads. |

---

## 🔐 Default Test Accounts (Password: `password`)

| Role | Username |
|:---|:---|
| **Administrator** | `admin` |
| **Records Officer** | `records.unit` |
| **Staff: Cash Unit** | `cash.unit` |
| **Staff: Admin Unit** | `administrative.unit` |
| **Staff: Personnel** | `personnel.unit` |
| **Staff: Supply Unit** | `supply.unit` |
| **Staff: Budget Unit** | `budget.unit` |
| **Staff: Accounting** | `accounting.unit` |
| **Staff: Legal Unit** | `legal.unit` |
| **Staff: Health & Nutrition** | `health.and.nutrition` |
| **Staff: BAC Unit** | `bids.and.awards.committee.unit` |
| **Staff: SDS Office** | `schools.division.superintendent.office` |
| **Staff: ASDS Office** | `assistant.schools.division.superintendent.office` |
| **Staff: CID** | `curriculum.implementation.division` |
| **Staff: SGOD** | `school.governance.and.operations.division` |

---

Developed for the **DepEd Division of Iligan City**.
