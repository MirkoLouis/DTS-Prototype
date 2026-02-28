# Document Hashing & Chain Logic (The Trust Builder)

The "Trust Builder" is a sophisticated security layer implemented in the Document Tracking System (DTS) that guarantees the immutability of document records using cryptographic hash-chaining.

## Table of Contents
1. [Hash-Chain vs. Blockchain](#hash-chain-vs-blockchain)
2. [How it Works: The Digital Chain](#how-it-works-the-digital-chain)
3. [Implementation Details](#implementation-details)
4. [What Makes it "Unbreakable"?](#what-makes-it-unbreakable)
5. [Security & Computing Requirements](#security--computing-requirements)
6. [Administrative Tools](#administrative-tools)
7. [Benefits & Disadvantages](#benefits--disadvantages)

---

## Hash-Chain vs. Blockchain

While both technologies use cryptographic hashes to link data, they serve different purposes:

| Feature | Hash-Chain (This Project) | Blockchain (e.g., Bitcoin) |
|:---|:---|:---|
| **Control** | **Centralized:** Managed by a single authority (DepEd). | **Decentralized:** Managed by a global network of nodes. |
| **Trust Model** | Trust is built into the application's audit trail. | Trustless; everyone verifies everyone else. |
| **Cost** | Extremely low; runs on standard servers. | Extremely high; requires massive electricity for mining. |
| **Speed** | Near-instantaneous. | Slow (minutes or hours for confirmation). |

### A Simple Scenario
-   **Blockchain:** 1,000 people each have a copy of a ledger. To change one entry, you must convince 501 people to change theirs simultaneously.
-   **Hash-Chain:** One master ledger exists. Every time a new entry is added, it "locks" the previous entry with a digital fingerprint. If anyone changes an old entry, the "lock" on the next entry breaks, making the tampering immediately visible to the auditor.

---

## How it Works: The Digital Chain

Every action performed on a document (Submit, Receive, Forward, Release) creates a `DocumentLog` entry. Each entry is cryptographically linked to the one before it using the **SHA-256** algorithm.

### The Anatomy of a Link
Each log entry contains two critical fields:
1.  **`previous_hash`**: The digital fingerprint of the log entry that came immediately before it for that specific document.
2.  **`hash`**: The unique fingerprint of the *current* entry.

### What data is hashed?
To ensure total security, the current `hash` is calculated from:
-   `document_id` + `user_id` + `action`
-   **Timestamp:** Precision down to microseconds (ISO-8601).
-   **`previous_hash`:** The link to the past.
-   **`document_state_hash`:** A snapshot of the document's metadata (Title, Submitter, Purpose) at that exact moment.

---

## Implementation Details

The hashing logic is embedded directly into the **`DocumentLog`** model's `boot()` method. This ensures that:
-   Hashing happens **automatically** whenever a log is created.
-   It cannot be bypassed by standard application code.
-   The first log entry for any document uses a special `genesis_hash` as its starting point.

---

## What Makes it "Unbreakable"?

The system is "unbreakable" in a standard operational context because of the **Forward-Propagation of Errors**:

1.  If an intruder manually changes the `action` of a log from 3 days ago in the database...
2.  The `hash` for that log no longer matches its data.
3.  The *next* log in the chain (2 days ago) was built using the old `hash`. Since that old `hash` is now invalid, the *entire chain from that point forward* breaks.
4.  Even if the intruder recalculates the hash for that one log, they would have to recalculate the hashes for **every subsequent log** for that document to hide their tracks.

---

## Security & Computing Requirements

Compared to traditional Blockchain, a Hash-Chain offers a **massive security-to-resource ratio**:

-   **Computing Requirements:** Negligible. A standard web server can verify thousands of hash chains in seconds. It does not require "Mining" or "Proof of Work."
-   **Security Level:** For a centralized system, it is mathematically "perfect." Unless an intruder has the power to overwrite the entire database and re-calculate thousands of complex SHA-256 hashes instantly, they cannot hide their tampering.

---

## Administrative Tools

### 1. Verification (`php artisan dts:verify-integrity`)
This command is the "Heart" of the Trust Builder. It:
-   Iterates through every document log in the system.
-   Recalculates every hash from scratch.
-   Compares the new hash to the one stored in the database.
-   Reports the exact Log ID and Document Tracking Code where a mismatch is found.

### 2. Intruder Simulation (`php artisan dts:corrupt-log {logId}`)
This tool allows administrators to test the system's defenses. It acts like an "Actual Intruder" by:
-   Manually changing the `action` text of a log directly in the database.
-   **Crucially, it does NOT update the hash.**
-   Running the verification command immediately after will flag this log as corrupted, proving the system works.

---

## Benefits & Disadvantages

### Benefits
-   **Immutability:** Provides absolute proof that a document's history hasn't been altered.
-   **Auditability:** Every action is linked to a user and a precise timestamp.
-   **Efficiency:** High security with almost zero performance impact.

### Disadvantages
-   **Centralization:** Since it is centralized, the "Root of Trust" is the DepEd Division server itself. If the entire server (including the database and the code) is compromised, a very sophisticated attacker could theoretically rebuild the entire chain.
-   **Complexity:** Requires developers to understand cryptographic principles to maintain the core logic.
