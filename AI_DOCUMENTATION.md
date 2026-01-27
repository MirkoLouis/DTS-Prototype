# The "AI" Route Prediction System: How It Works

This document explains the mechanism behind the "AI" route prediction feature in the Document Tracking System. While not a large-scale neural network, it's a practical and effective implementation of core AI and Machine Learning concepts.

## 1. AI vs. Machine Learning: A Quick Primer

It's helpful to first understand the difference between these two terms:

*   **Artificial Intelligence (AI):** This is the broad science of making machines that can simulate human intelligence. It's a huge field that includes everything from simple logic puzzles to complex robotics. Think of AI as the entire category.
*   **Machine Learning (ML):** This is a specific *subset* of AI. The defining feature of ML is that the system **learns from data**. Instead of a programmer writing explicit rules for every possible situation, they create a framework that allows the system to improve its own rules and logic based on experience.

Our DTS uses a simple expert system (a type of AI) and enhances it with a feedback loop (a type of ML).

## 2. How Our System Predicts Routes

The system has two scenarios for suggesting a route when a new document is created:

1.  **Official Purposes:** For pre-defined purposes selected from the dropdown (e.g., "Request for Service Record"), the system uses a hardcoded `suggested_route` that is defined in the `database/seeders/PurposeSeeder.php` file.
2.  **"Other" Purposes:** When a guest types a custom purpose, the prediction engine is activated.

## 3. The "AI" Component: Dynamic Keyword Prediction

When a guest submits a document with a new, "Other" purpose, the `RoutePredictionService` performs the following steps:

1.  **Keyword Extraction:** It takes the purpose text (e.g., "Request for copy of my Form 137"), converts it to lowercase, and breaks it down into unique keywords: `['request', 'copy', 'form', '137']`.
2.  **Scoring:** It looks up each keyword in the `prediction_keywords` table. This table stores a set of JSON `weights` that link a keyword to various departments with a certain score.
3.  **Aggregation:** The service adds up the scores for each department based on the keywords found. For example:
    *   The keyword `request` might give `Records Unit` a score of +5.
    *   The keyword `form` might give `Records Unit` a score of +10 and `Schools Division Superintendent Office` a score of +3.
    *   The final scores would be `Records Unit: 15`, `Schools Division Superintendent Office: 3`.
4.  **Route Assembly:** The departments are then sorted by their final score in descending order to form the suggested route: `['Records Unit', 'Schools Division Superintendent Office']`.

## 4. The Machine Learning Component: Learning from Officers

This is where the system truly "learns". If the prediction in Step 3 isn't perfect, the Records Officer can manually change the route on the "Manage Document" page. When they click "Accept & Finalize Route", two things happen:

1.  **Immediate Update:** The system immediately updates the `suggested_route` for that specific, newly-created purpose. If another document with the exact same purpose text arrived, it would use this corrected route.
2.  **Background Learning:** The `UpdateKeywordWeights` job is dispatched. This job analyzes the keywords from the purpose text and the **correct, finalized route** provided by the officer. It then adjusts the weights in the `prediction_keywords` table.

#### Example:

*   **Initial State:** A guest writes "Request for Form 137". The system has a low weight for "137" and predicts the route `['Records Unit']`.
*   **Human Correction:** The Records Officer knows that Form 137 requires approval from the Superintendent's office and corrects the route to `['Records Unit', 'Schools Division Superintendent Office']`.
*   **Learning:** The `UpdateKeywordWeights` job runs. It sees that the keyword "137" was present in a document that was ultimately routed to `Schools Division Superintendent Office`. It **increases the weight** connecting the keyword "137" to the `Schools Division Superintendent Office`.
*   **Future State:** The next time a guest submits a document with the purpose "Certified copy of Form 137", the prediction engine will see the keyword "137", find its newly increased weight, and be much more likely to correctly predict the route `['Records Unit', 'Schools Division Superintendent Office']`.

## 5. Conclusion: So, is it "Real AI"?

**Yes.** It's a practical example of a **rule-based expert system** (a classic AI technique) enhanced with a **feedback-driven machine learning loop**. It doesn't use complex neural networks, but it perfectly fits the definition of a system that simulates intelligence and learns from experience to improve its performance over time.
