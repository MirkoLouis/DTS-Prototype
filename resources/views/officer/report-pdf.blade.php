<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Released Documents Report</title>
    <style>
        /* Define margins using padding on the body for better merging compatibility */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 9px;
            padding: 0.5in 0.5in 0.2in 0.5in; /* Top, Right, Bottom, Left */
            background-color: white;
        }

        .header {
            text-align: center;
            margin-bottom: 15px;
        }
        .header img {
            width: 50px;
            height: auto;
        }
        .header p {
            margin: 0;
            font-size: 11px;
        }
        .header .division {
            font-weight: bold;
            margin-top: 3px;
            font-size: 16px;
        }
        .title {
            text-align: center;
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        .charts-section {
            text-align: center;
        }
        .charts-section img {
            max-width: 100%;
            height: auto;
            border: 1px solid #eee;
        }
        .filters {
            margin-bottom: 15px;
            font-style: italic;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }
        
        .styled-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .styled-table th, .styled-table td {
            border: 1px solid #999;
            padding: 4px;
            vertical-align: top;
            word-wrap: break-word;
        }
        .styled-table th {
            background-color: #f0f0f0;
            font-weight: bold;
            text-align: left;
        }

        .col-tracking { width: 15%; }
        .col-title { width: 30%; }
        .col-purpose { width: 15%; }
        .col-district { width: 10%; }
        .col-submitter { width: 15%; }
        .col-date { width: 15%; }

        .fixed-height-cell {
            height: 24px; 
            line-height: 12px;
            overflow: hidden;
        }

        .page-break {
            page-break-after: always;
        }

        .footer {
            margin-top: 10px;
            text-align: right;
            font-size: 7px;
            color: #999;
        }
    </style>
</head>
<body>
    @if ($isFirstChunk)
        {{-- PAGE 1 --}}
        <div class="header">
            <img src="{{ public_path('images/logoipsum-411.png') }}" alt="DepEd Logo">
            <p>Republic of the Philippines</p>
            <p>Department of Education</p>
            <p>Region X - Northern Mindanao</p>
            <p class="division">SCHOOLS DIVISION OF ILIGAN CITY</p>
        </div>

        <h2 class="title">Released Documents Report</h2>

        <div style="margin-bottom: 10px;">
            <strong>Department:</strong> {{ $departmentName }}<br>
            <strong>Date Generated:</strong> {{ now()->format('F d, Y h:i A') }}
        </div>

        <div class="filters">
            <strong>Filters Applied:</strong>
            @php
                $allowedKeys = ['year', 'month', 'day', 'purpose_id', 'submitter', 'search'];
                $displayFilters = array_filter($filters, function($value, $key) use ($allowedKeys) {
                    return in_array($key, $allowedKeys) && !empty($value) && $value !== 'all';
                }, ARRAY_FILTER_USE_BOTH);
            @endphp
            @if(empty($displayFilters)) None @else
                @foreach($displayFilters as $key => $value)
                    <span>{{ ucfirst(str_replace('_', ' ', $key)) }}: <strong>
                        @if ($key === 'month') {{ date('F', mktime(0, 0, 0, $value, 10)) }}
                        @elseif ($key === 'purpose_id') {{ \App\Models\Purpose::find($value)?->name ?? $value }}
                        @else {{ $value }} @endif
                    </strong>;</span>
                @endforeach
            @endif
        </div>

        @if (isset($charts['throughput']))
            <div class="charts-section">
                <h3 style="margin-bottom: 15px; text-decoration: underline; font-size: 18px;">{{ $departmentName }} Performance Charts</h3>
                <h4 style="margin-bottom: 8px; font-size: 14px;">Documents Processed Over Time</h4>
                <img src="{{ $charts['throughput'] }}">
            </div>
            <div class="page-break"></div>
        @endif

        {{-- PAGE 2 (If charts exist) --}}
        @if (isset($charts['load']) || isset($charts['avg_time']))
            <div class="charts-section">
                @if (isset($charts['load']))
                    <div style="margin-bottom: 25px;">
                        <h4 style="margin-bottom: 8px; font-size: 14px;">Documents Received</h4>
                        <img src="{{ $charts['load'] }}" style="max-width: 90%;">
                    </div>
                @endif

                @if (isset($charts['avg_time']))
                    <div style="margin-bottom: 25px;">
                        <h4 style="margin-bottom: 8px; font-size: 14px;">Average Processing Time</h4>
                        <img src="{{ $charts['avg_time'] }}" style="max-width: 90%;">
                    </div>
                @endif
            </div>
            <div class="page-break"></div>
        @elseif (isset($charts['throughput']))
            {{-- We already had throughput and broke the page, but no other charts. --}}
            {{-- The next content (table) will start on the new page. --}}
        @endif
    @endif

    {{-- Data Table --}}
    <table class="styled-table">
        <thead>
            <tr>
                <th class="col-tracking">Tracking Code</th>
                <th class="col-title">Title</th>
                <th class="col-purpose">Purpose</th>
                <th class="col-district">District</th>
                <th class="col-submitter">Submitted By</th>
                <th class="col-date">Released At</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($releasedDocuments as $doc)
                <tr>
                    <td>{{ $doc->tracking_code }}</td>
                    <td>
                        <div class="fixed-height-cell">
                            {{ $doc->title }}
                        </div>
                    </td>
                    <td>{{ $doc->purpose->name }}</td>
                    <td>{{ $doc->district }}</td>
                    <td>{{ $doc->guest_info['name'] ?? 'N/A' }}</td>
                    <td>{{ $doc->updated_at->format('Y-m-d h:i A') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Document Tracking System - Batch processed at {{ now()->format('h:i:s A') }}
    </div>
</body>
</html>
