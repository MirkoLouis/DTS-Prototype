# DepEd Iligan - Document Tracking System (DTS) Prototype

This project is a comprehensive, production-ready prototype for a modern, web-based Document Tracking System (DTS) for the DepEd Division of Iligan City. Built with Laravel 12, it digitizes and streamlines the submission, tracking, and management of official documents through a sophisticated role-based workflow and a cryptographically secure audit trail.

---

## 📸 Screenshots

### 🌐 Public & Guest Portal
| Public Submission Portal | Tracking Dashboard |
|:---:|:---:|
| ![Submission](documentation/screenshots/Guest%20Welcome%20Page.png) | ![Tracking Dashboard](documentation/screenshots/Guest%20Track%20Page.png) |

### 🛠 Administrative Suite
| Admin Dashboard | Integrity Monitor | System Health |
|:---:|:---:|:---:|
| ![Analytics](documentation/screenshots/Admin%20Dashboard%20Page.png) | ![Integrity Monitor](documentation/screenshots/Admin%20Document%20Integrity%20Page.png) | ![System Health](documentation/screenshots/Admin%20System%20Page.png) |

### 📋 Operations & Staff Dashboards
| Records Officer Intake | Records Officer Statistics | Cryptographic Audit Trail |
|:---:|:---:|:---:|
| ![Intake Page](documentation/screenshots/Records%20Officer%20Intake%20Page.png) | ![RO Stats](documentation/screenshots/Records%20Officer%20Statistics%20Page.png) | ![Hash Chain Audit](documentation/screenshots/View%20Hash%20Chain%20Page.png) |

| Staff Tasks Dashboard | Staff Statistics |
|:---:|:---:|
| ![Staff Tasks](documentation/screenshots/Staff%20Tasks%20Page.png) | ![Staff Stats](documentation/screenshots/Staff%20Statistics%20Page.png) |

---

## 📄 Example Output

Explore generated document tracking forms and administrative reports:
-   **[Sample Tracking Form (PDF)](documentation/examples/document-tracking-form-DEPED-A84BC8C861.pdf):** A printable form given to guests with a unique tracking code and QR code.
-   **[Sample Historical Report (PDF)](documentation/examples/released-documents-24a8d2af-8610-4db2-b38b-938249269b3e.pdf):** A comprehensive summary of documents processed over a specific period.

---

## 🚀 Key Innovations

1.  **Trust Builder (Hash-Chaining):** An immutable, `sha256`-based chained ledger of all document actions. Every action is cryptographically linked to the previous one, ensuring a verifiable and tamper-proof audit trail.
2.  **AI Route Prediction:** A dynamic, keyword-driven system that suggests document routes based on the purpose of the request. The system "learns" from manual corrections made by Records Officers via a background weighted-keyword update job.
3.  **Enterprise-Scale Reporting:** A high-performance export system for PDF and CSV reports that utilizes a "chunk-and-merge" strategy, allowing it to handle 10,000+ records without memory exhaustion.
4.  **Physical Workflow Integration:** Integrated QR code system for physical document tracking. Documents require physical scans at each handoff (Intake, Departmental Receipt, and Releasing) to ensure accountability.

---

## ✨ Core Features

-   **Guest & Tracking Portals:** Public-facing submission forms and a multi-document tracking dashboard with an interactive "subway map" status view.
-   **Role-Based Access Control:** Secure, middleware-protected dashboards for **Admins**, **Records Officers**, and **Department Staff**.
-   **Full Document Lifecycle:** Manages the entire process from initial intake to departmental processing, return requests, and final releasing.
-   **Advanced Analytics:** A comprehensive "Bottleneck Detector" featuring time-series charts for throughput, departmental load distribution, and processing hotspots.
-   **QR Code Integration:** Automatic generation and camera-based scanning for efficient physical document handoffs.
-   **Database Security Suite:** Includes a **Backup Manager** for on-demand snapshots and the **System Health Monitor** for real-time integrity verification.
-   **Automated Maintenance:** Scheduled tasks for document pruning, database performance snapshots, and AI keyword weight updates.
-   **Interactive Route Editor:** A drag-and-drop interface for Records Officers to dynamically manage and modify document paths.
-   **Client Feedback:** Integrated 5-star rating system for completed documents to monitor service quality.
-   **Responsive Design:** Fully mobile-friendly dashboards for staff processing documents on the move.

---

## 🛠 Tech Stack

-   **Backend:** Laravel 12 (PHP 8.3)
-   **Database:** MySQL 8.0 (with time-series performance snapshots)
-   **Frontend:** Blade Templates, Tailwind CSS 4, Bootstrap 5 (Layout), Vanilla JS
-   **Analytics:** Chart.js
-   **Infrastructure:** Vite (HMR & Asset Bundling), Redis/Database Queue, mkcert (Local SSL)

---

## 📖 Project Documentation

For deep technical dives into specific system components, please refer to the files in the `documentation/` directory:

-   **Setup & Deployment:**
    -   [Setup & Installation Guide](documentation/Setup%20&%20Installation%20Guide.md)
    -   [System Deployment Guide](documentation/System%20Deployment%20Guide.md)
    -   [HTTPS & Local SSL Logic](documentation/HTTPS%20&%20Local%20SSL%20Logic.md)
-   **Core Logic:**
    -   [Document Lifecycle & Statuses](documentation/Document%20Lifecycle%20&%20Statuses.md)
    -   [Role-Based Access Control (RBAC) Logic](documentation/Role-Based%20Access%20Control%20(RBAC)%20Logic.md)
    -   [AI Route Prediction Logic](documentation/AI%20Route%20Prediction%20Logic.md)
-   **Security & Performance:**
    -   [Document Hashing & Chain Logic](documentation/Document%20Hashing%20&%20Chain%20Logic.md)
    -   [System Health & Analytics Logic](documentation/System%20Health%20&%20Analytics%20Logic.md)
    -   [Project Resilience & Fallback Strategies](documentation/Project%20Resilience%20&%20Fallback%20Strategies.md)
-   **Development Tools:**
    -   [Project Commands Guide](documentation/Project%20Commands%20Guide.md)
    -   [Data Generation & Simulation Logic](documentation/Data%20Generation%20&%20Simulation%20Logic.md)

---

## ⚡ Quick Start

1.  **Clone & Install:**
    ```bash
    git clone <repository-url>
    composer install && npm install
    ```
2.  **Environment Setup:** Follow the [Setup Guide](documentation/Setup%20&%20Installation%20Guide.md) to configure your `.env` and generate your local SSL certificates (`mkcert localhost`).
3.  **Database:**
    ```bash
    php artisan dts:migrate --devseed  # Simulates 10,000 documents over 5 years
    ```
4.  **Run Development Server:**
    ```bash
    composer run dev  # Starts Server, Vite, Queue, Logs, and Scheduler
    ```

---

## 🔐 Default Accounts

| Role | Email | Password |
|:---|:---|:---|
| **Administrator** | `admin@dts.com` | `password` |
| **Records Officer** | `records@dts.com` | `password` |
| **Staff: Cash Unit** | `cash.unit@dts.com` | `password` |
| **Staff: Admin Unit** | `administrative.unit@dts.com` | `password` |
| **Staff: Personnel** | `personnel.unit@dts.com` | `password` |
| **Staff: Supply Unit** | `supply.unit@dts.com` | `password` |
| **Staff: Budget Unit** | `budget.unit@dts.com` | `password` |
| **Staff: Accounting** | `accounting.unit@dts.com` | `password` |
| **Staff: Legal Unit** | `legal.unit@dts.com` | `password` |
| **Staff: Health & Nutrition** | `health.and.nutrition@dts.com` | `password` |
| **Staff: BAC Unit** | `bids.and.awards.committee.unit@dts.com` | `password` |
| **Staff: SDS Office** | `schools.division.superintendent.office@dts.com` | `password` |
| **Staff: ASDS Office** | `assistant.schools.division.superintendent.office@dts.com` | `password` |
| **Staff: CID** | `curriculum.implementation.division@dts.com` | `password` |
| **Staff: SGOD** | `school.governance.and.operations.division@dts.com` | `password` |

---

Developed for the **DepEd Division of Iligan City**.
