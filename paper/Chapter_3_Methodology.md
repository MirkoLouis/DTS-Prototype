# CHAPTER 3
## RESEARCH METHODOLOGY

This chapter details the research design and development approach used to build the Document Tracking System. It adopts the Design Science Research (DSR) methodology to address the real-world operational gaps observed at DepEd Iligan City.

### 3.1 Research Design
This study employs the **Design Science Research (DSR)** methodology proposed by Peffers et al. (2007). DSR is particularly suited for information systems research as it focuses on the creation and evaluation of artifacts intended to solve identified organizational problems. The research is structured around three main cycles:

1. **Relevance Cycle:** Connects the project to the real-world environment. In this study, the environment is the DepEd Division Office of Iligan City, where manual processes and shadow systems (Google Sheets) were identified as critical pain points during the December site visit.
2. **Design Cycle:** The core iterative process of building and evaluating the prototype. This involves the development of the Laravel 12 artifact, the implementation of hash-chaining logic, and the refinement of the user interface based on Records Office feedback.
3. **Rigor Cycle:** Grounds the artifact in existing scientific knowledge. This study utilizes established cryptographic standards (SHA-256, Ed25519) and software engineering patterns (Active Guard middleware, Asynchronous jobs) to ensure the prototype is technically sound.

### 3.2 Research Framework
The development follows the six stages of the DSR process:
1. **Problem Identification:** Observing the printing race conditions and the bypass of formal tracking via manual Google Sheets.
2. **Definition of Objectives:** Establishing requirements for immutability, non-repudiation, and centralized code management.
3. **Design and Development:** Building the Laravel 12 prototype with the "Trust Builder" hash-chaining module.
4. **Demonstration:** Using seeded data (10,000 records) to prove the system's performance and integrity verification capabilities.
5. **Evaluation:** Testing the system's ability to detect manual database alterations via the `dts:verify-integrity` command.
6. **Communication:** Documenting the findings in this thesis and presenting the prototype to the DepEd IT Department.

### 3.3 Problem Identification and Motivation
The motivation for this study was driven by the discovery of a massive "Shadow System" at DepEd Iligan. The Records Department’s manual releasing log had surpassed 10,000 untracked entries, highlighting a failure in the legacy system’s ability to manage internal documents. The need for a unified, secure, and locally-deployed system provided the impetus for this research.

### 3.4 Objectives of the Solution
| Objective Type | Description |
| :--- | :--- |
| **Functional** | Automate intake, suggest routes via AI, and confirm physical receipt via QR scans. |
| **Non-Functional** | Ensure sub-second integrity checks and handle high-volume reporting without memory crashes. |
| **Technical** | Implement Ed25519 signatures and SHA-256 hash-chaining using the Sodium library in PHP. |
| **Theoretical** | Demonstrate how "trustless" audit trails can be implemented in a local government setting. |

### 3.5 Research Locale and Participants
- **Locale:** DepEd Division Office of Iligan City.
- **Participants:** The prototype was designed for three primary user groups:
    1. **Records Officers (Primary Users):** Responsible for intake and routing.
    2. **Department Staff:** Responsible for receiving and processing documents.
    3. **IT Administrators:** Responsible for system health and integrity monitoring.
- **Sampling:** Purposive sampling was used to identify key staff members involved in the manual Google Sheets workaround to gather requirements.

### 3.6 Artifact Design and Development Plan
The artifact was developed using an **Agile-within-DSR** approach:
- **Iteration 1:** Core Database Schema and Guest Portal (Solving the "Overwrite" issue).
- **Iteration 2:** Trust Builder and Signing logic (Implementing Cryptographic Integrity).
- **Iteration 3:** QR Scanning and Releasing Workflow (Standardizing physical hand-offs).
- **Iteration 4:** Analytics and Bottleneck Detector (Providing administrative oversight).

### 3.7 Evaluation Framework
Evaluation follows the Framework for Evaluation in Design Science (FEDS):
- **Ex-Ante (Simulated):** Using the `DocumentFactory` to seed 10,000 documents and testing the "Active Guard" against manual SQL injections.
- **Ex-Post (Naturalistic):** Gathering feedback on the UI/UX from the Records Head and staff to ensure the "Forced Submission" workflow is practical.

### 3.8 Data Gathering Procedures
Data was gathered through:
- **Observation:** Direct witnessing of the legacy system's failures during the December visit.
- **Interviews:** Informal talks with the IT Head and Records Department regarding their "Google Sheets" workaround.
- **System Logs:** Analyzing the prototype's generated hash-chains to ensure mathematical consistency.

### 3.9 Data Analysis
- **Quantitative:** Measuring report generation times and database query performance for large datasets.
- **Qualitative:** Thematic analysis of user sentiments regarding the transition from manual logs to cryptographic signing.

### 3.10 Ethical Considerations
- **Data Privacy:** Personal data of guests is encrypted and only accessible via authorized roles.
- **Non-Repudiation:** Users are informed that their digital signatures are mathematically bonded to their actions and cannot be undone.
- **Local Deployment:** The prototype complies with the IT Head's requirement for local hosting to prevent data exposure outside the division's network.
