# **CHAPTER 3**

# **RESEARCH METHODOLOGY**

This chapter details the research design and development methodology used to create the Document Tracking System (DTS) for DepEd Iligan City. The study follows the Design Science Research (DSR) methodology to address institutional problems through the creation of an innovative IT artifact, utilizing Rapid Application Development (RAD) as the strategy for building and refining the system.

## **3.1 Research Design**

This study employs the Design Science Research (DSR) methodology, an approach suited for developing and evaluating innovative information technology artifacts that address identified organizational problems (Hevner et al., 2004). DSR focuses on both the creation of purposeful tools and the generation of design knowledge that contributes to practice (March & Smith, 1995). 

The study adopts the Design Science Research Methodology (DSRM) proposed by Peffers et al. (2007), which provides a systematic process consisting of six key stages:
1.  Problem identification and motivation
2.  Definition of the objectives of a solution
3.  Design and development of the artifact
4.  Demonstration of the artifact
5.  Evaluation of the artifact
6.  Communication of the results

These stages are iteratively executed following Hevner's (2007) three-cycle model:

| DSR Cycle | Description |
| :--- | :--- |
| **1. Relevance Cycle** | Identify the problem: "DepEd Iligan lacks a secure, unified document tracking system, leading to data loss and shadow systems." Collect requirements from the Records and IT Departments. |
| **2. Design Cycle** | Build the artifact (the DTS prototype) using RAD and refine it iteratively based on client feedback. Define the data model, security rules, and QR workflows. |
| **3. Rigor Cycle** | Use existing knowledge: cryptographic hash-chains, Ed25519 signatures, and prior IT research to support design decisions. |

## **3.2 Research Framework**

The DSR framework connects the environment, design science activities, and the knowledge base that together form the foundation of the DSR process. This framework ensures that the study is grounded in proven theories, relevant to real-world needs at DepEd, and methodologically rigorous. The iterative "Build-Evaluate" loop, driven by RAD, allows continuous refinement of the artifact until it effectively meets the defined objectives.

*(Insert Figure 3.1: Design Science Research Framework adapted from Hevner et al. (2004))*

## **3.3 Problem Identification and Motivation**

The study began with an in-depth analysis of the problem environment at the DepEd Division Office of Iligan City in December 2025. Through empirical observation and interviews, researchers identified critical inefficiencies in the existing intake infrastructure, specifically race conditions that caused print jobs to overwrite one another.

More significantly, the study identified the widespread use of informal "Shadow Systems" (such as Google Sheets) due to the inadequacy of the formal tracking system. This practice compromises the integrity of official records and creates disconnected tracking codes, making a unified audit impossible. The motivation for creating an innovative IT-based solution is to provide a highly secure, centralized, and locally-hosted platform that eliminates these vulnerabilities and complies with the efficiency mandates of Republic Act No. 11032.

## **3.4 Objectives of the Solution**

Based on the problem definition, the study establishes clear objectives for the proposed solution, which serve as measurable criteria for success. These objectives are classified into functional, non-functional, technical, and theoretical dimensions:

| Objective Type | Description |
| :--- | :--- |
| **Functional Objectives** | Automate document intake, provide AI-assisted route suggestions, enforce QR code scanning for physical hand-offs, and generate real-time status updates for guests. |
| **Non-Functional Objectives** | Ensure the system remains fast and responsive (sub-second load times) even when managing over 1,000,000 records, and guarantee a highly usable interface for staff. |
| **Technical Objectives** | Implement SHA-256 hash-chaining and Ed25519 digital signatures to create an immutable audit trail, restricted to a local, on-premise network environment. |
| **Theoretical Objectives** | Demonstrate how blockchain-inspired security concepts can be practically implemented in a centralized, resource-constrained government IT environment. |

## **3.5 Research Locale and Participants**

The study is conducted within the DepEd Division Office of Iligan City, specifically focusing on the administrative workflow of official documents across various functional units (Records, Cash, Personnel, Supply, etc.).

A **purposive sampling** strategy was used to involve key personnel during both the pre-development and post-development phases:
-   **Needs Assessment:** Involved Records Officers and the IT Department Head to identify operational gaps and define strict security requirements.
-   **Prototype Evaluation:** Approximately 10 key personnel from the Records and IT departments will be involved to evaluate the usability and functionality of the DTS artifact. These participants were selected because they directly handle document intake and system maintenance. 

Informed consent will be obtained from all participants prior to evaluation.

## **3.6 Artifact Design and Development Plan**

The artifact, the Document Tracking System (DTS), is developed using the **Rapid Application Development (RAD)** methodology (Martin, 1991). RAD is applied within the DSRM Design & Development phase to support rapid prototyping and continuous user feedback. The process follows these cycles:

1.  **Requirements Planning:** Defining the scope and security objectives (Non-repudiation, Integrity) with the IT and Records departments.
2.  **User Design:** Developing the first functional prototype to resolve basic intake errors and demonstrate the core hash-chaining logic to clients.
3.  **Construction:** Integrating advanced features such as QR code physical hand-offs, Ed25519 signatures, and database performance optimizations based on continuous client feedback.
4.  **Cutover:** Final demonstration and evaluation of the complete feature set against institutional demands and RA 11032 requirements.

Documentation is maintained through system logs containing records of design decisions, security implementations, and user feedback, ensuring practical utility and scientific contribution.

## **3.7 Evaluation Framework**

Evaluation is conducted to determine the utility, quality, and efficacy of the artifact following the Framework for Evaluation in Design Science (FEDS) by Venable, Pries-Heje, and Baskerville (2016).

| Evaluation Type | Setting | Method | Purpose |
| :--- | :--- | :--- | :--- |
| **Ex-ante** | Artificial | Expert review, automated integrity simulation, performance load testing (1,000,000 records). | Assess expected performance, security resilience, and algorithmic scalability before full implementation. |
| **Ex-post** | Naturalistic | Task-based usability testing, System Usability Scale (SUS) survey, user feedback. | Assess real-world effectiveness, interface clarity, and acceptance by Records Officers and IT staff. |

## **3.8 Data Gathering Procedures**

Data is gathered from multiple sources to inform the DSR framework and RAD cycles:
-   **Key Informant Interviews & Observation:** Initial data collection to document existing race conditions and shadow system usage.
-   **Usability Testing Sessions:** Participants will be given standardized tasks to perform (Intake, Receiving via QR, Releasing) to measure system efficiency.
-   **System Usability Scale (SUS):** Immediately following the testing session, participants will complete a digital questionnaire based on the SUS to measure perceived usability.
-   **System Logs & Automated Tests:** Technical metrics will be gathered from automated integrity checks and high-volume performance benchmarking scripts.

## **3.9 Data Analysis**

Data collected throughout the study will be processed and analyzed using quantitative and qualitative methods:
-   **Qualitative Data:** Feedback from interviews and usability testing will undergo thematic analysis to identify recurring operational issues and emergent design preferences.
-   **Quantitative SUS Scores:** Will be calculated to determine a standardized usability score (0-100), with a target rating of "Good" (>68) to validate interface accessibility.
-   **Performance Metrics:** System logs from load simulations will be analyzed to verify that database queries and dashboard visualizations maintain sub-second response times under concurrent load.

## **3.10 Ethical Considerations**

All study procedures comply with the Republic Act No. 10173 (Data Privacy Act of 2012). Ethical approval will be secured from the relevant institutional committee prior to formal data collection. Participation is strictly voluntary, and informed consent will be obtained from all institutional respondents. Data confidentiality and anonymity will be strictly maintained; participants will be assigned identification numbers to prevent personal identification in reports. All collected data is used solely for the evaluation of the DTS prototype and is stored on secure, local devices as mandated by the institutional IT policy.
