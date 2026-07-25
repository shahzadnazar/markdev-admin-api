<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Attendance — {{ $student->name }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1a1c22; padding: 26px 30px; }
        .head { border-bottom: 3px solid #0C5ABD; padding-bottom: 10px; margin-bottom: 12px; }
        .brand { font-size: 16px; font-weight: 700; color: #0C5ABD; }
        .brand small { color: #6b7280; font-weight: 400; font-size: 8px; letter-spacing: 1px; }
        .title { font-size: 13px; font-weight: 700; margin-top: 5px; }
        .meta { color: #6b7280; font-size: 8.5px; margin-top: 2px; }
        .chips { margin: 10px 0; }
        .chip { display: inline-block; border: 1px solid #e5e7eb; border-radius: 10px; padding: 4px 10px; margin-right: 6px; font-size: 8.5px; }
        .chip b { font-size: 10.5px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #F5F9FF; color: #374151; text-transform: uppercase; font-size: 7.5px; letter-spacing: 0.6px; text-align: left; padding: 6px; border-bottom: 1.5px solid #0C5ABD; }
        td { padding: 5.5px 6px; border-bottom: 1px solid #eef1f6; vertical-align: top; }
        .muted { color: #6b7280; }
        .status { font-weight: 700; text-transform: uppercase; font-size: 8.5px; }
        .present { color: #157f3c; } .late { color: #b45309; } .absent { color: #b91c1c; } .leave { color: #6B53C4; }
        .foot { margin-top: 12px; color: #9ca3af; font-size: 8px; border-top: 1px solid #e5e7eb; padding-top: 6px; }
    </style>
</head>
<body>
    <div class="head">
        <div class="brand">MARKDEV <small>LEARN · BUILD · GROW</small></div>
        <div class="title">Attendance Report — {{ $student->name }}</div>
        <div class="meta">
            {{ $student->studentProfile?->reg_no ? 'Reg # '.$student->studentProfile->reg_no.' · ' : '' }}{{ $student->email }}
            · Range: {{ $range === 'custom' ? (request('from') ?: '…').' to '.(request('to') ?: '…') : ['today' => 'today', 'yesterday' => 'yesterday', 'week' => 'this week', 'month' => 'this month', 'all' => 'all time'][$range] }}
            · {{ $statusFilter ? 'status '.$statusFilter : 'all statuses' }}
        </div>
    </div>

    <div class="chips">
        <span class="chip">Present <b class="present">{{ $summary['present'] }}</b></span>
        <span class="chip">Late <b class="late">{{ $summary['late'] }}</b></span>
        <span class="chip">Absent <b class="absent">{{ $summary['absent'] }}</b></span>
        <span class="chip">Leave <b class="leave">{{ $summary['leave'] }}</b></span>
        <span class="chip">Days tracked <b>{{ $summary['total'] }}</b></span>
        @if ($summary['rate'] !== null)
            <span class="chip">Attendance rate <b>{{ $summary['rate'] }}%</b></span>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 17%">Date</th>
                <th style="width: 10%">Status</th>
                <th style="width: 28%">Remarks</th>
                <th style="width: 22%">Marked</th>
                <th style="width: 23%">Correction</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $record)
                <tr>
                    <td><strong>{{ $record->date->format('D, M j, Y') }}</strong></td>
                    <td><span class="status {{ $record->status }}">{{ $record->status }}</span></td>
                    <td class="muted">{{ $record->remarks ?? '' }}</td>
                    <td class="muted">
                        {{ $record->arrived_at ? \Illuminate\Support\Carbon::parse($record->arrived_at)->format('g:i A').' · ' : '' }}{{ $record->marked_at->format('g:i A') }} ·
                        @if ($record->source === 'biometric')
                            Biometric
                        @else
                            {{ $record->marker?->name ?? 'System' }}{{ $record->marker?->roles?->first() ? ' ('.\Illuminate\Support\Str::headline($record->marker->roles->first()->name).')' : '' }}
                        @endif
                    </td>
                    <td class="muted">
                        @if ($record->last_updated_at)
                            {{ $record->last_update_reason }} ({{ $record->last_updated_at->format('M j, g:i A') }})
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No attendance records in this range.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="foot">
        Generated {{ now()->format('D, M j, Y · g:i A') }} by {{ $generatedBy }} · MarkDev LMS
    </div>
</body>
</html>
