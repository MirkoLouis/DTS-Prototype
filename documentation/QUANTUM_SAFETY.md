# DTS Quantum Safety & Post-Quantum Strategy

## Summary
A technical assessment of the Document Tracking System's resilience against quantum-based attacks. This document analyzes the vulnerabilities of classical asymmetric cryptography, the relative safety of symmetric hashing, and the roadmap for transitioning to **Post-Quantum Cryptography (PQC)**.

## Table of Contents
1. [The Quantum Threat Model](#1-the-quantum-threat-model)
2. [Vulnerability Analysis: Ed25519 vs. Shor's Algorithm](#2-vulnerability-analysis-ed25519-vs-shors-algorithm)
3. [Resilience Analysis: SHA-256 vs. Grover's Algorithm](#3-resilience-analysis-sha-256-vs-grovers-algorithm)
4. [The "Gaslighting" Attack Scenario](#4-the-gaslighting-attack-scenario)
5. [Mitigation & Post-Quantum Roadmap](#5-mitigation--post-quantum-roadmap)
6. [Glossary of Terms](#6-glossary-of-terms)

---

## 1. The Quantum Threat Model

The DTS "Trust Builder" relies on two primary cryptographic pillars:
1.  **SHA-256**: Ensures data integrity and chains the ledger blocks.
2.  **Ed25519**: Ensures **non-repudiation** and **authenticity** of actions.

In a post-quantum world, these pillars face two distinct threats: **Shor's Algorithm** and **Grover's Algorithm**.

---

## 2. Vulnerability Analysis: Ed25519 vs. Shor's Algorithm

The most critical vulnerability in the current system is the **Ed25519** signature layer.

### The Mathematical Break
Ed25519 is based on the **Elliptic Curve Discrete Logarithm Problem (ECDLP)**.
*   **Classical Attack:** Solving ECDLP is **computationally infeasible** because it requires exponential time.
*   **Quantum Attack:** **Shor's Algorithm** can solve the discrete logarithm problem in **polynomial time**.

### Impact
A Cryptographically Relevant Quantum Computer (CRQC) could derive a user's **Private Key** simply by observing their **Public Key**. Once the private key is derived, the **non-repudiation** guarantee is destroyed, as the attacker can forge any signature.

---

## 3. Resilience Analysis: SHA-256 vs. Grover's Algorithm

The **SHA-256** **Hash Chain** and the `document_state_hash` are significantly more resilient.

### The "Grover's Speedup"
**Grover's Algorithm** provides a "square-root speedup" for finding pre-images or collisions in hash functions.
*   **Classical Security:** 256-bit security.
*   **Post-Quantum Security:** ~128-bit security.

### Impact
Even with the quantum speedup, 128 bits of security remains **computationally infeasible** for the foreseeable future. While the **authenticity** (who signed it) is vulnerable, the **immutability** of the data remains a strong defense.

---

## 4. The "Gaslighting" Attack Scenario

Without quantum-resistant signatures, an attacker can bypass the `dts:verify-integrity` command by "re-writing reality."

### Attack Steps:
1.  **Modify Data:** The attacker changes a document's title or a log's action in the database.
2.  **Forge Signature:** Using a quantum computer, they derive the user's **Private Key** and generate a **new, valid signature** for the modified data.
3.  **Repair the Chain:** They update the `hash` and `previous_hash` for every subsequent log entry in that document's chain.
4.  **Sync the State:** They update the `document_state_hash` to match the modified document metadata.

### The Result
The `VerifyIntegrityChain` command will report **100% Success**. The command verifies that the math matches the data; it cannot know that the data and the math were both systematically replaced by a quantum-capable adversary.

---

## 5. Mitigation & Post-Quantum Roadmap

To achieve true "Quantum-Safe" status, the system must move beyond Elliptic Curves to **Lattice-Based Cryptography**.

### A. Migration to Post-Quantum Signatures (PQC)
The project should aim to replace or supplement Ed25519 with NIST-standardized PQC algorithms:
*   **ML-DSA (Dilithium):** A lattice-based signature scheme offering high security and moderate signature sizes (~2.4 KB).
*   **SLH-DSA (SPHINCS+):** A hash-based signature scheme that is extremely conservative and relies only on the security of the underlying hash function.

### B. Hybrid Signatures
A "Conservative Transition" strategy involves **Hybrid Signatures**. Every log entry is signed twice: once with Ed25519 and once with a PQC algorithm. A log is only valid if **both** signatures verify. This protects against flaws in new PQC math while maintaining current security standards.

### C. External Anchoring (External Trust)
To prevent the "Total Rewrite" attack, the system can "anchor" its latest global hash to an external, immutable source:
*   **Remote Log Servers:** Write-only syslog servers that an attacker cannot access to modify.
*   **Public Witnessing:** Periodically publishing the system's "Root Hash" to a public blockchain or a Trusted Timestamping Authority.

---

## 6. Glossary of Terms

*   **Authenticity:** The quality of being genuine and authorized by the correct person.
*   **Computationally Infeasible:** So difficult and time-consuming that it is impossible for any current or near-future computer to solve.
*   **Ed25519:** A specific type of mathematical lock used for digital signatures.
*   **Elliptic Curve Discrete Logarithm Problem (ECDLP):** The specific math puzzle used to lock our current cryptographic keys.
*   **Grover's Algorithm:** A quantum computer program that speeds up the process of searching through many possibilities.
*   **Hash Chain:** A series of connected digital fingerprints where each one depends on the one before it.
*   **Immutability:** The quality of being impossible to change after being created.
*   **Lattice-Based Cryptography:** A type of advanced math based on points in a grid, used for security.
*   **Non-Repudiation:** A legal and technical guarantee that a person cannot deny they performed an action.
*   **Polynomial Time:** A mathematical term meaning the time it takes to solve a problem grows at a manageable rate, making it "fast" for a computer.
*   **Post-Quantum Cryptography (PQC):** Security methods that even a quantum computer cannot break.
*   **Private Key:** A secret code used to sign documents.
*   **Public Key:** A code shared with the system to verify your identity.
*   **SHA-256:** A method of creating a unique "fingerprint" for data.
*   **Shor's Algorithm:** A quantum computer program that can solve complex math puzzles very quickly.
