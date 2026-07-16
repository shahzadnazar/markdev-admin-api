<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssignmentSubmission;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $stats = [
            'students' => User::role('student')->count(),
            'instructors' => User::role('instructor')->count(),
            'courses' => Course::count(),
            'lessons' => Lesson::count(),
            'pending_submissions' => AssignmentSubmission::whereNull('graded_at')->count(),
            'attempts_today' => QuizAttempt::whereDate('started_at', today())->count(),
            'attendance_rate' => $this->attendanceRate(),
        ];

        // 14-day enrollments sparkline (inline SVG in the view).
        $days = collect(range(13, 0))->map(fn (int $back) => today()->subDays($back));
        $counts = Enrollment::where('enrolled_at', '>=', today()->subDays(13)->startOfDay())
            ->get(['enrolled_at'])
            ->groupBy(fn (Enrollment $e) => $e->enrolled_at?->toDateString())
            ->map->count();

        $sparkline = $days->map(fn ($day) => [
            'date' => $day->toDateString(),
            'label' => $day->format('M j'),
            'count' => (int) ($counts[$day->toDateString()] ?? 0),
        ]);

        return view('admin.dashboard.index', [
            'stats' => $stats,
            'sparkline' => $sparkline,
            'recentEnrollments' => Enrollment::with(['user', 'course'])->latest('enrolled_at')->take(5)->get(),
            'latestLogs' => AuditLog::latest('created_at')->take(8)->get(),
            'health' => $this->health(),
        ]);
    }

    protected function attendanceRate(): ?float
    {
        $monthly = AttendanceRecord::whereBetween('date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->selectRaw("count(*) as total, sum(case when status in ('present', 'late') then 1 else 0 end) as attended")
            ->first();

        if (! $monthly || (int) $monthly->total === 0) {
            return null;
        }

        return round(((int) $monthly->attended / (int) $monthly->total) * 100, 1);
    }

    /** @return array<string, mixed> */
    protected function health(): array
    {
        $dbOk = rescue(fn () => DB::connection()->getPdo() !== null, false, false);

        $free = rescue(fn () => disk_free_space(storage_path()), null, false) ?: null;
        $total = rescue(fn () => disk_total_space(storage_path()), null, false) ?: null;

        return [
            'db_ok' => (bool) $dbOk,
            'queue_pending' => rescue(fn () => DB::table('jobs')->count(), 0, false),
            'queue_failed' => rescue(fn () => DB::table('failed_jobs')->count(), 0, false),
            'disk_free' => $free,
            'disk_total' => $total,
            'disk_used_percent' => ($free && $total) ? round((($total - $free) / $total) * 100, 1) : null,
            'last_backup' => $this->lastBackupTime(),
        ];
    }

    protected function lastBackupTime(): ?\Illuminate\Support\Carbon
    {
        return rescue(function () {
            $disk = Storage::disk(config('backup.backup.destination.disks.0', 'local'));
            $directory = config('backup.backup.name', config('app.name'));

            $latest = collect($disk->files($directory))
                ->filter(fn (string $file) => str_ends_with($file, '.zip'))
                ->sortByDesc(fn (string $file) => $disk->lastModified($file))
                ->first();

            return $latest ? \Illuminate\Support\Carbon::createFromTimestamp($disk->lastModified($latest)) : null;
        }, null, false);
    }
}
