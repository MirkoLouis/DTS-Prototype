# **CHAPTER 2**

# **Review of Related Literature and Studies**

This chapter presents a structured review of related literature and existing systems, focusing on document tracking inefficiencies, security, and administrative transparency. It concludes with the theoretical and conceptual frameworks that guide the development of the DTS.

## **2.1 Related Literature**

### **2.1.1 Document Tracking Inefficiencies in Government**

The inefficiency of manual document tracking is a global challenge. Bala (2020) notes that locating files using manual methods is a slow and exhausting process for administrative staff in large institutions. Research by Rokosu (2025) in local government councils found that relying on paper-based or poorly designed digital systems resulted in a 40% rate of document misplacement and prolonged service delays—averaging 3–6 weeks instead of the mandated 7 days. This lack of digital audit trails directly enables discretionary behavior, with 60% of citizens admitting to paying unofficial fees to speed up document retrieval (Rokosu, 2025). 

In the local context, Piloton (2023) observes that physical logbooks often lead to missing documents where it becomes impossible to pinpoint the responsible person. This is worsened by the failure to meet the turnaround times mandated by Republic Act No. 11032, which penalizes government personnel for excessive delays in processing official requests. Maina et al. (2025) emphasize that these infrastructure gaps compromise transparency and often lead to the creation of parallel tracking systems. The observations at DepEd Iligan City, specifically the use of manual Google Sheets for over 10,000 entries (Empirical Observation, 2025), clearly validate these academic findings.

### **2.1.2 Cryptographic Integrity and Proof of Action**

Ensuring that digital records cannot be secretly changed is critical for institutional trust. Chavarro (2018) added digital signatures and encryption into institutional workflows to secure document management processes. Unlike standard security models that only rely on passwords and access levels, math-based security utilizes digital signatures to provide concrete proof of an action. Crosby & Wallach (2009) highlight the importance of "history trees" and tamper-evident logging to verify the integrity of large-scale event logs. This study utilizes Ed25519 signatures and SHA-256 hash-chaining, creating a mathematical link between logs that mirrors the security of blockchain but is optimized for local, on-premise databases. This shifts the trust from human administrators to verifiable mathematical proofs (Crosby & Wallach, 2009).

### **2.1.3 QR Code Integration for Physical Workflows**

The use of QR codes has been proven to improve the synchronization between physical documents and digital data. Farin (2022) found that QR code tracking in Philippine higher education was highly accepted because it was easy to use. Acedo (2025) further demonstrated that QR codes uniquely identify each document for real-time status tracking. This directly aids compliance with the Ease of Doing Business Act (RA 11032) by providing verifiable timestamps every time a document is handed over between departments.

## **2.2 Related Studies/Systems**

### **2.2.1 Foreign Studies: Emerging Architectures for Scalability**

Recent advancements in secure tracking emphasize the need for resource-efficient designs. Kim and Kim (2024) proposed a structured and adaptive hash chain approach to reduce the high computational costs associated with traditional global blockchain ledgers. Their study demonstrates that generating independent hash chains for specific processes—rather than a single, massive chain—allows for faster tracking and verification. This research directly supports the DTS prototype's architecture, which isolates document-specific logs to enable fast verification times and prevent system slowdowns (Kim & Kim, 2024).

### **2.2.2 Foreign Studies: Security in Centralized Databases**

Recent literature emphasizes that for systems intended to run on local hardware, scalability must be achieved through smart programming rather than just buying better servers. Bajoria (2025) argues that blockchain-enabled audit trails within standard databases can revolutionize data integrity by creating mathematically unbreakable links between operations, satisfying strict compliance rules without needing decentralized networks. Mishra (2025) proposed a blockchain-based framework for tamper-proof document exchange but noted that high processing needs are a barrier for local governments. The proposed DTS overcomes this by using secure hash chains in a centralized MySQL environment, providing a high-integrity local ledger as described by Bajoria (2025).

### **2.2.3 Local Studies: Shadow Systems in the Public Sector**

The spread of shadow systems—software used without formal IT approval—presents a complex challenge for public administration. Behrens (2009) suggests that shadow systems usually emerge as a coping mechanism when official IT systems are seen as too rigid, slow, or disconnected from actual workflows. In the public sector, employees often resort to tools like Google Sheets to maintain speed (Rentropa & Zimmermann, 2020). Haag & Eckhardt (2024) identify that these unapproved systems introduce serious risks, including inconsistent data and severe security vulnerabilities. This literature reinforces the design philosophy of the DTS prototype, which incorporates smart routing to provide a user experience that rivals the speed of informal spreadsheets while maintaining strict security control.

### **Table of Related Systems**

| Author/Year | Title of Study/System | Location | Features / Functions | Strengths | Weaknesses / Limitations | Relevance to Current Study |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| Kim & Kim (2024) | Structured and Adaptive Hash Chain | Foreign | Independent hash chains for process lines. | Faster tracking, lower computational cost. | Not applied to centralized document management. | Supports the "Micro-sharding" design used in the DTS to ensure fast verification. |
| Bajoria (2025) | Blockchain-enabled Audit Trails within RDBMS | Foreign | Mathematically linked database operations. | Satisfies strict compliance without decentralized consensus. | Focuses heavily on financial ledgers rather than document tracking. | Validates the concept of building a "High-Integrity Centralized Ledger" for the DTS. |
| Mishra (2025) | Blockchain-based Framework for Tamper-Proof PDF Exchange | Foreign | Tamper-proof document exchange. | High security for shared documents. | High computational overhead makes it unsuitable for local government units. | Highlights the need for a lightweight, centralized alternative like the proposed DTS. |
| Acedo (2025) | QR Code Tracking in Public Administration | Local | Real-time status tracking via QR codes. | Verifiable timestamps for document hand-offs. | Lacks mathematical protection against retroactive log tampering. | Guides the implementation of QR scanning at department hand-offs in the DTS. |

## **2.3 Synthesis of the Review**

The reviewed literature and systems reveal a clear progression from manual logbooks to basic digital databases. However, a critical gap remains: most current systems rely on policy-based security, where data integrity depends entirely on the trustworthiness of system administrators. Furthermore, systems that do implement strong cryptographic security (like blockchain) are often too computationally heavy for local, on-premise government hardware. This study addresses these gaps by implementing math-based security (hash-chaining and digital signatures) within a centralized, lightweight database. This shifts the trust from human administrators to mathematical proofs, effectively eliminating the possibility of secret tampering or the fragmentation caused by unapproved shadow systems, while remaining viable for local deployment.

## **2.4 Theoretical Framework**

The development of the DTS is anchored in the following theories:
1.  **Case Management Theory (Frankel et al., 2018):** Provides the structure for tracking a document through its various stages (Intake, Processing, Releasing).
2.  **Information Integrity Theory:** Focuses on maintaining and assuring the accuracy, consistency, and security of data over its entire life cycle.
3.  **Decision Support Theory (Keen et al., 1978):** Guides the smart routing module, which helps Records Officers make data-driven decisions based on historical routing data.
4.  **General System Theory (Bertalanffy, 1968):** Views the DepEd Division Office as a socio-technical system where technological tools must integrate seamlessly with human habits (e.g., QR scanning during physical hand-offs).

## **2.5 Conceptual Framework**

The conceptual framework follows an Input-Process-Output (IPO) model to illustrate the flow of data:
-   **INPUT:** Document details (Title, Purpose, Submitter), Security PINs, Department routes, and QR scan data.
-   **PROCESS:** Trust Builder (Hash-chaining), Ed25519 Signing, Active Guard (Real-time integrity check), Context-Aware Route Prediction, and Asynchronous Report Generation.
-   **OUTPUT:** Immutable Audit Trail, Signed Document Logs, Real-time status tracking, Automated Integrity Reports, and Bottleneck Analytics that comply with RA 11032.
