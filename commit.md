```
feat(documents): Implement soft decline for documents

This feature replaces the previous hard-delete functionality for declining documents with a "soft decline" system.

- **feat:** Added a modal for Records Officers to input a reason for declining a document. The `decline_reason` and `declined_at` are now stored in the database.
- **fix:** Prevents previously declined documents from being processed again by changing their status to `declined` instead of deleting them.
- **fix:** Corrected a logic error in the guest tracking view to ensure the decline reason is properly displayed to the user.
```
