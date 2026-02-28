# AI Route Prediction Logic

An in-depth technical explanation of the intelligent routing engine, detailing its keyword-based scoring system, background learning mechanism, and the transition from static to dynamic document flows.

## Table of Contents
1. [Expert Systems vs. Generative AI](#expert-systems-vs-generative-ai)
2. [Core Prediction Engine (How It Works)](#core-prediction-engine-how-it-works)
3. [From Static Seeders to Dynamic Memory](#from-static-seeders-to-dynamic-memory)
4. [The Learning Loop (Feedback Mechanism)](#the-learning-loop-feedback-mechanism)
5. [Official vs. "Other" Purposes](#official-vs-other-purposes)

---

## Expert Systems vs. Generative AI

Unlike the large-scale neural networks (LLMs) like GPT-4, our system is a **Hybrid Expert System**. It combines a rule-based logic framework with a data-driven feedback loop.

-   **Generative AI:** Predicts the next word in a sequence based on trillions of parameters. It is "broad" but can be unpredictable.
-   **Our Implementation:** Predicts a departmental sequence (route) based on weighted keyword correlations. It is "narrow," highly predictable, and can be fine-tuned with precision for specific administrative tasks.

---

## Core Prediction Engine (How It Works)

When a document with a custom ("Other") purpose is submitted, the `RoutePredictionService` executes a multi-stage scoring algorithm:

1.  **Keyword Extraction:** The purpose text is tokenized into lowercase, unique keywords (e.g., "Request for Form 137" -> `['request', 'form', '137']`).
2.  **Scoring & Weights:** Each keyword is looked up in the `prediction_keywords` table. Each entry contains a JSON `weights` object linking keywords to departments (e.g., `137` might have a weight of `+15` for the `Records Unit`).
3.  **Aggregation:** The engine sums the weights for every department across all extracted keywords.
4.  **Route Assembly:** Departments are ranked by their total score. The top-scoring departments are then assembled into a sequential `suggested_route`.

---

## From Static Seeders to Dynamic Memory

A common misconception is that "Official Purposes" remain hardcoded forever. In reality, the system transitions from static to dynamic logic:

-   **Initial State (Seeder):** When the system is first deployed, purposes (like "Request for Service Record") get their initial `suggested_route` from `database/seeders/PurposeSeeder.php`.
-   **The "Finalization" Handoff:** When a Records Officer manages a document, they can manually override the suggested route.
-   **State Transformation:** Once the officer clicks "Accept & Finalize", the system **overwrites** the `suggested_route` in the `purposes` table with the new, corrected route.
-   **Persistent Evolution:** The next document submitted with that same purpose—even an "Official" one—will now use the updated route stored in the database, effectively "evolving" the hardcoded seeder values into live, dynamic business logic.

---

## The Learning Loop (Feedback Mechanism)

The system "learns" through the `UpdateKeywordWeights` background job. This is the **Machine Learning** component:

1.  **Detection:** The system detects a discrepancy between the *predicted* route and the *finalized* route.
2.  **Dispatch:** If the purpose is not marked as "Official," the system dispatches `UpdateKeywordWeights` to analyze why the prediction failed.
3.  **Weight Adjustment:** The job identifies keywords present in the document and **increases their weights** for the departments that the Records Officer manually added.
4.  **Future Precision:** Over time, keyword-to-department correlations strengthen, allowing the system to handle varied phrasing (e.g., "Gimme my 137" vs. "Formal Request for Form 137") with increasing accuracy.

---

## Official vs. "Other" Purposes

-   **Official Purposes:** These are the "Standard Operating Procedures" (SOPs). While their routes are dynamic and update in the database when changed by an officer, they **do not** trigger the background keyword learning job. This prevents rare, one-off overrides of SOPs from skewing the global keyword weights.
-   **"Other" Purposes:** These are the "Unstructured Tasks." They are fully dynamic, updating both the individual purpose's route and the global keyword weights, allowing the system to adapt to new, recurring document types that aren't yet official SOPs.
