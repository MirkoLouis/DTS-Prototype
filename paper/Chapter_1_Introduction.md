# CHAPTER 1
## INTRODUCTION

### 1.1 Introduction
In the contemporary era of digital transformation, government agencies are increasingly mandated to enhance the efficiency and transparency of their service delivery. Document tracking plays a foundational role in administrative operations, serving as the primary mechanism for monitoring the flow of official correspondence, personnel records, and financial transactions. For large organizations like the Department of Education (DepEd) Division of Iligan City, the manual management of these documents often leads to systemic inefficiencies, including data loss, redundant processing, and a lack of real-time visibility for stakeholders.

This study proposes the development of a comprehensive Document Tracking System (DTS) for DepEd Iligan City. Unlike traditional tracking models that rely on simple database logs, this system integrates advanced cryptographic primitives—specifically SHA-256 hash-chaining and Ed25519 digital signatures—to create an immutable, "trustless" audit trail. By digitizing the workflow from guest intake to physical release, the system aims to eliminate the **Informal Information Systems (IIS)** currently utilized within the division and ensure absolute accountability for every administrative action.

### 1.2 Background of the Study
The genesis of this study lies in an empirical site visit to the DepEd Division Office of Iligan City in December 2025. During an observational survey for a previous research inquiry, the researchers identified critical failures in the existing document intake infrastructure. Observations revealed a significant race condition in the client-side kiosks: concurrent guest attempts to print document tracking forms resulted in overwritten print jobs, necessitating redundant data entry by the users.

Further consultation with the Records and IT Departments highlighted deeper structural issues. The Records Department had effectively bypassed the existing digital system due to its lack of centralized tracking code management for internal documents. This led to the proliferation of **Informal Information Systems (IIS)**, where staff utilized ad-hoc digital spreadsheets to generate disparate tracking codes. By the time of the researchers' visit, a single manual tracking log had already surpassed 10,000 entries, illustrating a massive, uncoordinated backlog of untracked documents.

Following these revelations, and upon the recommendation of the IT Head, the researchers pivoted their thesis focus to address these specific operational gaps. The IT Department granted approval for the development of a new DTS under two primary constraints: the system must be built using PHP and must be strictly limited to local, on-premise deployment. This study, therefore, documents the design and development of "DTS 1.0.0," a high-security, locally-hosted prototype tailored to the unique workflow of DepEd Iligan City.

### 1.3 Statement of the Problem
Despite previous attempts at digitization, the DepEd Division of Iligan City continues to struggle with fragmented and insecure document tracking. Specifically, the study seeks to address the following problems:
1. **Systemic Race Conditions:** The legacy intake kiosks fail under concurrent usage, leading to data loss and guest frustration.
2. **Proliferation of Informal Information Systems (IIS):** The reliance on disparate digital spreadsheets for internal documents creates disconnected tracking codes, making a unified audit of a document’s lifecycle impossible.
3. **Workflow Evasion:** Internal requests frequently bypass the formal intake process, resulting in "ghost documents" that lack verifiable accountability.
4. **Lack of Cryptographic Integrity:** Existing manual and semi-digital methods provide no mathematical assurance that document logs have not been retroactively altered.

### 1.4 Objectives
This study aims to design, develop, and evaluate a cryptographically secure Document Tracking System for DepEd Iligan City that ensures unified accountability and operational efficiency. Specifically, it seeks to:
1. **Develop a "Trust Builder" Audit Trail:** Implement a SHA-256 hash-chaining mechanism utilizing a **Micro-sharding** architecture to ensure the immutability and scalability of document logs.
2. **Integrate Ed25519 Non-Repudiation:** Require digital signatures for critical administrative actions using elliptic curve cryptography.
3. **Establish Unified Tracking:** Consolidate all document requests (internal and external) through a centralized guest portal to eliminate code fragmentation and **IIS usage**.
4. **Optimize Physical Workflows:** Integrate QR code scanning at every department hand-off to confirm physical receipt and delivery.
5. **Evaluate System Integrity:** Assess the prototype's ability to detect tampering through automated "Active Guard" and "Auto-Freeze" mechanisms.

### 1.5 Significance of the Study
1. **Records Officers:** Provides a **Heuristic Context-Aware Routing** interface to manage intakes and suggest department routes, reducing manual data entry and cognitive load.
2. **Department Staff:** Ensures that documents are physically accounted for through mandatory QR scans, preventing the loss of files during floor-to-floor transfers.
3. **DepEd IT Department:** Delivers a secure, PHP-based local solution that complies with institutional data governance policies.
4. **Guests/Public:** Enhances transparency by providing a reliable portal to track document status in real-time without the risk of system-overwrites.
5. **Future Researchers:** Serves as a reference for implementing blockchain-inspired integrity (hash-chaining) in local government information systems.

### 1.6 Scope and Delimitations
**Scope:**
The study focuses on the document lifecycle within the DepEd Division Office of Iligan City. It covers guest intake, AI-assisted routing, department-level receipt via QR scanning, and the final physical release of documents. The prototype is built using Laravel 12 and utilizes Sodium for Ed25519 signatures.
**Delimitations:**
The system is restricted to a local area network (LAN) deployment as per the client's IT constraints. It does not replace the physical storage of original paper documents but digitizes the tracking metadata and the audit trail associated with them. The AI routing is limited to a TF-IDF inspired keyword weighting system and does not utilize deep learning models.

### 1.7 Definition of Terms
- **Active Guard:** A real-time middleware check that verifies the document's live database state against its last signed hash before allowing any action.
- **Auto-Freeze:** A system state triggered when the Active Guard detects an integrity mismatch, preventing further actions on a document.
- **Ed25519:** A high-speed elliptic curve signature scheme used in the system to ensure non-repudiation of administrative actions.
- **Hash-Chaining:** A process where each log entry's hash depends on the hash of the preceding entry, creating a mathematically linked audit trail.
- **Micro-sharding:** An architectural design that partitions the cryptographic ledger into independent, document-specific hash chains, ensuring that a failure in one record does not compromise the scalability or integrity of the entire system.
- **Shadow System:** Unofficial information systems (like ad-hoc Google Sheets) used by staff when the primary system is deemed inadequate or broken.
- **Trust Builder:** The system's core logic module responsible for generating the SHA-256 hash-chain for document logs.
