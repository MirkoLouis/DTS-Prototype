# Document Log Hashing Mechanism

## 1. Introduction and Purpose

This document details the hash-chaining mechanism implemented within the Document Tracking System (DTS). The primary purpose of this system is to guarantee the **integrity** and **auditability** of a document's history.

By creating a cryptographically-linked chain of every action performed on a document, we can mathematically prove that the log history has not been tampered with after the fact. This serves as a strong deterrent against unauthorized data modification and provides a robust foundation for administrative audits.

## 2. The Hashing Mechanism

The system employs a `sha256`-based hash chain, where each `DocumentLog` entry is linked to the one preceding it.

### 2.1. The Hash Chain

- Each record in the `document_logs` table contains a `hash` field and a `previous_hash` field.
- The `previous_hash` of a given log entry is the `hash` of the immediately preceding log entry for that same document.
- This creates an unbroken chain of cryptographic hashes stretching back to the document's first-ever log entry.

### 2.2. The Genesis Hash

- The very first log entry for any document has no preceding log.
- In this case, its `previous_hash` field is set to the string literal `"genesis_hash"`.
- This "genesis hash" acts as the anchor or the first link from which the entire chain is built.

### 2.3. Hash Calculation

The `hash` for each log entry is a `sha256` digest of a concatenated string of the log's most critical data points:

```
hash = sha256(document_id + user_id + action + timestamp + previous_hash)
```

- **`document_id`**: The ID of the document the log belongs to.
- **`user_id`**: The ID of the user who performed the action.
- **`action`**: A string representing the action taken (e.g., "Submitted", "Processing Complete").
- **`timestamp`**: The creation timestamp of the log entry, formatted to the ISO-8601 standard with microseconds (`YYYY-MM-DDTHH:MM:SS.mmmmmmZ`). This precision is critical for ensuring hash uniqueness.
- **`previous_hash`**: The hash of the prior log entry.

Including the `previous_hash` in the data for the *current* hash is what creates the immutable chain. If a previous hash changes, all subsequent hashes are invalidated.

## 3. Implementation Details

### 3.1. Automatic Hashing

- The hashing logic is implemented within the `boot()` method of the `App\Models\DocumentLog` model.
- It uses the `creating` Eloquent model event to automatically calculate and set the `previous_hash` and `hash` fields every time a new `DocumentLog` is created.
- This ensures that hashing is an integral part of the model's lifecycle and cannot be accidentally bypassed by application logic.

### 3.2. Integrity Verification

- The system includes a dedicated Artisan command, `php artisan dts:verify-integrity`, to audit the hash chain.
- This command iterates through every document's logs, recalculates the hash of each entry based on its stored data, and verifies two conditions:
    1. The recalculated hash matches the entry's stored `hash`.
    2. The entry's `previous_hash` matches the stored `hash` of the preceding entry.
- Any mismatch will be reported as an integrity failure, immediately flagging the specific log entry that has been tampered with.

### 3.3. Testing and Simulation

- A second Artisan command, `php artisan dts:corrupt-log {logId}`, exists for testing and demonstration purposes.
- This command intentionally alters a log entry's data without recalculating its hash, allowing administrators to simulate a data corruption event and confirm that the `dts:verify-integrity` command successfully detects it.

## 4. Security Analysis

### 4.1. Strengths

- **Tamper Detection**: It is computationally infeasible to alter a log entry without breaking the hash chain. Any modification is easily and definitively detectable.
- **Auditability**: Provides a chronologically secure and verifiable trail of every action, creating a high degree of accountability.
- **Deterrence**: The presence of this system discourages attempts at unauthorized data modification, as such attempts are guaranteed to be discovered.

### 4.2. Limitations

- **Centralization**: Unlike a decentralized blockchain, this system's data resides in a central database. A malicious actor with sufficient database privileges could, in theory, modify a log entry and then expend the significant effort required to recalculate all subsequent hashes to conceal the tampering.
- **Reliance on Database Security**: The hash chain is a layer of *integrity verification*, not a replacement for fundamental database security. Its effectiveness is predicated on a secure database environment with strong access controls and logging.
