# CHAPTER 3
## RESEARCH METHODOLOGY

This chapter details the research design and development methodology used to create the Document Tracking System (DTS) for DepEd Iligan City. The study follows the **Design Science Research (DSR)** framework to address institutional problems through the creation of an innovative technological artifact, utilizing an **Agile/Scrum** approach for its iterative development.

### 3.1 Research Design (DSR Framework)
The study is structured according to the six phases of the Design Science Research process (Peffers et al., 2007), ensuring that the developed system is both scientifically grounded and practically relevant.

1.  **Problem Identification:** Triggered by the December 2025 site visit, identifying race conditions in kiosks and the proliferation of "shadow systems" (Google Sheets) at DepEd Iligan.
2.  **Define Objectives:** Establishing the need for a cryptographically secure, unified tracking system built on PHP (as per IT Department constraints).
3.  **Design and Development:** The iterative creation of the DTS prototype, integrating SHA-256 hash-chaining and Ed25519 signatures.
4.  **Demonstration:** Presenting Version 1.0.0 to the Records Department (the primary client) to verify its utility in a real-world workflow.
5.  **Evaluation:** Validating the system through automated integrity tests, performance benchmarking (1 million document scale), and expert feedback from the DepEd IT Department.
6.  **Communication:** Documenting the technical architecture and research findings in this thesis.

### 3.2 Development Methodology (Agile/Scrum)
To accommodate the urgent operational needs of the client while maintaining academic rigor, the system was developed using an Agile/Scrum methodology. This allowed for the rapid delivery of a functional prototype (v1.0.0) followed by iterative enhancements based on stakeholder feedback.

#### 3.2.1 Sprint Cycles
The development process was categorized into four distinct sprints, as documented in the project’s technical changelogs:

-   **Sprint 1: Foundational Prototype (Dec 2025):** 
    -   *Focus:* Core CRUD, Guest Portal, and basic SHA-256 hash-chaining.
    -   *Outcome:* Delivery of Version 1.0.0, which resolved the kiosk race conditions and established the "genesis" audit log.
-   **Sprint 2: Workflow & Mobility (Jan - Feb 2026):** 
    -   *Focus:* Physical handoff logic and QR Code integration.
    -   *Outcome:* Implementation of `in_transit` and `ready_for_release` statuses, requiring physical scans at each department floor.
-   **Sprint 3: Cryptographic Non-Repudiation (Mar 2026):** 
    -   *Focus:* Ed25519 Digital Signatures and "Active Guard" integrity.
    -   *Outcome:* Mandatory Security PINs for administrative actions, mathematically bonding user identity to the document state hash.
-   **Sprint 4: High-Scale Performance & Auditing (Mar 2026 - Present):** 
    -   *Focus:* Performance tuning for 1,000,000+ records and background integrity monitoring.
    -   *Outcome:* Neutralizing the "RAM Trap" via SQL-level aggregations and implementing **Asynchronous Integrity Auditing** to perform full-ledger cryptographic verification without latency.

### 3.3 System Validation (Testing Strategy)
Validation was conducted through a multi-layered testing approach, providing empirical evidence of the system's compliance with DSR evaluative criteria.

#### 3.3.1 Functional & Integrity Testing
The system utilizes automated unit and feature tests to verify its core security premises. 
-   **Chain Verification Test:** Ensures the `dts:verify-integrity` command reports 100% success on a valid, seeded database.
-   **Security Simulation:** Utilizes a custom command (`dts:corrupt-log`) to intentionally simulate a data breach or tampering event, asserting that the system's "Active Guard" correctly identifies the mismatch and triggers an immediate **Auto-Freeze** state.

#### 3.3.2 Performance & Scalability Benchmarking
To simulate the long-term throughput of the DepEd Division Office, the system was subjected to a high-scale load simulation:
-   **High-Volume Dataset:** 1,000,000 documents and 10,000,000 log entries were seeded to test database index efficiency and UI responsiveness.
-   **Algorithmic Optimization:** Refactored heavy analytics from PHP-side model hydration to database-side Window Functions, achieving **sub-second** dashboard response times through **O(log n)** query scaling.
-   **Asynchronous Audit Validation:** Verified that background workers (`IntegrityCheckJob`) can audit a 1M+ record ledger in a non-blocking manner, ensuring persistent system health monitoring in high-traffic environments.

### 3.4 Deployment and Environment
The prototype is architected for **On-Premise LAN Deployment**, prioritizing data sovereignty and local governance as per the DepEd IT Department's technical mandates.
-   **Stack:** PHP 8.3 / Laravel 12 / MySQL 8.0.
-   **Secure Development Proxy:** Utilizing **Nomadic HTTPS** (via `mkcert`) to unlock secure mobile features like QR scanning during the development and testing phases.
-   **Hardware Optimization:** Implemented a thread allocation strategy that pins critical system processes to specific CPU cores, ensuring operational stability on standard administrative workstations.
