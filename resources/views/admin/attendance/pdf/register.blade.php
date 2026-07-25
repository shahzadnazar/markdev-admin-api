<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Daily attendance — {{ $date->toDateString() }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1a1c22; padding: 24px 28px; }
        .head { border-bottom: 3px solid #124389; padding-bottom: 10px; margin-bottom: 12px; }
        .brand { font-size: 16px; font-weight: 700; color: #124389; }
        .brand small { color: #6b7280; font-weight: 400; font-size: 8px; letter-spacing: 1px; }
        .title { font-size: 12px; font-weight: 700; margin-top: 4px; }
        .meta { color: #6b7280; font-size: 8px; margin-top: 2px; }
        .chips { margin: 10px 0; }
        .chip { display: inline-block; border: 1px solid #e5e7eb; border-radius: 10px; padding: 4px 10px; margin-right: 6px; font-size: 8px; }
        .chip b { font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #EEF4FB; color: #374151; text-transform: uppercase; font-size: 7px; letter-spacing: 0.6px; text-align: left; padding: 6px; border-bottom: 1.5px solid #124389; }
        td { padding: 5px 6px; border-bottom: 1px solid #eef1f6; vertical-align: top; }
        .muted { color: #6b7280; }
        .status { font-weight: 700; text-transform: uppercase; font-size: 8px; }
        .present { color: #157f3c; } .late { color: #b45309; } .absent { color: #b91c1c; } .leave { color: #6B53C4; } .unmarked { color: #9ca3af; }
        .foot { margin-top: 12px; color: #9ca3af; font-size: 7.5px; border-top: 1px solid #e5e7eb; padding-top: 6px; }
    </style>
</head>
<body>
    <div class="head">
        <div class="brand">MARKDEV <small>LEARN · BUILD · GROW</small></div>
        <div class="title">Daily Attendance Register — {{ $date->format('l, F j, Y') }}</div>
        <div class="meta">
            Filters:
            {{ $course ? 'course “'.$course->title.'”' : 'all courses' }}
            · {{ $statusFilter ? 'status '.$statusFilter : 'all statuses' }}
            {{ $search !== '' ? '· search “'.$search.'”' : '' }}
        </div>
    </div>

    <div class="chips">
        <span class="chip">Present <b class="present">{{ $counts['present'] }}</b></span>
        <span class="chip">Late <b class="late">{{ $counts['late'] }}</b></span>
        <span class="chip">Absent <b class="absent">{{ $counts['absent'] }}</b></span>
        <span class="chip">Leave <b class="leave">{{ $counts['leave'] }}</b></span>
        <span class="chip">Not marked <b class="unmarked">{{ $counts['unmarked'] }}</b></span>
        <span class="chip">Active students <b>{{ $counts['total'] }}</b></span>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 4%">#</th>
                <th style="width: 11%">Reg #</th>
                <th style="width: 20%">Student</th>
                <th style="width: 21%">Course</th>
                <th style="width: 9%">Status</th>
                <th style="width: 17%">Remarks</th>
                <th style="width: 7%">Time</th>
                <th style="width: 11%">Marked by</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($students as $index => $student)
                @php $record = $records->get($student->id); @endphp
                <tr>
                    <td class="muted">{{ $index + 1 }}</td>
                    <td class="muted">{{ $student->studentProfile?->reg_no ?? '—' }}</td>
                    <td><strong>{{ $student->name }}</strong></td>
                    <td class="muted">{{ $student->enrollments->map(fn ($enrollment) => $enrollment->course?->title)->filter()->implode(', ') ?: '—' }}</td>
                    <td>
                        @if ($record)
                            <span class="status {{ $record->status }}">{{ $record->status }}</span>
                            @if ($record->last_updated_at)
                                <span class="muted" style="font-size: 7px;">(corrected)</span>
                            @endif
                        @else
                            <span class="status unmarked">not marked</span>
                        @endif
                    </td>
                    <td class="muted">{{ $record?->remarks ?? '' }}</td>
                    <td class="muted">{{ $record?->arrived_at ? \Illuminate\Support\Carbon::parse($record->arrived_at)->format('g:i A') : ($record?->marked_at?->format('g:i A') ?? '') }}</td>
                    <td class="muted">
                        @if ($record)
                            @if ($record->source === 'biometric')
                                Biometric
                            @else
                                {{ $record->marker?->name ?? 'System' }}{{ $record->marker?->roles?->first() && \Illuminate\Support\Str::headline($record->marker->roles->first()->name) !== $record->marker?->name ? ' ('.\Illuminate\Support\Str::headline($record->marker->roles->first()->name).')' : '' }}
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="foot">
        Generated {{ now()->format('D, M j, Y · g:i A') }} by {{ $generatedBy }} · MarkDev LMS · {{ $students->count() }} student(s) listed
    </div>
</body>
</html>
