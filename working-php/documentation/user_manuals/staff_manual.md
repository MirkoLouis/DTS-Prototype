# DepEd Iligan DTS — Department Staff User Manual

**Target Audience:** Department Staff (`staff` role)  

---

## 1. Overview & Role Summary

As **Department Staff** (`staff`), you handle the domain-specific review, actioning, and endorsement of official documents routed to your specific division department (e.g., Accounting, Budget, Personnel, Legal, ODS). Your key responsibilities include receiving physical document folders delivered to your desk, executing internal actions, digitally signing completed tasks, and routing paperwork to the next department in line.

---

## 2. Role-Based Access Control (RBAC) Permissions

| Feature / Resource | Access Level | Description |
| :--- | :--- | :--- |
| **Department Tasks** (`/tasks`) | Primary Landing | View and process active documents currently routed to your department. |
| **Global QR Scanner** (`/documents/scan`) | Department-Scoped | Scan QR codes to log physical receipt of documents assigned to your department. |
| **Task Completion** (`/tasks/{id}/complete`) | Full Access | Enter Security PIN and advance document to next step. |
| **Completed Tasks History** (`/tasks/completed`) | Full Access | Review past tasks completed by your department. |
| **Department Analytics** (`/statistics`) | Read Access | View turnaround time (TAT) charts and request custom statistics exports. |
| **Document Visibility** | Department-Scoped | Restricted to documents whose finalized route includes your department. |
| **Intake / Releasing Portals** | No Access | Restricted to Records Officers (`officer` role). |
| **Admin & User Management** | No Access | Restricted to System Administrators (`admin` role). |

---

## 3. First-Time Login & Security Key Setup

Every task completion or receipt log generates a cryptographically binding **Ed25519 Digital Signature**. On your first login, you must set up your secret Security PIN.

### Step-by-Step Initialization:

1. Navigate to `/login` and sign in with your email and password.
2. You will be automatically directed to the **Security Key Setup** screen (`/security-key`).
3. Choose a secret **Security PIN** of at least 10 characters (letters, numbers and symbols are all allowed). The Security PIN is a second passcode that unlocks your signing key; you enter it to sign an action on a document. It must be different from your sign-in password. A Security PIN can only be replaced through an administrator reset.
4. Click **Set Security Key**.
5. The system generates your unique cryptographic keypair. Your private key is encrypted with your PIN and stored securely.

> [!IMPORTANT]
> Do not share your PIN with anyone. Every document action signed with your PIN is permanently linked to your identity in the immutable audit trail. Using the same passcode for sign-in and for signing is a security risk: anyone who learns one would have both.

### Changing Your Sign-In Password

Your sign-in password is part of your sign-in credentials; it proves who you are when you log in. A new account starts with the password `password`, and you get a notification with a **Change password** link each time you sign in until you change it. Open the user menu (your name, top right), click **Change password**, and enter your current password and the new one twice. The new password needs at least 15 characters, must not be a commonly used password, and must differ from your current password, from `password` and from your Security PIN. Five wrong current passwords in a minute lock the page for one minute, and a password can be changed at most five times per minute. Create your Security PIN first; the change-password form is refused until you have one. The reminder pops up when you sign in and then stays in the notification list. Nothing is forced, but please change it. When you change your password you are signed out everywhere, including this browser, and sign in again with the new password. If an administrator resets your password, you are signed out and sign in again with `password`.

---

## 4. Operational Workflows & Step-by-Step Guide

### 4.1 Viewing Assigned Tasks (`/tasks`)

```
[Physical Folder Delivered] ──> [Scan QR Code / View /tasks] ──> [Perform Action] ──> [Complete Task + PIN] ──> [Routed to Next Dept]
```

1. Navigate to **My Department Tasks** (`/tasks`).
2. The task table lists all pending documents currently waiting at your department's step in the routing chain.
3. Items display the tracking code, title, submitter info, current step index, and total elapsed time.

### 4.2 Receiving an Incoming Physical Folder (`/documents/scan`)

When a physical document folder is delivered to your department:

1. Click the **Scan QR Code** button in the header (or open the scanner modal).
2. Point your camera at the QR code on the document's tracking sheet.
3. The scanner identifies the document. Click **Receive Document**.
4. Enter your **Security PIN** and click confirm.
5. The system logs a `Received` entry ("Document received by [Department Name].") signed with your cryptographic key.

### 4.3 Actioning & Completing a Task (`/tasks/{id}/complete`)

After performing your required office work (e.g., verifying budget allocation, signing endorsement, encoding records):

1. On `/tasks`, locate the document and click **Complete Task** (or open document details at `/documents/{tracking_code}`).
2. Enter your **Security PIN** when prompted. After the first correct PIN, the system remembers it in an encrypted, session-bound form for the configured period, so later actions do not prompt again.
3. **What happens under the hood:**
   - The pre-action integrity check checks document integrity before proceeding.
   - The document row is updated with an optimistic version check inside a database transaction; the row lock it takes ensures only one of two simultaneous completions succeeds.
   - It records a signed log entry ("Step processed by [Department]. In transit to [Next Department]."), increments the document's `current_step` counter, and updates the active department pointer to the next office in the routing chain.

### 4.4 Reviewing Completed Tasks (`/tasks/completed`)

1. Navigate to `/tasks/completed`.
2. View historical documents previously processed and signed by your department, along with timestamps and total processing duration.

---

## 5. Troubleshooting & FAQs

- **Q: A document folder was delivered to me, but it doesn't show up on `/tasks`.**  
  *A:* Check the current routing step. The previous department may not have marked their task as complete yet in the DTS software. Scan the QR code to verify the current active department.
- **Q: I get "Action Denied: You lack permissions for this document".**  
  *A:* Staff members can only process documents currently positioned at their department's step index. If the document is currently assigned to another department, processing is restricted by system security policies.
- **Q: What should I do if I forget my Security PIN?**  
  *A:* Contact your System Administrator to request a Security PIN reset. They will reset your signature key while safely archiving your past signed logs, and you create a new PIN (at least 10 characters) at your next page view.
- **Q: What should I do if I forget my sign-in password?**  
  *A:* Contact your System Administrator to request a sign-in password reset. It is set back to `password`, and you are reminded to change it when you sign in.
