<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Test - Documents</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f3f4f6;
            margin: 0;
            padding: 2rem;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background-color: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1f2937;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 0.5rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        th, td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            background-color: #f9fafb;
            color: #4b5563;
            font-weight: 600;
        }
        tr:hover {
            background-color: #f9fafb;
        }
        .status {
            padding: 4px 8px;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 500;
            text-transform: capitalize;
        }
        .status.pending { background-color: #fef3c7; color: #92400e; }
        .status.completed { background-color: #d1fae5; color: #065f46; }
        .status.processing { background-color: #dbeafe; color: #1e40af; }
    </style>
</head>
<body>

<div class="container">
    <h1>Database Connection Test: Documents</h1>
    <p>If you can see this list, it means PDO successfully connected to your MySQL database and fetched the records!</p>

    <?php if (empty($documents)): ?>
        <p style="color: #6b7280; font-style: italic;">No documents found in the database. (Or the table is empty!)</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Tracking Code</th>
                    <th>Title</th>
                    <th>Status</th>
                    <th>Created At</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($documents as $doc): ?>
                    <tr>
                        <td><?= htmlspecialchars($doc['id']) ?></td>
                        <td><strong><?= htmlspecialchars($doc['tracking_code']) ?></strong></td>
                        <td><?= htmlspecialchars($doc['title'] ?? 'N/A') ?></td>
                        <td>
                            <span class="status <?= htmlspecialchars($doc['status']) ?>">
                                <?= htmlspecialchars($doc['status']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($doc['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

</body>
</html>
