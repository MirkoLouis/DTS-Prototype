# Document Hashing & Chain Logic (The Merkle-Chain Trust Builder)

The "Trust Builder" is a sophisticated security layer implemented in the Document Tracking System (DTS) that guarantees the immutability of document records using cryptographic Merkle-chaining (a private hash-chain).

## Table of Contents
1. [Hash-Chain vs. Blockchain](#hash-chain-vs-blockchain)
2. [How it Works: The Digital Chain](#how-it-works-the-digital-chain)
3. [The "Block" Definition](#the-block-definition)
4. [Implementation Details](#implementation-details)
5. [Scaling & Performance (The 1-Million Record Goal)](#scaling--performance-the-1-million-record-goal)
6. [Non-Repudiation: Digital Signatures](#non-repudiation-digital-signatures)
7. [What Makes it "Unbreakable"?](#what-makes-it-unbreakable)
8. [Security & Computing Requirements](#security--computing-requirements)
9. [Administrative Tools](#administrative-tools)
10. [Benefits & Disadvantages](#benefits--disadvantages)

---

## Hash-Chain vs. Blockchain

While both technologies use cryptographic hashes to link data, they serve different purposes. This system uses a **Merkle-Chain** approach:

| Feature | This System (Merkle-Chain) | Traditional Blockchain (Public) |
|:---|:---|:---|
| **Control** | **Centralized:** Managed by a single authority (DepEd). | **Decentralized:** Managed by a global network of nodes. |
| **Trust Model** | Trust is built into the application's audit trail. | Trustless; everyone verifies everyone else. |
| **Tamper Detection**| High (Cryptographic) | High (Cryptographic) |
| **Immutability** | Administrative (Centralized) | Absolute (Decentralized) |
| **Performance** | **Ultra-Fast** (No Consensus/Mining). | Slow (Requires Consensus). |
| **Cost** | Free (Standard Server). | Expensive (Gas Fees/Mining Power). |

### A Simple Scenario
-   **Blockchain:** 1,000 people each have a copy of a ledger. To change one entry, you must convince 501 people to change theirs simultaneously.
-   **Hash-Chain (Merkle-Chain):** One master ledger exists. Every time a new entry is added, it "locks" the previous entry with a digital fingerprint. If anyone changes an old entry, the "lock" on the next entry breaks, making the tampering immediately visible to the auditor.

---

## How it Works: The Digital Chain

Every action performed on a document (Submit, Receive, Forward, Release) creates a `DocumentLog` entry. Each entry is cryptographically linked to the one before it using the **SHA-256** algorithm.

### The Anatomy of a Link
Each log entry contains three critical security fields:
1.  **`previous_hash`**: The digital fingerprint of the log entry that came immediately before it for that specific document.
2.  **`hash`**: The unique fingerprint of the *current* entry.
3.  **`signature`**: The department's unique digital signature (Public Key) used to "sign" the action.

---

## The "Block" Definition

In this Merkle-Chain architecture, we define the structure as follows:
- **Block:** A single `DocumentLog` record representing a specific lifecycle event.
- **Chain:** The chronological sequence of logs for a specific `Document`, linked via hashes.
- **Genesis Block:** The first log of any document (marked with `previous_hash: "genesis_hash"`).

---

## Implementation Details

The hashing logic is embedded directly into the **`DocumentLog`** model's `boot()` method. This ensures that:
-   Hashing happens **automatically** whenever a log is created.
-   It cannot be bypassed by standard application code.
-   To ensure total security and **Non-Repudiation**, the current `hash` is calculated from:
    - `document_id` + `user_id` + `action`
    - **Timestamp:** Precision down to seconds (ISO-8601).
    - **`previous_hash`:** The link to the past.
    - **`document_state_hash`:** A snapshot of the document's metadata (Title, Submitter, Purpose) at that exact moment.
    - **`signature`:** The performing user/department's digital key.

---

## Scaling & Performance (The 1-Million Record Goal)

To ensure "Blockchain-grade" integrity doesn't sacrifice speed, the system employs a hybrid optimization strategy:

1.  **Hybrid Architecture (MySQL + Redis):**
    - **MySQL (The Ledger):** Stores the persistent, hash-chained document logs.
    - **Redis (The Speed Layer):** Handles all caching for the Admin Dashboard. While MySQL retrieval takes ~5-20ms, Redis takes **<1ms**, allowing for real-time analytics even with massive datasets.
2.  **Database Chunking:** During integrity verification (`dts:verify-integrity`), the system processes records in **1,000-record batches**. This keeps RAM usage constant whether checking 10,000 or 10,000,000 records.
3.  **Pre-Aggregated Analytics:** Heavy SQL math (like throughput calculations) is performed every 5 minutes and cached, ensuring the dashboard remains responsive (sub-second load times).

---

## Non-Repudiation: Digital Signatures

A key innovation of this DTS is the enforcement of **Non-Repudiation**. This means that no authorized user (Staff, Officer, or Admin) can later deny having performed an action.

### 1. Initialization
Upon the first official login, the system presents a **Security Key Initialization** modal. The user must choose or generate a unique digital signature (e.g., `DTS-PUB-RECORDS-A84BC...`).

### 2. Cryptographic Binding
Once initialized, this signature is stored in the `users` table. Every subsequent action automatically attaches this signature to the `DocumentLog`. Because this signature is part of the data used to generate the log's `hash`, any attempt to change the authority associated with a historical log will break the entire chain.

### 3. Proof of Authority
The `signature` field serves as a verifiable "stamp" of which specific account was responsible for an action. This is especially critical for administrative actions like **Hash Chain Rebuilds**, which are permanently signed by the Admin who performed them.

---

## What Makes it "Unbreakable"?

The system is "unbreakable" in a standard operational context because of the **Forward-Propagation of Errors**:

1.  If an intruder manually changes the `action` of a log from 3 days ago in the database...
2.  The `hash` for that log no longer matches its data.
3.  The *next* log in the chain (2 days ago) was built using the old `hash`. Since that old `hash` is now invalid, the *entire chain from that point forward* breaks.
4.  Even if the intruder recalculates the hash for that one log, they would have to recalculate the hashes for **every subsequent log** for that document to hide their tracks.

---

## Security & Computing Requirements

Compared to traditional Blockchain, a Merkle-Chain offers a **massive security-to-resource ratio**:

-   **Computing Requirements:** Negligible. A standard web server can verify thousands of hash chains in seconds. It does not require "Mining" or "Proof of Work."
-   **Security Level:** For a centralized system, it is mathematically "perfect." Unless an intruder has the power to overwrite the entire database and re-calculate thousands of complex SHA-256 hashes instantly, they cannot hide their tampering.

---

## Administrative Tools

### 1. Verification (`php artisan dts:verify-integrity`)
This command iterates through every document log, recalculates hashes from scratch, and reports the exact Log ID and Document Tracking Code where a mismatch is found.

### 2. Intruder Simulation (`php artisan dts:corrupt-log {logId}`)
Intentionally breaks a log's data without updating the hash to test the system's detection capabilities.

---

## Benefits & Disadvantages

### Benefits
-   **Immutability:** Provides absolute proof that a document's history hasn't been altered.
-   **Auditability:** Every action is linked to a user and a precise timestamp.
-   **Efficiency:** High security with almost zero performance impact.
-   **Blockchain-Grade Integrity:** Offers the same level of tamper-evidence as public blockchains without the prohibitive compute costs.

### Disadvantages
-   **Centralization:** The "Root of Trust" is the DepEd Division server. If the entire server environment is compromised, a sophisticated attacker could theoretically rebuild the chain.
-   **Complexity:** Requires developers to understand cryptographic principles to maintain the core logic.
