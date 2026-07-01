# **CHAPTER 3**

# **RESEARCH METHODOLOGY**

This chapter details the research design and development methodology used to create the Document Tracking System (DTS) for DepEd Iligan City. The study follows the Design Science Research (DSR) methodology to address institutional problems through the creation of an innovative IT artifact, utilizing Rapid Application Development (RAD) as the strategy for building and refining the system.

## **3.1 Research Design**

This study employs the Design Science Research (DSR) methodology, a rigorous framework suited for developing and evaluating innovative information technology artifacts that address identified organizational problems (Hevner et al., 2004). DSR focuses on both the creation of purposeful artifacts—in this study, a high-security Document Tracking System (DTS)—and the generation of design knowledge that contributes to the scientific knowledge base (March & Smith, 1995). Recent research in the field of information systems increasingly utilizes DSRM to solve complex institutional challenges, as seen in the development of user management ecosystems (Izzulhaq et al., 2022) and structure health monitoring applications (Rosidin et al., 2023), proving its effectiveness in bridging the gap between theoretical models and practical IT artifacts.

The study adopts the Design Science Research Methodology (DSRM) proposed by Peffers et al. (2007), which provides a systematic process consisting of six key stages:
1.  **Problem identification and motivation:** Analyzing the failures of the existing DTS at DepEd Iligan.
2.  **Definition of the objectives of a solution:** Establishing functional and technical requirements (Non-repudiation, Integrity).
3.  **Design and development of the artifact:** Building the "Trust Builder" and "Active Guard" modules.
4.  **Demonstration of the artifact:** Showcasing the cryptographic movement of documents via QR scanning.
5.  **Evaluation of the artifact:** Measuring technical integrity and system usability.
6.  **Communication of the results:** Documenting the findings for institutional and academic use.

These stages are iteratively executed following Hevner's (2007) three-cycle model, ensuring that the artifact is grounded in the real-world environment, refined through engineering, and supported by a rigorous knowledge base:

| DSR Cycle | Description |
| :--- | :--- |
| **1. Relevance Cycle** | Identify the problem: "DepEd Iligan lacks a mathematically secure tracking system, leading to data loss and the proliferation of insecure shadow systems (Behrens, 2009)." Collect strict security and workflow requirements from the IT and Records Departments. |
| **2. Design Cycle** | Build the DTS artifact using Rapid Application Development (RAD). Iteratively refine the "Trust Builder" hash-chaining logic, the Ed25519 signing interface, and the QR synchronization protocols based on continuous client feedback. |
| **3. Rigor Cycle** | Use existing knowledge: SHA-256 hash-chaining (Crosby & Wallach, 2009), Ed25519 signatures (Bernstein et al., 2012), and Information Integrity Theory. Support architectural decisions with established cryptographic and software engineering research. |

## **3.2 Research Framework**

The study is guided by the DSR framework adapted from Hevner et al. (2004), which connects the organizational environment, design science activities, and the scientific knowledge base. This framework ensures that the DTS prototype is not merely a technical solution but a rigorous response to the administrative and security gaps identified at the DepEd Division Office of Iligan City.

*(Insert Figure 3.1: Design Science Research Framework adapted from Hevner et al. (2004))*

By utilizing this framework, the researchers maintain a continuous "Build-Evaluate" loop. This iterative process allows for the refinement of the system's performance layer—such as the TF-IDF-inspired route prediction (Ngan et al., 2022) and asynchronous background processing—until it effectively meets the institutional demands for speed and absolute data integrity. This approach guarantees that the final artifact is methodologically sound and operationally viable within the local-area network (LAN) constraints of the division office.

## **3.3 Problem Identification and Motivation**

The motivation for this study stems from the critical technical failures and operational inefficiencies observed at the DepEd Division Office of Iligan City. During an empirical visit in December 2025, researchers identified a significant "Digital Traffic Jam" at guest kiosks, caused by a lack of robust queue management (FormKiQ, 2023; SunSmart Global, 2022). This failure resulted in race conditions where concurrent document submissions would overwrite one another, leading to lost tracking data and administrative backlog. This technical instability directly undermines the transparency mandates of the Ease of Doing Business Act (Republic Act No. 11032).

More significantly, the study is motivated by the pervasive use of informal "Shadow Systems" within the institution. Because the formal tracking system was perceived as incomplete or rigid, the Records Department resorted to using unencrypted Google Sheets and Forms to log metadata for over 10,000 document entries (Empirical Observation, 2025). As noted by Behrens (2009) and Rentrop and Zimmermann (2020), such shadow systems emerge as rational coping mechanisms for staff but introduce severe cybersecurity risks and lack the ACID compliance needed for government data sovereignty (Haag & Eckhardt, 2024). The creation of an innovative, high-security IT artifact is therefore necessary to consolidate these fragmented audit trails and restore institutional trust through mathematical-based assurance.

## **3.4 Objectives of the Solution**

Based on the identified institutional gaps, the study establishes clear objectives for the proposed DTS prototype. These objectives serve as the measurable criteria for evaluating the success of the artifact across functional, non-functional, technical, and theoretical dimensions:

| Objective Type | Description |
| :--- | :--- |
| **Functional Objectives** | Enforce a mandatory intake protocol to eliminate unofficial "ghost documents," provide AI-assisted route suggestions (Ngan et al., 2022), and implement QR code physical-digital synchronization for departmental hand-offs (Acedo, 2025). |
| **Non-Functional Objectives** | Ensure sub-second response times for dashboard visualizations and document searches, maintaining system responsiveness even when managing over 1,000,000 records (FormKiQ, 2023; SunSmart Global, 2022). |
| **Technical Objectives** | Implement a "Trust Builder" module using SHA-256 hash-chaining (Crosby & Wallach, 2009) and Ed25519 digital signatures (Bernstein et al., 2012) to ensure non-repudiation. Integrate an "Active Guard" middleware for real-time state verification. |
| **Theoretical Objectives** | Demonstrate how "Mathematical-Based Security" and high-integrity centralized ledgers can be practically implemented in a resource-constrained government environment (Bajoria, 2025; Kim & Kim, 2024). |

These objectives guide the iterative RAD cycles, ensuring that every feature—from the cryptographic signing interface to the asynchronous background audits—is purposefully designed to solve a specific institutional problem.

## **3.5 Research Locale and Participants**

The study is conducted within the DepEd Division Office of Iligan City, specifically focusing on the administrative workflow of official documents across various functional units (Records, Cash, Personnel, Supply, etc.). This environment was selected because it represents a high-volume government ecosystem where data integrity and physical accountability are critical for operational transparency.

A **purposive sampling** strategy was used to involve key personnel during both the pre-development needs assessment and the post-development artifact evaluation phases. Approximately 10 key personnel were selected based on their expertise and relevance to the document tracking lifecycle:
-   **Records Officers (6 Participants):** Selected because they are the primary handlers of document intake, routing, and final release. Their feedback is essential for evaluating the usability of the smart routing and QR scanning modules.
-   **IT Personnel (4 Participants):** Selected for their technical oversight of the institutional network and database security. They are responsible for evaluating the robustness of the cryptographic hash-chains and the system-wide integrity audits.

Informed consent was obtained from all participants prior to evaluation, ensuring that their involvement was voluntary and their feedback used solely for the technical improvement and academic validation of the DTS artifact.

## **3.6 Artifact Design and Development Plan**

The DTS artifact is developed using the **Rapid Application Development (RAD)** methodology (Martin, 1991), which emphasizes an iterative prototyping approach and continuous user feedback. RAD is particularly suited for this study as it allows the researchers to refine complex security features—such as cryptographic signing—in response to real-world operational constraints. The development process follows four distinct phases:

1.  **Requirements Planning:** This phase involved consultation with the Records and IT Departments to define the scope and security objectives of the system. The focus was on identifying the specific failure points of the existing DTS, such as kiosk race conditions and the lack of a unified primary key for internal documents.
2.  **User Design:** During this phase, the first functional prototype was developed to resolve the immediate "Digital Traffic Jam" at the kiosks. The researchers designed the initial database schema and demonstrated the core "genesis" hash-chaining logic to ensure that the mathematical foundations aligned with the client’s security expectations.
3.  **Construction:** This phase involved the intensive engineering of the system’s high-security modules. The researchers implemented the "Trust Builder" module (SHA-256 and Ed25519), the "Active Guard" middleware for real-time state verification, and the QR code synchronization protocols. Performance optimizations, such as "micro-sharding" the hash-chains and implementing asynchronous background processing, were also completed during this stage.
4.  **Cutover:** The final phase involves the demonstration and evaluation of the complete artifact against institutional demands and RA 11032 requirements. The researchers conduct technical simulations and usability testing to verify that the system is ready for LAN-based deployment within the division office.

Throughout these phases, documentation is maintained through system logs containing records of design decisions, security implementations, and user feedback. This ensures that the engineering process is transparent, reproducible, and contributes to the design knowledge base of the study.

## **3.7 Evaluation Framework**

Evaluation is conducted to determine the utility, quality, and efficacy of the DTS artifact following the Framework for Evaluation in Design Science (FEDS) proposed by Venable, Pries-Heje, and Baskerville (2016). The evaluation strategy incorporates both ex-ante (predictive) and ex-post (empirical) components to assess the prototype’s resilience and operational readiness:

| Evaluation Type | Setting | Method | Purpose |
| :--- | :--- | :--- | :--- |
| **Ex-ante** | Artificial | **Automated Integrity Simulation:** Recalculating 10,000+ document hashes and verifying signatures against historical keys using the `IntegrityCheckJob`. **Performance Load Testing:** Measuring sub-second response times under concurrent database load. | Assess expected performance, cryptographic resilience, and algorithmic scalability before full institutional deployment. |
| **Ex-post** | Naturalistic | **Task-based Usability Testing:** Participants perform core actions (Intake, Receiving via QR, Releasing). **System Usability Scale (SUS):** 10 key personnel complete a standardized 10-item survey. | Assess real-world effectiveness, interface clarity, and institutional acceptance by Records Officers and IT staff. |

This dual-layered approach ensures that the "Trust Builder" module is not only mathematically sound but also practically usable within the high-traffic environment of DepEd Iligan City.

## **3.8 Data Gathering Procedures**

Data is gathered from a combination of technical logs and human-centric feedback instruments to provide a comprehensive measure of the system’s success:

1.  **System Performance Logs and Technical Metrics:** Quantitative data is extracted directly from the system’s `database_metrics` table, including average query times, connection counts, and slow-query occurrences. Additionally, the results of the `IntegrityCheckJob` provide metrics on the "Verified Percentage" of the hash-chain, documenting the system’s ability to detect unauthorized modifications.
2.  **Usability Testing Sessions:** The 10 purposively selected participants are given standardized tasks to perform using the DTS prototype. These tasks cover the entire document lifecycle, from initial guest submission to final releasing. Researchers observe these sessions to document any "Digital Traffic Jams" or operational friction points.
3.  **System Usability Scale (SUS) Questionnaire:** Immediately following the usability sessions, participants complete a digital questionnaire based on the SUS (Brooke, 1996). This instrument is widely used in IT research for its reliability in measuring perceived usability. The questionnaire is validated by subject-matter experts to ensure its relevance to the DTS interface.
4.  **Key Informant Interviews:** Informal post-test interviews are conducted with IT personnel to gather qualitative insights into the scalability of the "micro-sharding" hash-chain design and the robustness of the "Active Guard" middleware.

All data gathering instruments and technical protocols are designed to adhere to the rigor cycle of the DSR methodology, ensuring that the findings are supported by empirical evidence and verifiable system logs.

## **3.9 Data Analysis**

Data collected throughout the study is processed and analyzed using both quantitative and qualitative methods to ensure a holistic evaluation of the DTS artifact:

1.  **Quantitative SUS Score Analysis:** The results of the System Usability Scale (SUS) survey are calculated to determine a standardized usability score ranging from 0 to 100. Based on established benchmarks (Brooke, 1996), the research sets a target rating of "Good" (a score greater than 68) to validate that the cryptographic and tracking interfaces are accessible to institutional staff.
2.  **Performance Metric Verification:** Technical logs from the ex-ante load simulations are analyzed to verify that the system maintains sub-second response times (less than 1,000 milliseconds) for core database queries and dashboard visualizations. The research specifically measures the "Verification Time" for hash-chains to ensure that the "micro-sharding" design maintains scalability as the record count exceeds 10,000 document logs.
3.  **Thematic Analysis of Qualitative Feedback:** Feedback from post-test interviews and usability observations undergoes thematic analysis to identify recurring operational issues, emergent design preferences, and potential bottlenecks in the "Active Guard" workflow. This analysis informs the final "Design Cycle" of the DSR methodology, guiding any necessary UI/UX refinements.
4.  **Integrity Audit Analysis:** The results of the `IntegrityCheckJob` are analyzed to quantify the system’s "Verified Percentage." A 100% verified result in artificial simulations is required to validate that the SHA-256 hash-chaining and Ed25519 signatures are technically sound and capable of detecting unauthorized data manipulation.

## **3.10 Ethical Considerations**

The study strictly adheres to the principles of the **Republic Act No. 10173 (Data Privacy Act of 2012)**. Ethical approval was secured from the relevant institutional committee prior to formal data collection, and all research procedures prioritize the protection of institutional and personal information:

-   **Informed Consent:** Participation in usability testing and interviews was strictly voluntary. Researchers provided clear orientations regarding the study's objectives, and signed informed consent was obtained from all 10 institutional respondents prior to any evaluation tasks.
-   **Anonymity and Confidentiality:** To protect the privacy of the personnel, participants are assigned identification numbers (e.g., Participant 01) in all reports and analysis logs. No personally identifiable information (PII) is stored within the system’s technical performance tables.
-   **Data Sovereignty and Network Security:** Adhering to the IT Department's strict security mandates, the DTS prototype is deployed exclusively within a local area network (LAN). This on-premise deployment ensures institutional data sovereignty, preventing unauthorized external access to sensitive document metadata and cryptographic keys.
-   **System Integrity:** All collected evaluation data is stored on secure, local devices as mandated by the institutional IT policy and is used solely for the evaluation and academic documentation of the DTS prototype.
