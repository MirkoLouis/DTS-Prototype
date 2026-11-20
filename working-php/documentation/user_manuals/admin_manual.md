# DepEd Iligan DTS — System Administrator User Manual

**Target Audience:** System Administrators (`admin` role)  

---

## 1. Overview & Role Summary

As a **System Administrator** (`admin`), you possess full control and global visibility over the DepEd Division of Iligan City Document Tracking System (DTS). Your primary responsibilities include user account management, cryptographically monitoring system integrity, executing data backups/restores, analyzing division-wide processing bottlenecks, and resolving automatically frozen document tamper alerts.

---

## 2. Role-Based Access Control (RBAC) Permissions

| Feature / Resource | Permission Level | Description |
| :--- | :--- | :--- |
| **Admin Dashboard** (`/admin-dashboard`) | Full Access | Live system throughput, decline trends, department load vs. step-time analytics. |
| **User Management** (`/users`) | Full Access | Create, edit, deactivate and reactivate users (accounts are never deleted, so their signed history stays verifiable), and reset a user's sign-in password, Security PIN or both (with your own Security PIN). |
| **System Overview & Health** (`/system-overview`) | Full Access | Run audit jobs, view DB performance metrics, clear personal/system caches, delete failed background jobs. |
| **Integrity & Tamper Control** (`/all-documents`, `/system-health/*`) | Full Access | View all document hash chains, freeze/unfreeze documents, execute snapshot-based Auto-Resolve. |
| **System Backups** (`/system/backups`) | Full Access | Generate manual database backups, restore database snapshots, download/delete backup archives. |
| **Global Document Visibility** (`/documents/{tracking_code}`) | Full Access | Unrestricted view and hash-chain audit access across all division documents. |
| **Department Task Execution** (`/intake`, `/tasks`) | View Only | Direct task processing is handled by Records Officers and Department Staff. |

---

## 3. First-Time Login & Security Key Setup

Every user action that mutates document state requires a cryptographically secure **Ed25519 Digital Signature**. On your initial login, you must initialize your personal signing key.

### Step-by-Step Initialization:

1. **Access Login Portal:** Navigate to `/login` using your web browser.
2. **Authenticate:** Enter your assigned admin email address and password.
3. **Security Key Setup Prompt:** If you have not set up your security key, the system automatically redirects you to the Security Key Setup screen (`/security-key`).
4. **Create Your Security PIN:**
   - The Security PIN is a second passcode that unlocks your signing key; you enter it to sign an action on a document.
   - Enter a PIN of at least 10 characters (letters, numbers and symbols are all allowed). It must be different from your sign-in password. Using the same passcode for sign-in and for signing is a security risk: anyone who learns one would have both. A Security PIN can only be replaced through an admin reset.
   - *Technical Note:* The system derives an encryption key from your PIN using Argon2id (libsodium) to encrypt your newly generated Ed25519 private key with AES-256-CBC before storing it in the database (`users.private_key`).
5. **Confirm Initialization:** Click **Set Security Key**. Once completed, your public key is published to `users.public_key` and logged in `user_public_key_histories`.

> [!IMPORTANT]
> Your Security PIN is never stored in plain text and cannot be recovered by the system. If you forget your PIN, another Administrator must reset it (**Reset**, then **Security PIN**) via the User Management panel.

### Changing Your Own Password

Your sign-in password is part of your sign-in credentials; it proves who you are when you log in. To change it, open the user menu (your name, top right) and click **Change password**. Enter your current password and the new one twice. The new password needs at least 15 characters, must not be a commonly used password, must differ from your current password and from `password`, and must not be your Security PIN. Five wrong current passwords in a minute lock this page for one minute, and a password can be changed at most five times per minute. Create your Security PIN first; the change-password form is refused until you have one.

If you are still using the default password, or an administrator set it, you get a notification with a **Change password** link each time you sign in; it pops up first and then stays in the notification list. Nothing is forced, but please change it. When you change your password you are signed out everywhere, including this browser, and sign in again with the new password.

---

## 4. Administrator Operations & Workflows

### 4.1 Managing User Accounts (`/users`)

1. **Creating New Accounts:**
   - Navigate to **Users Management** -> **Create User** (`/users/create`).
   - Fill in full name, email, role (`admin`, `officer`, or `staff`), and choose the **Department**.
   - Staff and Records Officer accounts need a department, because documents are routed to departments. A Records Officer must belong to the **Records Unit**. An Admin account has no department (choose "None (Admin)").
   - You do not type a password. The new account signs in with the default password `password` and is reminded to change it. Give the user their username and that password.
   - Click **Save User**. To move an account to another department later, use **Edit** on the Users page. Edit never changes a password.
2. **Resetting a Password or a Security PIN:**
   - If a user forgets their password, or loses their PIN or compromises their key, go to `/users`, find the user and click **Reset**.
   - Choose **Sign-in password** (set back to `password`; the user is reminded to change it), **Security PIN** (the user creates a new one at their next page view) or **Both**, and click the confirm button.
   - Enter your own Security PIN when asked. Wrong PINs count toward the one-minute lockout. Each reset is recorded in the system audit log. Resetting a sign-in password signs that user out of all their sessions (you too, if you reset your own); resetting only the Security PIN does not.
   - *Security Policy:* When a Security PIN is reset, the system archives the user's old public key into `user_public_key_histories` with an `activated_at` / `deactivated_at` timeframe. This ensures older signed documents remain cryptographically verifiable while invalidating the compromised key for future signatures.

### 4.2 System Health & Integrity Monitoring (`/system-overview`)

1. **Running a Full System Audit:**
   - Navigate to `/system-overview` and click **Run Integrity Check**.
   - An asynchronous queue job ([IntegrityCheckJob](file:///home/mirkolouis/Documents/DTS%20Prototype/working-php/src/Modules/Integrity/Jobs/IntegrityCheckJob.php)) evaluates every document's state hash against its immutable log chain (`document_logs`).
2. **Monitoring Real-Time DB Metrics:**
   - View active database connections, average query response times, and slow query logs fetched via [DatabasePerformanceService](file:///home/mirkolouis/Documents/DTS%20Prototype/working-php/src/Modules/System/DatabasePerformanceService.php).
   - Export performance metrics to CSV for auditing.
3. **Failed Jobs:**
   - A backup, restore, report or integrity check runs as a background job. A job that ends with an error is listed under **Failed Jobs** with its error text.
   - A running job reports that it is alive every 10 seconds. If its process dies (a crash, a server restart), it appears under **Failed Jobs** within about a minute with the note "stopped sending its health ping". It is not run again automatically; start it again yourself if it is still needed.
   - Delete entries once you have dealt with them.
4. **System audit log:**
   - Backup requests, downloads, restores (with document counts before and after) and deletions are written to `storage/logs/system-audit.log`, a file outside the database, because a restore replaces the tables a database record would live in.
   - Each line is linked to the one before it and protected with `APP_INTEGRITY_KEY`. **Run Integrity Check** also checks this file: "System audit log: intact", or the numbers of the lines that were changed, which also turns the overall status red.
   - Limit: lines cut off the end of the file are not detectable, because nothing after them records that they existed. Copy the file off the server together with the checkpoints.
5. **Seal results (entry seals and chain seals):**
   - Every log entry carries an **entry seal** and every document a **chain seal**, both made with `APP_INTEGRITY_KEY`. The chain seal covers what the state hash does not: the document's status, current step, current holder, decline reason, released-by user and release date (from 2.7.15), plus the newest log entry and the number of entries.
   - **Run Integrity Check** reports them next to the hash and signature results: **Invalid entry seals** and **Chain seal mismatches** (a number in brackets, for example "(4 unsealed)", counts entries or documents that have no seal yet). Both must be 0 for the overall status to turn green.
   - **"seal not configured"** means `APP_INTEGRITY_KEY` is missing or invalid in `.env`. Nothing was verified and the status is never green. Restore the key from your backup copy of `.env` (do not generate a new one: a new key cannot verify existing seals) and restart the worker. Without the key the worker does not start and document actions are refused with a message naming the key; no document is frozen because of it.
   - Documents and entries from before the seal existed (or restored from an old backup) show as "unsealed", not as failures, until the one-time sealing step has been run (README, "Chain Seal"). Actions on unsealed documents are refused until then.
   - **The review list:** the table under the status is the review list. A document whose chain seal is missing appears there as **Chain Seal Mismatch** once the database has been sealed (the first successful `seal-chains.php` run is recorded in the system audit log), because a seal that has disappeared is tampering. Before that first run, missing seals are shown only as "unsealed" counts and the documents are not listed or frozen. After restoring a backup taken before the chain seal on a sealed database, the restore notification gives the command to run (`--seal-missing-on-sealed-database --database=<name>`).
   - Seal failures are listed in the table under the status as **Invalid Entry Seal** (one row per entry) and **Chain Seal Mismatch** (one row per document). **Freeze All** also freezes the documents named by seal failures.

### 4.3 Handling Tampered Documents & Auto-Resolve

When raw database tampering is detected (e.g., unauthorized SQL updates bypassing application logic), the JIT **pre-action integrity check** instantly blocks processing and triggers **automatic freezing**.

```
[Database Tampering] ──> [Pre-Action Integrity Check Fails] ──> [Status: FROZEN] ──> [Admin Auto-Resolve]
```

1. **Identifying Frozen Documents:**
   - Frozen documents appear with a prominent warning badge on `/all-documents` and `/admin-dashboard`.
2. **Reviewing Hash Discrepancies:**
   - Open the document details page (`/documents/{tracking_code}`) and view the **Digital Hash Chain** (`/documents/{tracking_code}/hash-chain`).
   - Compare the current live SHA-256 state hash against the last logged `document_state_hash`.
3. **Executing Snapshot Auto-Resolve:**
   - On the document page, click **Auto-Resolve Document**.
   - The system retrieves the clean `document_snapshot` (JSON) stored inside the last valid `document_logs` record.
   - It overwrites the corrupted database row with the authentic snapshot data and unfreezes the document.
   - *Append-Only Rule:* The Auto-Resolve action logs a new entry (`ADMIN: Document Unfrozen & Restored`) signed by your Admin key, preserving full audit-trail transparency.
4. **When a seal fails (Chain Seal Mismatch or Invalid Entry Seal):**
   - A **Chain Seal Mismatch** means the document's status, step, holder, decline reason or released-by user, or the number or identity of its newest log entries, no longer matches what the system sealed. Someone changed the row outside the application, or an entry was deleted or added. The pre-action check freezes the document on the next action; the automatic freeze leaves the mismatch flagged on purpose.
   - An **Invalid Entry Seal** means a log entry was written or altered without the integrity key (an entry created by hand in the database has no valid seal, even if its hash and signature are correct).
   - Do not unfreeze until you know why. Check who had database access and compare the document with the paper trail. Then choose:
     - **Unfreeze** restores only status, step, holder, decline reason and released-by user, and works only while the document's content (title, submitter, district, department, purpose, route) still matches the last verified entry. If the content was changed it refuses with "The document's content was changed. Use Auto-resolve to restore it from the last verified entry." and changes nothing.
     - **Auto-Resolve** also puts the title, submitter, district, department, purpose and route back. It copies the stored snapshot back after proving it matches its state hash, block hash, signature and seal; nothing in the snapshot is decoded or interpreted.
     - Both use only the NEWEST regular log entry of the document and never an older one (an older entry would silently roll the document's progress back). If that entry fails any check (seal, block hash, link, signature, snapshot), the action is refused with a message that the document can only be fixed by restoring a backup, nothing is changed, and the refusal is recorded in the system audit log with the document and the entry number. Otherwise both write a new log entry and re-seal the document, which clears the chain-seal mismatch.
   - An entry with an invalid seal stays reported after recovery (the entry itself cannot be repaired); it documents that something was written outside the application.
   - **"Seals need upgrading: an administrator must run php scripts/seal-chains.php --upgrade-chain-seals"** is shown when a document's chain seal is still on the old version (from 2.7.15 the chain seal also covers the release date). It is not tampering and nothing is frozen. System Overview shows the count as "Chain seals on the old version". Run the upgrade once (README, "Upgrading chain seals from v1 to v2"): stop the app, back up, `php scripts/seal-chains.php --upgrade-chain-seals --database=<name>`, run the Integrity Check, restart. The upgrade runs once: afterwards the command upgrades nothing and a v1 seal is a mismatch (the document is frozen), because an old seal could otherwise be pasted back after editing the release date. A backup restore upgrades the restored data itself; its notification gives the counts. If the system audit log does not verify, actions on such documents are refused with "The system audit log does not verify; seals cannot be upgraded until it is restored" (nothing is frozen), and the command refuses to run.
   - **"This document has not been sealed yet"** is shown to staff when an action is attempted on a document that has not been sealed (after an upgrade before the sealing step, or after restoring an old backup). It is not tampering and nothing is frozen: run the Integrity Check, then `php scripts/seal-chains.php` (README, "Chain Seal"). The script verifies each chain first and lists any it refuses.
   - A person who has both the database and `APP_INTEGRITY_KEY` can recompute seals; only a checkpoint copied off the server (section 4.5) detects that.

### 4.4 Managing System Backups (`/system/backups`)

1. **Creating a Backup:** Click **Create Backup Now**. The background job [CreateBackupJob](file:///home/mirkolouis/Documents/DTS%20Prototype/working-php/src/Modules/System/Jobs/CreateBackupJob.php) generates a compressed SQL dump in `storage/app/backups/`. While it runs, the plain SQL file is kept in its own folder under `storage/temp/` and removed afterwards; if a job is killed, the next backup or restore clears what it left behind once no running job holds that folder.
2. **Restoring a Snapshot:** Select a backup file, click **Restore**, and enter your Security PIN. Before anything is replaced, the system saves the current database as a `…_pre-restore_…` backup, so a restore can itself be undone. When the restore has finished you get a notification with the number of documents before and after and the name of that safety backup.
3. **Downloading Archives:** Click **Download** and enter your Security PIN to save a backup file for off-site storage. Each download is recorded in the system audit log.
   - **Deleting** a backup also asks for your Security PIN. Five wrong PINs lock PIN entry for one minute.
   - **Encryption:** backups are encrypted with AES-256 under `BACKUP_PASSWORD` from `.env`. Open a downloaded backup with a tool that supports AES zip (7-Zip, for example) and that password. If the page says backups are stored unencrypted, set `BACKUP_PASSWORD`. Keep the password somewhere other than the server: without it a backup cannot be restored.
4. **What a backup contains:** every table except `jobs`, `cache`, `cache_locks` and `worker_schedule`. Those hold the running server's queue, cache and scheduler state, so a restore leaves them as they are. The file is named after the database it was taken from, for example `deped_dts_2026_11_14_093000_100001.sql.zip`.
5. **Expiry:** each new backup deletes backups older than `BACKUP_KEEP_DAYS` (set in `.env`, default 14 days). The newest backup is never deleted. Set it to `0` to keep every backup. Download any backup you want to keep longer.

### 4.5 Keeping the System Running (Deployment Requirements)

The DTS is designed to run without stopping. Three things must be in place on the server (setup commands: README, "Running Continuously on Linux"). The security settings to apply before the system holds real documents are in the README section "Vendor-Recommended Security Baselines".

1. **The queue worker runs as a service.** Install `deploy/dts-worker.service` so the worker starts at boot and restarts within 5 seconds after a crash. Without it, daily checkpoints, metrics and cleanup stop silently once the worker exits. Check it with `systemctl status dts-worker.service`.
2. **The operating system rotates logs and cleans PHP sessions.** nginx, PHP-FPM and the worker log grow without limit unless logrotate runs, and PHP session files are only deleted by the system's session cleaner. On a normal server these run from systemd timers (`logrotate.timer`, `phpsessionclean.timer`). In a container they need cron.
3. **Disk space is planned for what is never deleted.** Documents, their cryptographic logs, and the daily signed checkpoints in `storage/checkpoints/` are kept forever. A checkpoint is about 8.5 MB per day at 100,000 documents and grows with the number of documents. Backups older than `BACKUP_KEEP_DAYS` (default 14 days) are deleted when a new backup is made; the newest backup always stays.

**Copy checkpoints off the server regularly** (for example weekly, to USB or another machine). Only the newest checkpoint is used by the integrity check. The older copies stored elsewhere are what let you prove that no documents or log entries were deleted, even by someone with full server access.

---

## 5. Troubleshooting & Admin FAQs

- **Q: What happens if the background worker (`console.php`) stops?**  
  *A:* Queue items (report exports, integrity checks, backups) will remain in `queued` status. Restart the worker from terminal using `composer run worker` (or `php console.php`).
- **Q: A visitor or kiosk sees "Too Many Requests" (HTTP 429). Why?**  
  *A:* The public pages limit each connection (IP address) per minute: 10 document submissions, 10 tracking-form prints, 60 loads of the tracking page, 120 tracking-card loads and 240 status checks. The page says how many seconds to wait; the limit then resets by itself. Many kiosks behind one shared address count together. The limits apply while `RATE_LIMIT_ENABLED` in `.env` is `true` (the default); keep it on. On an existing database, run `scripts/migrations/master-migration.sql` once so the `rate_limits` table exists; until then the limits are off and a line is written to PHP's error log.
- **Q: After an update a button or feature does nothing and shows no error. What do I check?**  
  *A:* The browser enforces a Content Security Policy that blocks any script that is not a file of the system. A blocked script gives no message on the page. Open the browser console (F12, Console) and look for "Refused to execute ..." or "violates the following Content Security Policy directive"; send that line to the developers. Also stop and start `composer run prod` once after an update, so the server picks up the new web server settings.
- **Q: After an update, does the tracking slip (PDF) still open?**  
  *A:* Check it once: open a tracking slip from a document page and from the guest success page in the browsers your office uses. The PDF must display. If it shows a blank or blocked page, report the browser and its version to the developers.
- **Q: Why does clearing cache fail on certain pages?**  
  *A:* Caching uses Symfony Cache backed by database/filesystem storage. Click **Clear System Cache** on `/admin-dashboard` or `/clear-personal-cache` to invalidate stale cached analytics.
