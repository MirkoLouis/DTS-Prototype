# DTS Security Architecture & Cryptographic Workflows

This document outlines the core security concepts, mechanisms, and proposed features for the Document Tracking System (DTS) Prototype, focusing specifically on how it defends against advanced data tampering.

---

## 1. The Core Engine: `DocumentWorkflowService`
The `DocumentWorkflowService` is the centralized database worker for all state mutations within the DTS. Instead of scattering database writes across multiple controllers, all critical actions (Submit, Intake, Scan, Process, Release) are routed here.

**Key Responsibilities:**
- **Pessimistic Locking:** Uses explicit `SELECT ... FOR UPDATE` SQL commands to lock database rows during transactions. This prevents race conditions when multiple users attempt to process the same document simultaneously.
- **Workflow State Management:** Handles the business logic of moving a document from one department to the next based on its `finalized_route`.
- **Integrity Integration:** Calls the `IntegrityManager` to generate new cryptographic logs (`document_logs`) after every successful transaction, linking the action to the user's signature.

## 2. The "Active Guard" (Just-In-Time Verification)
The system employs a Just-In-Time (JIT) verification strategy to balance high performance with strict security.

- **Passive Reads (Fast):** When users simply view a document's details on the dashboard, the system bypasses complex cryptographic verification to keep page load times near-instant.
- **Active Guard (Strict):** The moment a user attempts to mutate the state of the document (e.g., scanning or processing it), the action routes through `DocumentPolicy.php`. The Active Guard intercepts the request and calculates a fresh SHA-256 hash of the live document metadata.
- **The Trap (String Equality, No Decoding):** Hashes are strictly one-way and mathematically impossible to decode. The Active Guard doesn't try to decode anything. Instead, it takes the current metadata from the live `documents` table, hashes it, and queries the database for the last log entry (`SELECT document_state_hash FROM document_logs...`). It then simply compares the two string values (`$currentHash === $lastLog['document_state_hash']`). If the strings don't match exactly, it knows the live data was altered, instantly denies the action, and triggers an **Auto-Freeze**.

## 3. Threat Modeling: System Attack vs. Database Attack
Security in the DTS is evaluated on two distinct fronts:

> [!CAUTION]
> **System-Level Attack (UI/API)**
> An attacker tries to manipulate data through the web interface or API endpoints. This is easily caught by standard Laravel/PHP validation, authentication middleware, and role-based access control (RBAC).

> [!WARNING]
> **Database-Level Attack (God Mode)**
> An attacker (e.g., a rogue Database Administrator) bypasses the web application entirely and runs raw SQL queries directly on the live database. This is what `scripts/corrupt.php` simulates. Because the attacker operates "beneath" the application's logic, traditional framework security is useless. The system must rely on cryptography to detect and reject these changes.

## 4. Hashing vs. Digital Signatures (Non-Repudiation)
To defend against the Database Attack, the DTS utilizes two layers of cryptography:

### Layer 1: SHA-256 Hashing (The State)
A hash is a **one-way function**. It takes the document's metadata and scrambles it into a fixed string. If the DB Admin changes even a single letter in the live database, the resulting hash completely changes, triggering the Active Guard. 

*Vulnerability:* If the DB Admin knows the hashing algorithm, they can calculate the new hash themselves and forge the log entry too.

### Layer 2: Ed25519 Asymmetric Encryption (The Signature)
To prevent the DB Admin from forging the logs, the system requires an Ed25519 Digital Signature for every action.
- Every staff member has a Private Key stored in the database, **encrypted with their personal secret PIN**.
- The DB Admin can see the encrypted key, but without the PIN, they cannot decrypt it.
- Without the decrypted key, it is mathematically impossible for the DB Admin to forge a valid signature for their fake log entry.

> [!IMPORTANT]
> **Information Security Concept: Non-Repudiation**
> This ensures an action is undeniably tied to the specific user who authorized it. Neither the user nor a highly privileged system admin can deny or forge it.

## 5. The Future Upgrade: Auto-resolve
Currently, if a document is tampered with and Auto-Frozen, an Administrator must manually investigate and fix the database using SQL so the hash matches again.

**The Solution:**
Because a SHA-256 hash is a one-way function, the system only knows *that* the data changed, not *what* the original data was. To enable an **Auto-resolve** feature:
1. **Document Snapshots:** We add a `document_snapshot` (JSON) column to the `document_logs` table. Every time an action is logged, a copy of the clean metadata is saved. These snapshots are extremely lightweight (usually less than 1KB), containing only raw text fields and route arrays, preventing database bloat.
2. **Auto-resolve UI:** When the Active Guard detects tampering and freezes the document, the Admin dashboard provides an "Auto-resolve" button.
3. **Restoration:** The system pulls the clean `document_snapshot` from the last valid log, overwrites the corrupted live database record, and seamlessly unfreezes the document.

> [!NOTE]
> **What if the attacker tampers with the snapshot too?**
> If a rogue DB Admin alters the `document_snapshot` along with the live metadata and calculates the new hashes to match, **they will still completely fail at the Ed25519 layer**. Modifying the snapshot changes the `document_state_hash`, which breaks the Ed25519 digital signature. Because they lack the user's decrypted Private Key, they cannot mathematically generate a valid signature for their tampered snapshot, and the system will reject it as forged.

### The Immutable Ledger Rule
Crucially, when Auto-resolve occurs, **we do not delete the "System Auto-Freeze" log**. Blockchain and audit-trail architecture dictates an **Append-Only** rule. Deleting logs allows cover-ups. 

Instead, the Auto-resolve action is recorded as a brand new log (`ADMIN: Document Unfrozen & Restored`). This permanently proves that an attack occurred, the security caught it, and the system successfully recovered from it.
