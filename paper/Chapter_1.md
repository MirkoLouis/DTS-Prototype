# **CHAPTER 1**

# **The Problem and Its Background**

## **1.1 Introduction**

In the modern landscape of public administration, Document Tracking Systems (DTS) serve as the essential digital infrastructure for monitoring the lifecycle of official correspondence. A DTS is a specialized information system designed to record the intake, movement, and final disposition of physical and digital files within an organization. For large-scale entities like the Department of Education (DepEd) Division of Iligan City, these systems are no longer optional tools; they are foundational to ensuring that personnel records, financial requests, and academic reports are processed in an orderly and transparent manner. By providing a centralized view of where a document is and who is currently handling it, these systems transform abstract administrative processes into visible, manageable workflows.

Before the introduction of digital tracking, government offices relied almost exclusively on manual logbooks and physical routing slips. In this traditional setup, a document’s journey was recorded by hand at every desk it reached, often requiring staff to flip through thick paper ledgers to find a specific entry. While this method was sufficient for lower volumes of work, it was highly susceptible to human error, physical damage, and loss. There was no way to search for a document instantly, and the lack of a centralized backup meant that if a logbook was lost, the entire history of those documents disappeared with it. The transition to digital DTS models was driven by the need for speed, searchability, and the ability to manage thousands of records without the physical limitations of paper.

The primary goal of a Document Tracking System is to eliminate the uncertainty and lack of accountability often found in manual workflows. Without a tracking system, documents frequently enter a "black hole" where their status and location are unknown to both the submitter and the management. This leads to systemic delays, misplaced files, and a phenomenon known as "Ghost Documents," which are requests that move through the office without any official record. DTSs aim to solve these issues by providing a real-time audit trail, ensuring that every hand-off is timestamped and every delay is identifiable. This digital accountability is particularly critical under laws like Republic Act No. 11032, which mandates that government actions be time-bound and verifiable to prevent bureaucratic slow-downs.

This thesis project differs from most standard Document Tracking Systems by moving beyond simple database logging into the realm of "Mathematical-Based Security." While traditional systems rely on the hope that administrators will not change data (Policy-Based Security), this project implements a "Trust Builder" architecture using SHA-256 hash-chaining and Ed25519 digital signatures. Every log entry is mathematically linked to the one before it, making it impossible to secretly alter the history of a document. Furthermore, by requiring a Security PIN to generate cryptographic signatures for critical actions, the system ensures non-repudiation—meaning a user cannot later deny that they performed a specific action. Most common systems lack this level of cryptographic protection, making them vulnerable to "silent tampering" that this project is designed to prevent.

For IT students, this study is a vital bridge between theoretical classroom concepts and the practical demands of the software industry. Developing this system allows the researchers to apply advanced topics—such as cryptographic salt derivation, database indexing for millions of records, and asynchronous background processing—to solve a tangible community problem. Beyond technical skills, the project teaches the importance of "Security by Design" and the ethical responsibility of a developer to protect the integrity of government data. By building a system that directly impacts the efficiency of the DepEd Division Office, the researchers gain a deeper understanding of how IT can be used as a tool for governance, transparency, and the reduction of corruption in the public sector.

## **1.2 Background of the Study**

The origin of this study began with an empirical observation visit to the DepEd Division Office of Iligan City in December 2025. During this visit, researchers witnessed a "Digital Traffic Jam" at the guest intake kiosks. Because the legacy system lacked a robust queue management logic, concurrent attempts by multiple guests to submit their documents and print tracking forms resulted in a technical race condition. This failure meant that one guest's print job would often overwrite another’s, leaving citizens with incorrect information or no tracking form at all. This immediate failure in the "first mile" of the document lifecycle created a negative first impression of the division’s digital services and forced guests to repeat their submissions, causing significant frustration and administrative backlog.

Beyond the visible failure at the kiosks, deeper consultation with the Records and IT Departments revealed a systemic fragmentation of information. The Records Department had largely abandoned the existing digital system because it lacked a unified way to manage tracking codes for internal documents—those moving between departments rather than coming from outside guests. To keep their daily operations running, staff resorted to using "Shadow Systems," specifically shared Google Sheets. While these spreadsheets provided a temporary way to log document movements, they created "Information Silos" where data was disconnected from the official system. This fragmented approach meant that finding the true status of a document required searching through multiple unapproved files, effectively defeating the purpose of a centralized tracking system.

These informal shadow systems introduce critical risks to data integrity and organizational security. Unlike professional databases, shared spreadsheets like Google Sheets lack "ACID compliance" (Atomicity, Consistency, Isolation, Durability) and row-level locking. This means that if two staff members edit the same record simultaneously, the data can become corrupted or permanently lost. Furthermore, these unencrypted logs have no mathematical protection against "silent tampering," where a record could be retroactively changed or deleted without leaving a trace. During the researchers’ visit, one such manual log had already surpassed 10,000 entries (Empirical Observation, 2025). Managing a dataset of this size in a simple spreadsheet is not only inefficient but represents a major "Insider Threat" to the division’s cybersecurity, as unauthorized changes to document histories could go undetected for months.

When comparing this situation to other standard Document Tracking Systems used in the public sector, a clear gap in functionality emerges. Most existing DTS solutions are designed to handle either only external guest intakes or only internal staff workflows, but rarely both under a unified tracking architecture. In the case of DepEd Iligan, previous digital attempts failed because they did not account for the high volume of internal correspondence that bypasses the formal intake process. While other systems may have digitized the "hand-over" process, they failed to provide the mathematical assurance that those logs remained untampered over time. The institution was essentially relying on "Policy-Based Security"—the hope that staff would follow the rules—rather than "Mathematical-Based Security" that makes rules impossible to break.

Following these revelations and the recommendation of the IT Head, the researchers recognized that a standard, "off-the-shelf" tracking system would not solve the problem. The study therefore pivoted to create a custom, high-security prototype tailored to the unique workflow of DepEd Iligan City. This solution is specifically designed to meet the client’s strict constraints: it must be built using PHP and restricted to a local, on-premise network (LAN) to ensure data sovereignty. By integrating "Trust Builder" hash-chaining and Ed25519 signatures, the proposed system is not just another tracking tool; it is a specialized security artifact designed to restore institutional trust and provide a permanent solution to the proliferation of informal, insecure tracking methods.

## **1.3 Statement of the Problem**

**General Problem:**
Despite previous efforts to digitize, the DepEd Division of Iligan City lacks a unified and secure system for tracking official documents, leading to frequent data loss, unauthorized modifications, and an inability to accurately audit document histories.

**Specific Problems:**
Specifically, the study seeks to address the following problems:
1.  **System Failures During Intake:** The existing kiosks crash when multiple guests try to submit requests at the same time, causing print jobs to overwrite each other and resulting in lost data.
2.  **Spread of Unapproved Systems (Shadow IT):** Staff rely on disconnected tools like Google Sheets to track internal documents, making it impossible to perform a complete and accurate audit of a document's journey.
3.  **Bypassing the Formal Process:** Internal document requests frequently skip the official intake procedures, creating "ghost documents" that have no official record or accountability.
4.  **Lack of Security and Proof:** Current manual and simple digital logs offer no mathematical proof to prevent someone from secretly changing past records, relying entirely on human trust which is vulnerable to errors.

## **1.4 Objectives of the Study**

**General Objective:**
To design, develop, and evaluate a secure Document Tracking System (DTS) for DepEd Iligan City that ensures unified accountability, operational efficiency, and mathematical proof of data integrity.

**Specific Objectives:**
1.  **Develop a Secure Audit Trail:** Implement a SHA-256 hash-chaining mechanism to ensure that document logs cannot be secretly altered or deleted.
2.  **Require Digital Signatures:** Require Ed25519 digital signatures for important administrative actions (like finalizing a route or releasing a document) to ensure actions cannot be denied by the user who performed them.
3.  **Establish Unified Tracking:** Combine all document requests (both internal and from guests) into one centralized portal to eliminate the use of unapproved spreadsheets.
4.  **Optimize Physical Workflows:** Add QR code scanning at every department transfer to confirm physical receipt and delivery, ensuring the digital record matches the physical location.
5.  **Evaluate System Integrity:** Test the prototype's ability to detect unauthorized data changes through automated system-level checks and freezes.

## **1.5 Significance of the Study**

**Records Officers:** Provides a smart routing interface that suggests which departments a document should go to, reducing manual data entry and ensuring every action is securely signed.
**Department Staff:** Ensures that documents are physically accounted for through mandatory QR scans, preventing the loss of files during transfers and fulfilling the accountability rules of RA 11032.
**DepEd IT Department:** Delivers a secure, PHP-based local solution that follows institutional data policies and provides a highly secure, centralized record for all administrative actions.
**Guests/Public:** Improves transparency by offering a reliable portal to track document status in real-time without the risk of system crashes or data loss.
**Future Researchers:** Serves as a practical reference for implementing blockchain-inspired security features (like hash-chaining) in local government systems using centralized databases.

## **1.6 Scope and Delimitation**

**Scope:** 
The study focuses on managing the document lifecycle within the DepEd Division Office of Iligan City. It covers guest intake, AI-assisted routing suggestions, department-level receipt via QR scanning, and the final physical release of documents. The prototype is built using Laravel 12 and utilizes Sodium for Ed25519 security signatures.

**Delimitations:** 
The system is restricted to a local area network (LAN) deployment due to the client’s strict IT policies. It does not replace the physical storage of original paper documents but digitizes the tracking data and the audit trail associated with them. The AI routing feature is limited to a keyword weighting system and does not use complex deep learning models.

## **1.7 Definition of Terms**

For the purpose of this study, the following terms are defined to ensure a clear understanding of the technical concepts presented:

| Term | Definition |
| :--- | :--- |
| **Active Guard** | A security check that verifies the document’s current database state against its last signed record before allowing any new action, preventing unauthorized changes. |
| **Auto-Freeze** | A system response triggered when the Active Guard detects a mismatch, locking the document to prevent further actions. |
| **Ed25519** | A fast and highly secure digital signature method used to prove the identity of the user performing an administrative action. |
| **Hash-Chaining** | A process where each log entry is mathematically linked to the previous one, creating a secure audit trail that is extremely difficult to alter secretly. |
| **Micro-sharding** | A design approach that creates separate, independent security chains for each document, ensuring the system remains fast even with millions of records. |
| **Shadow System** | Unofficial tools, like personal Google Sheets, used by staff for official work when the main system is seen as broken or too slow. |
| **Trust Builder** | The core module of the system responsible for generating the secure hash-chain for document logs. |
