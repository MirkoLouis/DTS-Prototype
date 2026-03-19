# CHAPTER 2
## REVIEW OF RELATED LITERATURE AND SYSTEMS

This chapter presents a structured review of related literature and existing systems, focusing on document tracking inefficiencies, cryptographic security, and administrative transparency. It concludes with the theoretical and conceptual frameworks that anchor the development of the DTS.

### 2.1 Related Literature

#### 2.1.1 Document Tracking Inefficiencies in Government
The inefficiency of manual document tracking is a global challenge. Bala (2020) notes that locating files using manual methods is a "tedious and time-consuming process" for administrative staff in large institutions. In the local context, Piloton (2023) observes that physical logbooks often lead to missing documents where "no one can pinpoint a person" responsible, as the system lacks accuracy and reliability. The empirical findings at DepEd Iligan City, specifically the use of manual Google Sheets for over 10,000 entries, validate these academic observations.

#### 2.1.2 Cryptographic Integrity and Non-Repudiation
Ensuring that digital records are immutable is critical for institutional trust. Agarkar (2012) emphasizes that security and "abstraction of file views" are essential for modern document management. Chavarro (2018) integrated digital signatures and encryption into institutional workflows to secure document management processes. This study extends these principles by utilizing **Ed25519 digital signatures** and **SHA-256 hash-chaining**, creating a mathematical dependency between logs that mirrors the security of blockchain audit trails.

#### 2.1.3 QR Code Integration for Floor-to-Floor Workflows
The use of QR codes has been proven to enhance physical-to-digital data synchronization. Farin (2022) found that QR code tracking in Philippine higher education was highly accepted due to its usability and readiness. Acedo (2025) further demonstrated that QR codes uniquesly identify each document for real-time status tracking, directly aiding compliance with the Ease of Doing Business Act (RA 11032).

### 2.2 Related Studies/Systems

#### 2.2.1 Foreign Systems
- **HDSS (Healthcare Decision Support System):** Ngan et al. (2022) developed a digitized platform that utilizes analytics dashboards for monitoring. While effective, HDSS often has "high computational costs" due to complex encryption.
- **CounSol.com:** A practice management software that streamlines administrative workflows. While it offers efficiency, it lacks the deep audit-trail integrity required for government non-repudiation.

#### 2.2.2 Local Systems
- **MyCounselor (2022):** A local system for higher education institutes that improved organization through digital logs. However, it still required manual processes for certain record updates, leaving gaps in the audit trail.
- **E-Document Tracking System (Saldon, 2015):** Designed for the DOST-X to expedite procurement processing. While it enhanced monitoring, it lacked the "Auto-Freeze" integrity mechanisms found in this study's prototype.

### 2.3 Synthesis of the Review
The reviewed systems reveal a clear progression from manual logbooks to basic digital databases. However, a critical gap remains: most systems rely on "trusted" database administrators and policy-based security. This study addresses this deficiency by implementing **cryptographic-based integrity**, where the system itself verifies its own history via hash-chains, and ensures non-repudiation via Ed25519, effectively eliminating the possibility of "silent tampering" or "shadow system" fragmentation.

### 2.4 Theoretical Framework
The development of the DTS is anchored in the following theories:
1. **Case Management Theory (Frankel et al., 2018):** Provides the structure for tracking a document through its various stages (Assessment, Planning, Implementation).
2. **Information Integrity Theory:** Focuses on maintaining and assuring the accuracy and consistency of data over its entire life cycle.
3. **Decision Support Theory (Keen et al., 1978):** Guides the AI-assisted routing module, which helps Records Officers make data-driven decisions based on historical routing weights.
4. **General System Theory (Bertalanffy, 1968):** Views the DepEd Division Office as a socio-technical system where technological artifacts must integrate seamlessly with human workflows (e.g., QR scanning at floor hand-offs).

### 2.5 Conceptual Framework

#### 2.5.1 Input-Process-Output (IPO) Model
The conceptual framework follows an IPO model to illustrate the flow of data:
- **INPUT:** Document metadata (Title, Purpose, Submitter), Security PINs, Department routes, and QR scan data.
- **PROCESS:** Trust Builder (Hash-chaining), Ed25519 Signing, Active Guard (Integrity check), AI Route Prediction, and Asynchronous Report Generation.
- **OUTPUT:** Immutable Audit Trail, Signed Document Logs, Real-time status visibility, and Automated Bottleneck Analytics.

#### 2.5.2 Workflow Mapping
The system maps the empirical "Google Sheets" workaround to a unified digital flow:
- All internal and external documents are captured at a single entry point.
- The system generates a cryptographic hash for each action, linking it to the division's unique private key history.
- Departments "receive" documents only after a physical QR scan, ensuring the digital status matches the physical location.
