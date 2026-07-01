**B**

This chapter presents the proposed system design and the development
framework for \<the proposed system\>. \<Brief Description\>. It
discusses the system architecture, design models, diagrams, data
structures, and the methodological approach that will guide system
development. The goal of this chapter is to provide a clear,
comprehensive blueprint for how the system will be conceptualized,
built, tested, and deployed.

According to Peffers et al. (2007), the Design & Development phase
involves producing the artifact that addresses the identified problem.
This includes:

-   Designing the system's conceptual structure

-   Modeling processes, data, and interactions

-   Creating prototypes

-   Constructing initial system components

In this study, the artifact will be developed using Agile (cite Agile
study) iterative construction, allowing refinement through continuous
feedback while remaining aligned with the DSRM framework.

Within the DSRM framework, the Design and Development Phase focuses on
translating the solution objectives into a functional artifact. This
involves:

1.  Designing the system's structure, models, and data flows

2.  Developing prototypes that demonstrate and validate concepts

3.  Constructing initial system components that will later be refined
    > during evaluation

To effectively execute these activities, the development process adopts
Agile iterations, allowing progressive system construction and frequent
stakeholder feedback. Agile is a development strategy used inside DSRM's
Design & Development phase. Its role is to guide the incremental
construction of the artifact.

### Agile practices applied in this phase include:

-   Iterative sprint cycles for building system modules

-   Continuous feedback from counselors

-   Progressive enhancement of prototypes

-   Early demonstration of features for refinement

\<insert diagram\>

# 4.1 Overview of the Proposed Artifact

(Write paragraphs describing your system in general.)

Example from My.GUIDE (Group 5):

The proposed system, My.GUIDE, is a web-based case management platform
designed for the Office of Guidance and Counseling (OGC) at MSU--IIT. It
addresses the inefficiencies of the existing manual counseling workflow
by offering:

-   Digital case creation and recordkeeping

-   Automated rule-based intervention suggestions

-   Digitized counseling forms

-   Centralized retrieval of student data

-   Analytics dashboards and automated reporting

> *Figure X. Current System Activity Diagram*

This system architecture directly operationalizes the solution
objectives identified in the first two stages of DSRM:

> \(1\) reduce administrative workload,
>
> \(2\) improve data accessibility, and
>
> \(3\) support evidence-based decision-making..

**4.2 System Architecture (Artifact Design)**

(This section presents the high-level technical structure of the
artifact.)

The artifact adopts a Four-Tier Architecture designed to ensure
scalability, modularity, and secure handling of sensitive counseling
records.

### **4.2.1 Architectural Style**

*(Choose one: Three-Tier, Four-Tier, Client-Server, Cloud-Based)\
* Describe each layer and its role:

-   **Presentation Layer** -- handles user interface and interaction

-   **Application/Logic Layer** -- processes business logic

-   **Data Layer** -- stores structured information

-   **Hosting/Infrastructure Layer** -- supports deployment and access

### **4.2.2 Security Framework**

-   Encrypted communication (e.g., TLS)

-   Password hashing (e.g., Argon2, bcrypt)

-   Role-based access control (RBAC)

-   Audit logging

### **Example From Group 5:**

### **4.2.1 Presentation Layer (Frontend)**

-   Developed using React.js

-   Displays dashboards, case forms, session notes, and analytics

-   Handles user interaction and input validation

-   Communicates with backend through RESTful APIs

### **4.2.2 Application Layer (Backend)**

-   Implemented using Python (Flask)

-   Executes business logic, authentication, and automated
    > recommendations

-   Manages analytics computation

-   Orchestrates data operations between UI and database

### **4.2.3 Data Layer (Database)**

-   MySQL relational database

-   Stores student information, case data, session notes, intervention
    > records, and system logs

-   Ensures data integrity and consistency

### **4.2.4 Cloud Hosting Layer**

-   Supports remote access and availability

-   Hosts frontend, backend, and database

-   Implements backup and failover mechanisms

### **Security Controls**

-   AES encryption for sensitive data

-   Argon2 password hashing

-   Role-Based Access Control (RBAC)

-   TLS 1.2+ for secure communication

## **4.3 System Design Models**

(All diagrams from the SRS can be placed here --- this section is
designed for them.)

The following models and diagrams are developed as part of the artifact
design process. These models define the structure, behavior, and data
interactions of the proposed system and guide the construction of the
artifact.

### **4.3.1 Activity Diagram / BPMN**

*(Insert diagram + explanation*)

### **4.3.2 Use Case Diagram**

> *(Insert diagram + explanation)*

### **4.3.3 Use Case Descriptions**

> *(Insert tables/descriptions)*

### **4.3.4 Sequence Diagram**

> *(Insert diagram + explanation)*

### **4.3.5 Context Diagram**

*(Insert diagram + explanation)*

### **4.3.6 Entity--Relationship Diagram (ERD)**

> *(Insert ERD + explanation)*

### **4.3.7 Component Diagram**

*(Insert diagram + explanation)*

### **4.3.8 Class Diagram *(optional)***

> *(Insert diagram + explanation)*

### **4.3.9 Package Diagram *(optional)***

> *(Insert diagram + explanation)*

## **4.4 Database Design**

### **4.4.1 Logical Database Structure**

# *(Refer to ERD. Describe entities and relationships.)*

### **4.4.2 Data Dictionary**

# *Provide fields, data types, descriptions, validation rules.*

# 4.4 User Interface Design

Provide screen layouts, wireframes, or prototypes.

### **4.4.1 Low-Fidelity Prototype**

> *(Insert sketches, wireframes)*

### **4.4.2 High-Fidelity Prototype**

*(Insert Figma/mockup screens)*

Include descriptions of navigation flow, layout decisions, and
accessibility considerations.

## **4.5 Artifact Development Strategy**

### **4.5.1 Use of Agile in the DSRM Framework**

Explain that Agile is used as an artifact construction strategy, not the
research methodology.

Example:

> Agile iterative development is applied within the DSRM Design &
> Development phase to support incremental construction, continuous
> stakeholder feedback, and progressive refinement of the artifact.

### **4.5.2 Sprint Structure**

List general sprint plans (you may customize):

-   Sprint 1: Initial interface and authentication

-   Sprint 2: Core module development

-   Sprint 3: Database integration

-   Sprint 4: Advanced features

-   Sprint 5: Testing and polishing

### **4.5.3 Tools and Technologies**

Example:

-   Frontend: HTML/CSS/JS, Bootstrap, React

-   Backend: Python, PHP, Java, or Node.js

-   Database: MySQL, PostgreSQL

-   Tools: Figma, Visual Studio Code, GitHub, XAMPP, Postman

### **4.5.1 Sprint-Based Construction**

Each sprint focuses on building specific modules of the artifact:

-   **Sprint 1:** User authentication, dashboard layout

-   **Sprint 2:** Case creation and digitized forms

-   **Sprint 3:** Rule-based recommendation engine

-   **Sprint 4:** Intervention sending and email integration

-   **Sprint 5:** Analytics dashboards

-   **Sprint 6:** Report generation and data visualization

### **4.5.2 Continuous Integration Activities**

-   Code integration and testing

-   Usability reviews with counselors

-   Adjustment of system components based on feedback

-   Error fixing and enhancement

### **4.5.3 Deliverables of the Design & Development Phase**

-   System architecture

-   Complete system models

-   Database schema

-   Working prototype

-   Initial system modules

**Development Tools and Platform**

  -----------------------------------------------------------------------
  **Tool**                     **Purpose**
  ---------------------------- ------------------------------------------
  Visual Studio Code / PyCharm Integrated development environment for
                               coding

  GitHub                       Version control and collaborative
                               development

  Figma / Adobe XD             User interface prototyping

  Postman                      API testing

  Firebase / AWS               Cloud hosting and authentication services
  -----------------------------------------------------------------------

**Phased Development Plan ( for not using Agile)**

The development process will follow iterative prototyping aligned with
the DSR Build--Evaluate cycle.

  -----------------------------------------------------------------------
  Phase   Description                               Output
  ------- ----------------------------------------- ---------------------
  Phase 1 Requirement gathering and analysis        System specifications

  Phase 2 Prototype design (UI mockups,             Low-fidelity
          architecture)                             prototype

  Phase 3 System development                        Functional prototype

  Phase 4 Testing and refinement                    Improved prototype

  Phase 5 Final evaluation and deployment           Validated artifact
  -----------------------------------------------------------------------

A Gantt chart will be prepared to show the project timeline across these
phases.

**4.6 Planned Demonstration and Evaluation (Optional)**

The prototype will be demonstrated to selected participants to assess
its ability to address the identified problem. Evaluation will follow
the FEDS approach, applying ex-ante expert evaluation and ex-post user
testing.

Planned evaluation criteria include:

-   Functionality: System performs expected tasks.

-   Usability: Users find the interface intuitive and efficient.

-   Performance: Response times and resource usage meet benchmarks.

-   User Satisfaction: Positive feedback from end-users.

The findings from these evaluations will guide further refinements
before final deployment.

**4.7 Expected Outcomes and Contributions (Optional)**

The project is expected to yield both practical and theoretical
contributions:

-   Practical Contribution: A validated software artifact that addresses
    > a specific real-world need.

-   Theoretical Contribution: Design principles and evaluation insights
    > that extend DSR knowledge within software development.

-   Institutional Impact: A framework that can serve as a model for
    > similar IT-based innovation initiatives.

**4.8 Summary**

\<\>

**References**

Gregor, S., & Hevner, A. R. (2013). Positioning and presenting design
science research for maximum impact. *MIS Quarterly, 37*(2), 337--355.
[[https://doi.org/10.25300/MISQ/2013/37.2.01]{.underline}](https://doi.org/10.25300/MISQ/2013/37.2.01)

[\
]{.underline} Hevner, A. R., March, S. T., Park, J., & Ram, S. (2004).
Design science in information systems research. *MIS Quarterly, 28*(1),
75--105.
[[https://doi.org/10.2307/25148625]{.underline}](https://doi.org/10.2307/25148625)

[\
]{.underline} March, S. T., & Smith, G. F. (1995). Design and natural
science research on information technology. *Decision Support Systems,
15*(4), 251--266.
[[https://doi.org/10.1016/0167-9236(94)00041-2]{.underline}](https://doi.org/10.1016/0167-9236(94)00041-2)

[\
]{.underline} Peffers, K., Tuunanen, T., Rothenberger, M. A., &
Chatterjee, S. (2007). A design science research methodology for
information systems research. *Journal of Management Information
Systems, 24*(3), 45--77.
[[https://doi.org/10.2753/MIS0742-1222240302]{.underline}](https://doi.org/10.2753/MIS0742-1222240302)

[\
]{.underline} Venable, J., Pries-Heje, J., & Baskerville, R. (2016).
FEDS: A framework for evaluation in design science research. *European
Journal of Information Systems, 25*(1), 77--89.
[[https://doi.org/10.1057/ejis.2014.36]{.underline}](https://doi.org/10.1057/ejis.2014.36)

[\
]{.underline} Wieringa, R. (2014). *Design science methodology for
information systems and software engineering.* Springer.
