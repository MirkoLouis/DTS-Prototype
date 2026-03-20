# DepEd Iligan - Document Tracking System (DTS) Prototype

A comprehensive, production-ready prototype for a modern, web-based **Document Tracking System (DTS)** for the **DepEd Division of Iligan City**. Built with **Laravel 12**, it digitizes and streamlines the submission, tracking, and management of official documents through a sophisticated role-based workflow and a cryptographically secure audit trail.

---

## 📸 Project Screenshots

### 🌐 Public & Guest Portal
| Public Submission Portal | Tracking Dashboard |
|:---:|:---:|
| ![Submission](documentation/screenshots/Guest%20Welcome%20Page.png) | ![Tracking Dashboard](documentation/screenshots/Guest%20Track%20Page.png) |

### 🛠 Administrative & Security Suite
| Admin Dashboard | Integrity Monitor | System Health |
|:---:|:---:|:---:|
| ![Analytics](documentation/screenshots/Admin%20Dashboard%20Page.png) | ![Integrity Monitor](documentation/screenshots/Admin%20Document%20Integrity%20Page.png) | ![System Health](documentation/screenshots/Admin%20System%20Page.png) |

### 📋 Operations & Audit Trail
| Records Officer Intake | Cryptographic Ledger | Staff Tasks Dashboard |
|:---:|:---:|:---:|
| ![Intake Page](documentation/screenshots/Records%20Officer%20Intake%20Page.png) | ![Hash Chain Audit](documentation/screenshots/View%20Hash%20Chain%20Page.png) | ![Staff Tasks](documentation/screenshots/Staff%20Tasks%20Page.png) |

---

---
## 📄 Example Output

Explore generated document tracking forms and administrative reports:
-   **[Sample Tracking Form (PDF)](documentation/examples/document-tracking-form-DEPED-A84BC8C861.pdf):** A printable form given to guests with a unique tracking code and QR code.
-   **[Sample Historical Report (PDF)](documentation/examples/released-documents-24a8d2af-8610-4db2-b38b-938249269b3e.pdf):** A comprehensive summary of documents processed over a specific period.

---

## 🚀 Key Innovations

1.  **The Trust Builder (Independent Hash-Chaining):** An immutable, `sha256`-based chained ledger of all document actions. By utilizing independent chains (Micro-Sharding) per document, the system ensures O(log n) scalability and prevents system-wide bottlenecks (Kim & Kim, 2024).
2.  **Universal Non-Repudiation (Ed25519):** A high-security enforcement layer where every action is signed using a user's unique digital signature. Once recorded, it is mathematically impossible to deny authorization.
3.  **Nomadic HTTPS Infrastructure (mDNS):** A secure development environment utilizing mDNS (`.local`) hostnames. This allows cross-device access (e.g., iPhone/Laptop) for mobile QR scanning without needing to update IP addresses when switching networks.
4.  **Physical Workflow (QR Codes):** Integrated QR code system for physical document tracking. Requires physical scans at Intake, Receipt, and Releasing to ensure accountability between handlers.
5.  **AI Route Prediction (TF-IDF):** A dynamic, keyword-driven engine that suggests departmental routes based on document context. The system "learns" from expert corrections via a background learning job.
6.  **Enterprise-Scale Reporting:** A high-performance export system for PDF and CSV reports that utilizes a "chunk-and-merge" strategy, allowing it to handle 10,000+ records without memory exhaustion.
7.  **High-Performance Analytics:** Utilizes MySQL 8.0 Window Functions and intelligent caching to provide real-time throughput metrics and bottleneck detection without the "RAM Trap."

---

## ✨ Core Features

-   **Guest & Tracking Portals:** Public-facing submission forms and a multi-document tracking dashboard featuring an interactive "subway map" status view.
-   **Role-Based Access Control (RBAC):** Secure, middleware-protected dashboards tailored for **Admins**, **Records Officers**, and **Department Staff**.
-   **Full Document Lifecycle:** Comprehensive management from initial public submission to Records Office intake, multi-departmental processing, and final physical release.
-   **Advanced Analytics (Bottleneck Detector):** Real-time business intelligence with time-series charts for throughput, departmental load distribution, and processing hotspots.
-   **QR Code Integration:** Automatic tracking-form generation and high-speed camera-based scanning for efficient physical document handoffs.
-   **Cryptographic Security Suite:** Real-time integrity monitoring, automated hash-chain repair tools, and Ed25519 digital signature enforcement.
-   **Database Security & DevOps:** Integrated **Backup Manager** for on-demand snapshots and a **System Health Monitor** for technical oversight.
-   **Automated Maintenance:** Scheduled tasks for pruning old documents, capturing performance snapshots, and updating AI keyword weights.
-   **Interactive Route Editor:** A drag-and-drop interface allowing Records Officers to dynamically build and modify document processing paths.
-   **Non-Linear Routing (Return Requests):** Flexible workflow allowing departments to return documents to previous steps for corrections or re-processing.
-   **Client Feedback System:** Integrated 5-star rating and qualitative feedback system for completed documents to monitor service quality.
-   **Responsive & Mobile-Friendly:** Fully optimized dashboards for staff processing documents on mobile devices and tablets.
-   **Enterprise-Scale Reporting:** High-performance PDF/CSV export system utilizing a "chunk-and-merge" strategy for 10,000+ records.

---

## 🛠 Tech Stack

-   **Backend:** Laravel 12 (PHP 8.3 + Sodium)
-   **Database:** MySQL 8.0 (Optimized Buffer Pools)
-   **Frontend:** Tailwind CSS 4, Blade Templates, Vanilla JS, Chart.js
-   **Infrastructure:** Vite (HMR), Redis Queue, mkcert (Local SSL), local-ssl-proxy

---

## 📖 Deep Technical Documentation

Refer to the `documentation/` directory for in-depth technical guides:
- [**System Architecture**](documentation/ARCHITECTURE.md): Tech stack, Cryptographic Ledger, and AI Routing.
- [**Hardware Requirements**](documentation/HARDWARE_SPECS.md): High-concurrency specs and storage forecasting.
- [**User Guide**](documentation/USER_GUIDE.md): Role-based workflows and interface navigation.
- [**Quantum Safety**](documentation/QUANTUM_SAFETY.md): Post-quantum strategy and threat models.

---

## ⚡ Quick Start

1.  **Clone & Setup:**
    ```bash
    git clone <repository-url> <optional:folder-path>
    composer run setup  # Installs both PHP & NPM dependencies and initializes the system
    ```
2.  **Initialize Database:**
    ```bash
    composer run db:dev  # Seeds ~10,000 documents for testing
    ```
3.  **Launch Secure Environment:**
    To enable mobile QR scanning and HTTPS, run these in separate terminals:
    ```bash
    composer run dev    # Starts Server (Port 3050), Vite, Queue, and Scheduler
    composer run proxy  # Starts Secure HTTPS Bridge (Port 3051)
    ```

---

## 🔐 Default Accounts (Password: `password`)

| Role | Email |
|:---|:---|:---|
| **Administrator** | `admin@dts.com` |
| **Records Officer** | `records@dts.com` |
| **Staff: Cash Unit** | `cash.unit@dts.com` |
| **Staff: Admin Unit** | `administrative.unit@dts.com`
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
