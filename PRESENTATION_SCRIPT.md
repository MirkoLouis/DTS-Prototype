# DepEd DTS Prototype: Presentation Script & Workflow

---

## Introduction (5 minutes)

### Main Points:
-   **Welcome & Project Goal:** Introduce the Document Tracking System (DTS) prototype. State its primary goal: to modernize, secure, and streamline the document submission and tracking process for the DepEd Division of Iligan City.
-   **The Problem:** Briefly touch upon the challenges of a manual, paper-based system: lost documents, lack of transparency, difficulty in tracking status, and process bottlenecks.
-   **The Solution:** This prototype is a robust, web-based solution that provides end-to-end visibility and accountability.
-   **Thesis Innovations:** Mention that the system is built around three core concepts: **Security**, **Artificial Intelligence**, and **Human-Computer Interaction (HCI)**, which we will demonstrate.

> **SPEAKER NOTES:**
>
> *(Start with a welcome and brief intro of yourself.)*
>
> "Good morning, everyone. Today, we're excited to present a functional prototype for a modern Document Tracking System, or DTS, designed specifically for the DepEd Division of Iligan City."
>
> "We all know the challenges of manual, paper-based systems. It's difficult for parents and external stakeholders to know the status of their documents, and it's equally challenging for internal staff to manage the flow efficiently. Our goal with this project was to solve these problems by creating a system that is efficient, transparent, and secure."
>
> "To achieve this, we built the prototype on three key pillars, which you'll see throughout this presentation: first, a **Security** model inspired by blockchain to ensure data integrity; second, a simple **AI** to assist staff with routing documents; and third, a focus on **Human-Computer Interaction** to make the system easy and intuitive for everyone to use."

---

## Part 1: The Guest Experience (10 minutes)

### Main Points:
-   **Scenario:** A parent needs to submit documents for an application.
-   **Live Demo Walkthrough:**
    1.  **Homepage:** Show the main submission form. It's clean, simple, and the first thing a user sees. Point out the new 'Track a Document' button, which opens a modal for quick lookups directly from the homepage.
    2.  **Dynamic Requirements:** Select a purpose from the dropdown (e.g., "Application for Leave"). Point out how the required documents list appears instantly. This is our first example of good HCI—no guesswork for the user.
    3.  **"Other" Purpose:** Select "Other" and type in a custom purpose (e.g., "Request for a Good Moral Certificate"). Explain that this will be routed using our AI model.
    4.  **Submission:** Fill out the form and submit.
    5.  **Success Page:** Highlight the two key takeaways for the user: the unique **Tracking Code** and the scannable **QR Code**. This is their digital receipt.
    6.  **Tracking Portal:** Click the "Track Your Document" button. Show the tracking page with the document's status card and the "subway map" visualization, which clearly shows the document's journey.
    7.  **Multi-Tracking:** Use the "Track Another Document" modal to add another, existing tracking code manually.
    8.  **QR Code Tracking:** Open the modal again and demonstrate using the "Scan QR Code" button to add a third document, simulating a user with a printed form or a screenshot on their phone.

> **SPEAKER NOTES:**
>
> "Let's start from the perspective of a parent or any external client. They land on our homepage, which is the main submission portal."
>
> *(Show the welcome page)*
>
> "The process is straightforward. When the user selects a purpose, the system immediately tells them what documents are required. No more confusion or missing paperwork. Now, what if the purpose isn't on the list? We have an 'Other' option. Let's try submitting a 'Request for a Good Moral Certificate'."
>
> *(Submit the form)*
>
> "Upon submission, the user is given a unique tracking code and a QR code. This is their key. They no longer need to call the office for follow-ups; they can track the progress themselves, 24/7."
>
> *(Go to the tracking page)*
>
> "This is the public tracking portal. Each document has a card with its details and this 'subway map' that gives a clear, visual representation of where the document is and where it's going next. Users can track multiple documents at once. Let's add another one manually... and now, let's add a third one using the QR code scanner, just as if they were using their phone."
>
> *(Demonstrate both manual and QR code tracking)*

---

## Part 2: The Records Officer's Workflow (10 minutes)

### Main Points:
-   **Scenario:** The Records Officer is the gatekeeper and manager of the document flow.
-   **Live Demo Walkthrough:**
    1.  **Login & Dashboard:** Log in as the Records Officer (`records@dts.com`). Show the `/intake` dashboard.
    2.  **Advanced Filtering:** Point out the "Recently Handled Documents" table. Demonstrate the new, powerful filtering options.
        -   Filter by **Status** (e.g., "Show me only 'processing' documents").
        -   Filter by **Purpose**.
        -   Filter by **Submitter**.
        -   Filter by **Date Handled**.
        -   Show the **"Clear"** button to reset the view.
    3.  **Document Lookup:** Use the lookup form to find the "Good Moral Certificate" document we just submitted. Demonstrate both manual typing and using the officer's QR scanner.
    4.  **Route Management:** On the "Manage Route" page, explain the AI-suggested route.
        -   Demonstrate the **drag-and-drop** interface to re-order a step.
        -   Show how to **add** a new step and **delete** one.
    5.  **Finalization:** Click "Accept & Finalize Route". Explain that this action logs the document into the system officially and creates the first link in our secure hash chain.
    6.  **Releasing:** Briefly show the `/releasing` page, explaining this is the final checkpoint where officers release completed documents to the client.

> **SPEAKER NOTES:**
>
> "Now, let's switch roles and log in as the Records Officer. This is the control center for all incoming documents."
>
> *(Log in and show the intake page)*
>
> "The first thing they see is a list of documents they've recently handled. We've built a powerful filtering system here, so an officer can easily find documents based on their status, purpose, who submitted them, or even the exact date they were handled. This makes finding information incredibly fast."
>
> *(Demonstrate the filters)*
>
> "Let's find the document we just submitted. We can type in the code, or, just like the guest, use a QR scanner for instant lookup."
>
> *(Show the manage route page)*
>
> "Because this was a custom purpose, our AI has suggested a route. But the Records Officer has full control. They can easily drag and drop to re-order the steps, add a new office, or remove one. This combination of AI suggestion and human oversight is key."
>
> *(Finalize the route)*
>
> "By clicking 'Accept & Finalize', the document is now officially in the system and on its way. This is also a critical moment where our security model kicks in, which I'll explain later. The final step for the Records Officer, after the document has been processed by all departments, is the 'Releasing' page, where they mark the document as completed and ready for pickup."

---

## Part 3: The Department Staff's Role (5 minutes)

### Main Points:
-   **Scenario:** An employee in the Accounting department processing their part of the workflow.
-   **Live Demo Walkthrough:**
    1.  **Login:** Log in as a department staff member (e.g., `accounting@dts.com`).
    2.  **Task-Focused Dashboard:** Show the `/tasks` dashboard. Emphasize that the view is clean and simple—they **only** see documents that are currently waiting for *their* department's action. No clutter.
    3.  **Completing a Step:** Find the document in the queue and click "Complete Step".
    4.  **Automatic Handoff:** Explain that after clicking, the document disappears from their queue and automatically appears in the queue of the next department in the route.

> **SPEAKER NOTES:**
>
> "What about the individual departments? Let's log in as someone from Accounting."
>
> *(Log in and show the tasks page)*
>
> "The staff dashboard is designed to be extremely focused. You don't see all the documents in the system, only the ones that require your attention right now. It's a simple, actionable to-do list."
>
> "When the accounting staff is done with their part, they simply click 'Complete Step'. The document is automatically logged and sent to the next office in the route. It's a seamless digital handoff."

---

## Part 4: The Admin's View (5 minutes)

### Main Points:
-   **Scenario:** A school administrator or Division Head needs a high-level overview of the system's performance.
-   **Live Demo Walkthrough:**
    1.  **Login:** Log in as the Admin (`admin@dts.com`).
    2.  **Process Analytics:** Show the main Admin Dashboard.
        -   **Bottleneck Detector:** Explain the "Current Load" chart, which shows how many documents are pending at each department. This helps identify bottlenecks in real-time.
        -   **Throughput Chart:** Show the "Throughput" chart, explaining it tracks overall productivity.
    3.  **System Utilities:** Navigate to the System Health page.
        -   **The "Trust Builder":** This is the heart of our security innovation. Explain that you will now run a live integrity check of the entire document history.
        -   Click **"Run Verification"**. Show the "100% Verified" result. Explain that this cryptographically proves that no document's history has been tampered with since its creation.

> **SPEAKER NOTES:**
>
> "Finally, let's look at the system from an administrator's perspective. They need to see the big picture."
>
> *(Log in as Admin and show the admin dashboard)*
>
> "The Admin Dashboard provides key analytics. The 'Bottleneck Detector' shows if a particular department is getting overwhelmed with documents, allowing management to address issues proactively. The 'Throughput' chart tracks overall system efficiency over time."
>
> "But the most important feature here is the 'System Health' monitor. We call it the 'Trust Builder'. At any time, an admin can run a complete integrity verification of the database."
>
> *(Run the verification)*
>
> "This check just confirmed cryptographically that every single log for every document is secure and has not been altered. This is how we can guarantee the integrity and trustworthiness of the system's data."

---

## Part 5: Under the Hood: The Code (5 minutes)

### Main Points:
-   **Transition:** Shift from the "what" to the "how".
-   **1. Security (Hash-Chaining):**
    -   Briefly show the `DocumentLog` model file.
    -   Point to the `boot()` method and explain that before any log is created, it takes the hash of the *previous* log and includes it when creating the new hash.
    -   **Analogy:** "It's like a digital fingerprint. Each log is dependent on the one before it, creating an unbreakable chain. If a single log is changed, the entire chain after it breaks, which is what the 'Trust Builder' tool detects."
-   **2. AI (Route Prediction & Learning):**
    -   Show the `GuestController` and the line `Others: ` . $request->input('other_purpose_text'); to demonstrate the data normalization.
    -   Explain the `RoutePredictionService` tokenizes this input and queries the `prediction_keywords` table for weighted keywords.
    -   Explain the `UpdateKeywordWeights` job. "The system learns. If an officer corrects a route, the system analyzes the document's purpose and increases the weight of those keywords for the chosen departments, making it smarter over time."
-   **3. HCI (Interactive Interfaces):**
    -   Recap the features already shown: QR code scanners on both guest and staff pages, the drag-and-drop editor, and AJAX-powered tables that provide a smooth, app-like experience without constant page reloads.
    -   Mention the use of local NPM packages for all frontend libraries (like `html5-qrcode` and `Bootstrap`) for a fully standalone, production-ready application.

> **SPEAKER NOTES:**
>
> "So, how does this all work? Let's take a quick look at the code behind our three core innovations."
>
> "First, **Security**. In our `DocumentLog` model, we have a function that automatically creates a chained hash. Before saving any new action, it pulls the hash from the previous log and incorporates it into the new one. This creates a chain of cryptographic evidence, making the history immutable."
>
> "Second, **AI**. When you typed in 'Request for a Good Moral Certificate', the system saved it with the prefix 'Others:'. Our `RoutePredictionService` then broke that phrase into keywords and matched them against a database of terms associated with different departments, each with a different weight. The system learns, too. When an officer manually corrects a route, a background job analyzes their choice and adjusts the keyword weights to make better predictions in the future."
>
> "Finally, **HCI**. You saw this in action everywhere. From the QR scanners that make lookup effortless, to the drag-and-drop interface, to the smooth, responsive tables that filter and update without a single page refresh. We've ensured all dependencies are bundled locally, making the application fast and self-contained."

---

## Conclusion (2 minutes)

### Main Points:
-   **Summary of Benefits:** This DTS prototype directly translates to:
    -   **Efficiency:** Automates manual processes and reduces processing time.
    -   **Transparency:** Provides real-time status updates for everyone.
    -   **Accountability:** Creates a clear, auditable trail for every document.
    -   **Security:** Guarantees the integrity of the document history.
-   **Call to Action:** This prototype serves as a strong foundation for a full-scale implementation.
-   **Q&A:** Open the floor for questions.

> **SPEAKER NOTES:**
>
> "In conclusion, this Document Tracking System prototype offers a massive leap forward in terms of efficiency, transparency, and security. It empowers guests with real-time information, equips staff with focused, powerful tools, and gives administrators the oversight they need to ensure the system is running smoothly and with full integrity."
>
> "It is a solid foundation that is ready to be expanded and deployed. Thank you for your time. I'd now be happy to answer any questions you may have."