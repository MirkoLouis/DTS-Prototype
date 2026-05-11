# Chapter 1 Presentation Slides

## Slide 1.1: Introduction
**Title:** 1.1 Introduction: Reimagining Digital Accountability

### Slide Text Content
*   **Administrative Foundation:** DTS as the essential infrastructure for DepEd Iligan City’s transparency.
*   **The "Black Hole" Phenomenon:** Eliminating "Ghost Documents" and untraceable manual hand-offs.
*   **Legal Mandate:** Aligning organizational efficiency with the mandates of **RA 11032** (Ease of Doing Business).
*   **Beyond Logging:** Moving from simple database records to a **"Trust Builder"** architecture.
*   **Security Paradigm Shift:** Transitioning from **Policy-Based Security** to **Mathematical-Based Security**.
*   **Core Technologies:** Integrating **SHA-256 Hash-Chaining** and **Ed25519 Digital Signatures**.

### Presentation Script
"Good morning, everyone. To begin our proposal, let’s look at the foundational role of Document Tracking Systems. In a large-scale organization like the DepEd Division of Iligan City, a DTS isn't just a tool—it is the digital backbone for transparency. 

Historically, manual workflows often led to what we call the 'Black Hole' phenomenon, where document statuses vanish during hand-offs. This results in 'Ghost Documents'—requests moving through offices without any official record. Our study addresses this by ensuring every action is time-bound and verifiable, directly supporting the transparency goals of Republic Act 11032.

However, the core innovation of this project lies in *how* we secure that data. Most standard systems rely on 'Policy-Based Security,' which is essentially the hope that staff won't accidentally or intentionally alter records. We are proposing a shift to 'Mathematical-Based Security.' By implementing a 'Trust Builder' module using SHA-256 hash-chaining and Ed25519 signatures, we create an environment where the rules are enforced by math, not just policy. This ensures that every hand-off is not just logged, but mathematically sealed and impossible to secretly alter."

---

## Slide 1.2: Background of the Study
**Title:** 1.2 Background: The "Digital Traffic Jam" and Shadow Systems

### Slide Text Content
*   **The Kiosk Failure:** Identification of "Race Conditions" in the current guest intake kiosks.
*   **The "Shadow System" Proliferation:** Reliance on unencrypted Google Sheets/Forms for 10,000+ entries.
*   **Operational Fragmentation:** Disconnected workflows between internal departments and guest intake.
*   **Systemic Risk:** Lack of ACID compliance and mathematical security in current informal methods.

### Visual Suggestion: The "Fragmentation Gap"
*A simple flowchart showing two parallel, disconnected paths:*
1. **Path A (Official):** Failing Kiosks -> Data Overwrites -> Lost Tracking.
2. **Path B (Shadow):** Staff Workarounds -> Unsecured Google Sheets -> No Verifiable History.

### Presentation Script
"The motivation for this study stems from a direct observation visit to the DepEd Division Office. We witnessed what we call a 'Digital Traffic Jam' at the guest kiosks. Technically, this was caused by race conditions—where multiple guests submitting at once would result in print jobs overwriting each other, leaving citizens with incorrect tracking information.

However, the problem runs deeper than just the kiosks. Because the official system was unstable, the Records Department was forced to establish 'Shadow Systems.' We found that staff were manually transcribing over 10,000 records into unencrypted Google Sheets just to keep operations running. While this helped them survive the day-to-day, it created a fragmented and technically flawed architecture. There is currently no way to reliably trace a document’s journey from intake to release across these disconnected platforms. Our project aims to replace these insecure workarounds with a unified, local-only architecture that restores data sovereignty and technical integrity."

---

## Slide 1.3: Statement of the Problem
**Title:** 1.3 Statement of the Problem: Three Dimensions of Failure

### Slide Text Content
*   **Technical Instability:** Race conditions causing data corruption and lost submissions at guest kiosks.
*   **Security Vulnerability:** Use of "Shadow Systems" (Google Sheets) lacking ACID compliance and encryption.
*   **Operational Fragmentation:** Bypassing of official intake protocols for internal documents.
*   **Compliance Failure:** Inability to provide a verifiable audit trail as mandated by **RA 11032**.

### Visual Suggestion: The "Trinity of Risk"
*A triangle diagram with these three corners:*
1. **Data Corruption** (Technical)
2. **Insider/External Threats** (Security)
3. **Ghost Documents** (Operational)

### Presentation Script
"Our statement of the problem is defined by a three-pronged failure of the current tracking architecture. First is technical instability: the race conditions we mentioned aren't just inconveniences; they cause literal data corruption. Second is the security vulnerability of shadow systems. Relying on Google Sheets for sensitive records means there is no mathematical safeguard against unauthorized changes. Finally, we have operational fragmentation—where internal documents bypass the digital system entirely. This leads to the creation of 'Ghost Documents,' making it impossible for DepEd to meet the transparency and accountability mandates of the Ease of Doing Business Act. Our research specifically targets these three points of failure to restore institutional integrity."

---

## Slide 1.4: Objectives of the Study
**Title:** 1.4 Objectives: Engineering Institutional Trust

### Slide Text Content
*   **General Objective:** To develop a high-security DTS that enforces mandatory intake and cryptographic integrity.
*   **Technical Pillar 1:** Mandatory intake gatekeeping to eliminate "Ghost Documents."
*   **Technical Pillar 2:** Implementation of the **"Trust Builder"** (SHA-256 Hash-chaining).
*   **Technical Pillar 3:** **Ed25519** Digital Signatures for absolute non-repudiation.
*   **Technical Pillar 4:** QR-based synchronization of physical and digital document states.
*   **Evaluation:** Measuring the prototype’s defensive capabilities against unauthorized modifications.

### Visual Suggestion: The "System Pillars"
*An architectural diagram with a base labeled "DepEd Iligan City" and four pillars supporting a roof labeled "Institutional Trust":*
1. Pillar 1: Mandatory Intake
2. Pillar 2: Hash-chaining
3. Pillar 3: Digital Signatures
4. Pillar 4: QR Synchronization

### Presentation Script
"To address these problems, our objectives are centered on engineering institutional trust through four technical pillars. Our general goal is to develop a system that not only tracks documents but mathematically guarantees their integrity. Specifically, we aim to:
One—enforce mandatory intake to ensure every document is digitally registered. 
Two—build a cryptographic audit trail using SHA-256 hash-chaining so that history becomes tamper-evident. 
Three—implement Ed25519 digital signatures to provide proof of identity for every administrative action. 
Four—use QR codes to keep the physical file and its digital record in perfect sync. 
Finally, we will evaluate the system's ability to detect tampering, ensuring that the 'Active Guard' we’ve built actually does its job in a high-volume environment."

---

## Slide 1.5: Significance of the Study
**Title:** 1.5 Significance: Impact Across the Organization

### Slide Text Content
*   **For Records Staff:** Elimination of "Shadow Systems" and reduction of administrative fatigue.
*   **For the IT Department:** A secure, on-premise (LAN) solution utilizing modern cryptographic standards.
*   **For Management:** Mathematical proof of action (Non-repudiation) for legal compliance.
*   **For the General Public:** Reliable guest kiosks with no data overwrites and real-time tracking.
*   **For Researchers:** A documented case study of blockchain-inspired security in a centralized RDBMS.

### Visual Suggestion: The "Beneficiary Map"
*A circular diagram with "The DTS Prototype" in the center and arrows pointing outward to icons representing:*
1. **Staff** (Checkmark/Less stress)
2. **IT** (Lock/Security)
3. **Management** (Gavel/Law)
4. **Public** (Smartphone/Tracking)

### Presentation Script
"The significance of this study is measured by its impact on different stakeholders. For the Records staff, it means an end to the tedious manual transcription into Google Sheets, drastically reducing administrative fatigue. For the IT department, it provides a solution that respects their local network constraints while introducing modern security protocols like Ed25519. Management benefits from having mathematical proof of every action, ensuring they stay compliant with the law. Most importantly, the general public gets a system that actually works—where their tracking data is safe and verifiable. Finally, for the academic community, we are contributing a blueprint for how high-level security can be implemented in a standard database environment."

---

## Slide 1.6: Scope and Delimitation
**Title:** 1.6 Scope and Delimitation: Boundaries of the Study

### Slide Text Content
*   **Geographic Scope:** Limited to the DepEd Division Office of Iligan City.
*   **Operational Scope:** End-to-end lifecycle (Intake -> Internal Processing -> Release).
*   **Technical Stack:** Restricted to PHP/Laravel 12 as per institutional mandate.
*   **Deployment:** Strictly on-premise (LAN) to ensure data sovereignty.
*   **Delimitation:** Does not replace physical storage; focuses on metadata and security audit trails.

### Visual Suggestion: The "Scope Boundary"
*A box diagram showing "Inside the Project" vs. "Outside the Project":*
*   **Inside:** Intake Kiosks, Departmental Tasks, Cryptographic Trust Builder.
*   **Outside:** Physical File Cabinets, Regional Office, Public Internet.

### Presentation Script
"To ensure the project remains focused and feasible, we have defined strict boundaries. Geographically, our scope is limited to the DepEd Division Office of Iligan City. We track the entire document lifecycle, from the moment a guest walks in to the moment the document is released. 

Technically, we are delimited by institutional constraints: the system must be built in PHP and deployed only on a Local Area Network. This is a critical security requirement to ensure data sovereignty. It’s also important to note that this is a digital synchronization layer—we aren't replacing the physical paper folders themselves, but we are creating an immutable digital record of where they are and who is responsible for them at any given moment."

---

## Slide 1.7: Definition of Terms
**Title:** 1.7 Definition of Terms: Technical Context

### Slide Text Content
*   **Trust Builder:** The module responsible for maintaining mathematical integrity.
*   **Hash-Chaining:** Linking log entries to make the history tamper-evident.
*   **Active Guard:** Security middleware that verifies live state against historical logs.
*   **Non-Repudiation:** Cryptographic proof that an action cannot be denied by the user.
*   **Shadow Systems:** Unofficial workarounds (e.g., Google Sheets) that bypass official protocols.

### Visual Suggestion: The "Glossary Visual"
*A simple graphic showing a "Lock" (Trust Builder), a "Chain" (Hash-Chaining), and a "Shield" (Active Guard) to reinforce the technical nature of these terms.*

### Presentation Script
"Finally, to ensure we are all on the same technical page, we’ve defined several key terms unique to this study. The most important is the 'Trust Builder,' which is the engine of our system's integrity. It uses 'Hash-Chaining' to link every action together, making the database tamper-evident. We also use 'Active Guard,' a specialized middleware that acts like a digital sentry, constantly checking the current state of a document against its signed history. These mechanisms combined provide 'Non-Repudiation,' meaning once a staff member signs for a document, they cannot later deny it. By defining these terms, we move away from the unreliable 'Shadow Systems' and toward a standardized, secure administrative environment."
