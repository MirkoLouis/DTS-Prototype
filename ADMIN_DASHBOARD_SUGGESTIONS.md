# Admin Dashboard: Future Analytics & Monitoring Suggestions

This document contains suggestions for additional data visualizations and monitoring categories for the administrator dashboard to provide a more comprehensive overview of the system's performance and health.

---

## Client & Service Analytics

This category focuses on the public-facing side of the service and understanding client behavior.

1.  **Most Frequent Purposes**
    *   **What it shows:** A simple bar chart of the most submitted document purposes.
    *   **Why it's valuable:** This shows which services are in highest demand, helping to justify resource allocation for those specific workflows.

2.  **Submission Trends by District**
    *   **What it shows:** A bar chart or table showing the volume of submissions originating from different school districts.
    *   **Why it's valuable:** This could help identify areas with high demand for services and could inform outreach or support initiatives.

3.  **Client Satisfaction Deep-Dive**
    *   **What it shows:** While there is a separate ratings page, the admin dashboard could feature a "Recent Ratings" feed or a chart showing the trend of the average rating over time.
    *   **Why it's valuable:** Provides a quick pulse check on client satisfaction without having to navigate to a separate page.

4.  **Peak Submission Hours/Days**
    *   **What it shows:** A bar chart or heatmap indicating which days of the week or hours of the day have the highest submission volumes.
    *   **Why it's valuable:** This helps in resource planning and staffing at the Records Unit to better handle peak intake periods.

---

## AI & System Performance

This category focuses on the technical and "smart" aspects of the system.

1.  **AI Route Prediction Accuracy**
    *   **What it shows:** A line chart tracking the accuracy of the AI's route predictions over time. This would be calculated by comparing how many suggested routes were accepted versus how many were manually changed by the Records Officer.
    *   **Why it's valuable:** This is a direct measure of the "learning" system's effectiveness and provides powerful, quantifiable proof of the AI's value. A declining accuracy could signal a need to retrain or adjust the learning algorithm.

2.  **Orphaned Documents Monitor**
    *   **What it shows:** A simple counter or list of documents that have been in a `processing` or `in_transit` state for an unusually long time (e.g., > 10 business days) without any log updates.
    *   **Why it's valuable:** This acts as a proactive alert system to find documents that might be "stuck" or forgotten, allowing an admin to investigate and get them moving again. This directly improves service delivery and prevents client frustration.

3.  **User Adoption & Activity Rate**
    *   **What it shows:** A chart displaying the number of active internal users (staff, officers) per day or week and the volume of tasks they complete.
    *   **Why it's valuable:** Measures how well the system is being adopted by internal personnel. A low adoption rate or a high number of logins with few actions completed might indicate a need for more training or improvements to the user interface.

4.  **Database Growth Rate**
    *   **What it shows:** A simple line chart showing the growth in the number of `documents` and `document_logs` over time.
    *   **Why it's valuable:** This helps with capacity planning, forecasting storage needs, and understanding the rate of data accumulation.
