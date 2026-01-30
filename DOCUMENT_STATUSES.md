# Document Statuses Overview

This document outlines all possible statuses a document can have within the Document Tracking System (DTS), along with their meanings and transition rules. The status of a document dictates its current stage in the workflow and what actions can be performed on it.

---

## List of Document Statuses

1.  ### `pending`
    *   **Meaning:** The document has been submitted by a guest but has not yet been accepted or had its route finalized by a Records Officer. This is the initial state for new submissions.
    *   **Transition From:** Initial state after guest submission.
    *   **Transition To:** `in_transit` (after route finalization) or `declined` (if rejected).

2.  ### `declined`
    *   **Meaning:** The document was rejected by a Records Officer during the intake process, typically due to invalid or incomplete submission.
    *   **Transition From:** `pending`.
    *   **Transition To:** (Terminal state for this workflow path).

3.  ### `frozen`
    *   **Meaning:** An administrator has manually halted the document's progress. This status is typically used during integrity investigations or when a document's workflow needs to be paused.
    *   **Transition From:** Any active status (`in_transit`, `processing`, `ready_for_release`).
    *   **Transition To:** `processing` or `in_transit` (if unfrozen by an admin).

4.  ### `in_transit` **(New Status)**
    *   **Meaning:** The document is physically in the process of being moved between departments or from the Records Office to the first department, or back to the Records Office after all processing steps are complete. It is awaiting a QR code scan by the next responsible party to be formally "received".
    *   **Transition From:**
        *   `pending` (when a Records Officer accepts the document and finalizes its route).
        *   `processing` (when a department finishes its assigned step and sends the document onward).
    *   **Transition To:** `processing` (after a designated department scans and receives it) or `ready_for_release` (after the Records Officer scans a fully processed document).

5.  ### `processing`
    *   **Meaning:** The document has been physically received and its QR code scanned by the currently assigned department. Staff in this department are actively working on the document.
    *   **Transition From:** `in_transit` (after the designated department scans the QR code).
    *   **Transition To:** `in_transit` (after the department completes its step).

6.  ### `ready_for_release` **(New Status)**
    *   **Meaning:** All processing steps in the document's route have been completed, and the document has been physically returned to and scanned by the Records Officer. It is awaiting final review and handoff to the guest.
    *   **Transition From:** `in_transit` (after the Records Officer scans the QR code for a document that has completed all route steps).
    *   **Transition To:** `completed`.

7.  ### `completed`
    *   **Meaning:** The document has been successfully reviewed by the Records Officer and physically released to the guest. This is the final terminal state for a successfully processed document.
    *   **Transition From:** `ready_for_release`.
    *   **Transition To:** (Terminal state).
