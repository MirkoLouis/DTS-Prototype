<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Document Tracking Form</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        html, body {
            width: 210mm;
            height: 297mm;
            font-family: 'Helvetica', 'Arial', sans-serif;
        }
        .form-container {
            width: 100%;
            height: 148.5mm;
            padding: 7mm;
            page-break-inside: avoid;
        }
        .cut-line {
            padding: 25px;
            border-bottom: 2px dashed #000;
        }
        .header {
            padding-top: 40px;
            text-align: center;
            margin-bottom: 8px;
        }
        .header img {
            width: 45px;
            height: auto;
        }
        .header p {
            margin: 0;
            font-size: 12px;
        }
        .header .division {
            font-weight: bold;
            margin-top: 3px;
            font-size: 18px;
        }
        .title {
            text-align: center;
            font-weight: bold;
            text-decoration: underline;
            font-size: 18px;
            margin-bottom: 20px;
        }
        .content-table {
            width: 100%;
            border-collapse: collapse;
        }
        .content-table td {
            padding: 4px 4px;
            font-size: 14px;
            vertical-align: top;
        }
        .content-table .label-col {
            font-weight: bold;
            width: 25%;
        }
        .content-table .value-col {
            width: 45%;
        }
        .content-table .qr-col {
            width: 30%;
            text-align: center;
            vertical-align: top;
        }
        .footer-cell {
            padding-top: 10px !important;
        }
        .footer-note {
            font-style: italic;
            font-size: 10px;
            text-align: center;
            width: 95%;
        }
    </style>
</head>
<body>
    @php
        $routeString = is_array($document->finalized_route) ? implode(' -> ', array_map(function($step) { return $step; }, (array) $document->finalized_route)) : 'N/A';
        $details = [
            'Tracking Number:' => e($document->tracking_code),
            'Date and Time Submitted:' => e($document->created_at->format("F d, Y h:i A")),
            'Document Title:' => e($document->title),
            'Purpose:' => e($document->purpose->name),
            'Submitted By:' => e($document->guest_info["name"] ?? "N/A (Guest)"),
            'Route:' => e($routeString),
        ];
        $rowCount = count($details);
        $trackingUrl = route('track', ['codes' => $document->tracking_code]);

        function renderFormContent($details, $rowCount, $qrCode, $trackingUrl) {
            echo '
            <div class="header">
                <img src="https://upload.wikimedia.org/wikipedia/commons/2/23/Seal_of_the_Department_of_Education_of_the_Philippines.png" alt="DepEd Logo">
                <p>Republic of the Philippines</p>
                <p>Department of Education</p>
                <p>Region X - Northern Mindanao</p>
                <p class="division">SCHOOLS DIVISION OF ILIGAN CITY</p>
            </div>
            <h2 class="title">Document Tracking Form</h2>
            
            <table class="content-table">
                <tbody>';
                    $firstRow = true;
                    foreach ($details as $label => $value) {
                        echo '<tr>';
                        echo '<td class="label-col">' . $label . '</td>';
                        echo '<td class="value-col">' . $value . '</td>';
                        if ($firstRow) {
                            echo '<td class="qr-col" rowspan="' . $rowCount . '">
                                      <img src="data:image/png;base64,' . $qrCode . '" style="width: 125px; height: 125px;">
                                  </td>';
                            $firstRow = false;
                        }
                        echo '</tr>';
                    }
            echo '</tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" class="footer-cell">
                            <p class="footer-note">
                                This is to acknowledge the receipt of your document(s) which has been submitted to the Document Tracking System and will be processed by the respective office.<br>Please use your tracking number or QR code for follow up in the tracking page.
                                <br>You can track your document here: <a href="' . $trackingUrl . '" target="_blank">' . $trackingUrl . '</a>
                            </p>
                        </td>
                    </tr>
                </tfoot>
            </table>';
        }
    @endphp

    <div class="form-container">
        @php renderFormContent($details, $rowCount, $qrCode, $trackingUrl); @endphp
        <div class=cut-line></div>
        @php renderFormContent($details, $rowCount, $qrCode, $trackingUrl); @endphp
    </div>
</body>
</html>
