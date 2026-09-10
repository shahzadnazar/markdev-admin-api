<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function edit(): View
    {
        $settings = Setting::where('group', 'general')->pluck('value', 'key');

        return view('admin.settings.edit', [
            'settings' => [
                'site_name' => $settings['site_name'] ?? config('app.name'),
                'support_email' => $settings['support_email'] ?? '',
                'support_phone' => $settings['support_phone'] ?? '',
                'registration_fee' => $settings['registration_fee'] ?? 2000,
                'defaulter_fine_per_day' => $settings['defaulter_fine_per_day'] ?? 100,
                'billing_grace_days' => $settings['billing_grace_days'] ?? 5,
                'billing_activation_days' => $settings['billing_activation_days'] ?? 5,
                'maintenance_mode' => (bool) ($settings['maintenance_mode'] ?? false),
                'attendance_pin_set' => \App\Support\AttendanceConfig::hasEditPin(),
                'attendance_day_start' => \App\Support\AttendanceConfig::dayStart(),
                'attendance_mode' => \App\Support\AttendanceConfig::mode(),
                'attendance_late_after_minutes' => \App\Support\AttendanceConfig::lateAfterMinutes(),
                'academy_working_days' => \App\Support\AcademyCalendar::workingDays(),
                'holiday_announce_days_before' => \App\Support\AcademyCalendar::announceDaysBefore(),
                'attendance_weights' => \App\Support\AttendanceWeights::all(),
                'monthly_leave_allowance' => \App\Support\LeaveAllowance::perMonth(),
                'monthly_absent_allowance' => \App\Support\AbsenceFine::allowance(),
                'absent_fine_amount' => \App\Support\AbsenceFine::perAbsence(),
                // Defaults for a NEW quiz. A quiz storing NULL follows these
                // and keeps following them; one storing a number has been
                // pinned on its own form.
                'quiz_default_attempts' => \App\Support\QuizRules::defaultAttempts(),
                'quiz_seconds_per_question' => \App\Support\QuizRules::defaultSecondsPerQuestion(),
            ],
            // Lateness is judged per slot now; the two keys above are what a
            // student without one falls back to.
            'slots' => \App\Models\AttendanceSlot::ordered()->get(),
            'slotCount' => \App\Models\AttendanceSlot::count(),
            'holidayCount' => \App\Models\Holiday::count(),
            'nextHoliday' => \App\Models\Holiday::where('date', '>=', today()->toDateString())
                ->orderBy('date')->first(),
            'activeSlotCount' => \App\Models\AttendanceSlot::active()->count(),
            'backups' => $this->backups(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:120'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:30'],
            'registration_fee' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'defaulter_fine_per_day' => ['required', 'numeric', 'min:0', 'max:100000'],
            'billing_grace_days' => ['required', 'integer', 'min:0', 'max:60'],
            'billing_activation_days' => ['required', 'integer', 'min:0', 'max:28'],
            'maintenance_mode' => ['nullable', 'boolean'],
            'attendance_edit_pin' => ['nullable', 'digits_between:4,8'],
            // Entered 12-hour with an AM/PM selector, like slot times; stored
            // as the same 24-hour H:i string this key has always held.
            'attendance_day_start_hour' => ['required', 'integer', 'min:1', 'max:12'],
            'attendance_day_start_minute' => ['required', 'integer', 'min:0', 'max:59'],
            'attendance_day_start_meridiem' => ['required', Rule::in(['AM', 'PM'])],
            'attendance_late_after_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            // The weekdays the academy opens, for students who are on no slot;
            // a slot answers for its own students. At least one, because an
            // academy that never opens marks nobody and bills nobody, and that
            // is a mistake to show rather than a state to store.
            'academy_working_days' => ['required', 'array', 'min:1'],
            'academy_working_days.*' => ['integer', Rule::in(array_keys(\App\Models\AttendanceSlot::DAYS))],
            // At least one day, because a notice that arrives on the morning of
            // the holiday is not notice. Capped so a typo cannot push every
            // notice a year out.
            'holiday_announce_days_before' => ['required', 'integer', 'min:1', 'max:30'],
            // What one marked day is worth, out of 100. Each is a share of a
            // percentage, so anything outside 0–100 is not a weight; the
            // defaults in DailyAttendance::WEIGHTS are what an unsaved or
            // unreachable setting falls back to.
            'attendance_weight_present' => ['required', 'integer', 'min:0', 'max:100'],
            'attendance_weight_late' => ['required', 'integer', 'min:0', 'max:100'],
            'attendance_weight_leave' => ['required', 'integer', 'min:0', 'max:100'],
            'attendance_weight_excused' => ['required', 'integer', 'min:0', 'max:100'],
            'attendance_weight_absent' => ['required', 'integer', 'min:0', 'max:100'],
            // At least one: zero would not be an allowance, it would be a ban,
            // and there is a toggle-shaped way to say that if it is ever wanted.
            'monthly_leave_allowance' => ['required', 'integer', 'min:1', 'max:31'],
            'monthly_absent_allowance' => ['required', 'integer', 'min:1', 'max:31'],
            // Zero is meaningful here, unlike the allowances: it is how an
            // academy says absences are tracked but never charged for.
            'absent_fine_amount' => ['required', 'numeric', 'min:0', 'max:100000'],
            'attendance_mode' => ['required', Rule::in(\App\Support\AttendanceConfig::MODES)],
            // One attempt is the academy default; more is a per-quiz decision.
            // Zero would not be an allowance, it would be a quiz nobody can
            // sit, and unpublishing is the way to say that.
            'quiz_default_attempts' => [
                'required', 'integer',
                'min:'.\App\Support\QuizRules::MIN_ATTEMPTS,
                'max:'.\App\Support\QuizRules::MAX_ATTEMPTS,
            ],
            // Seconds PER QUESTION, not per quiz: the total is this times the
            // questions the quiz has when the attempt starts. The floor stops
            // a typo creating a quiz that expires before it renders.
            'quiz_seconds_per_question' => [
                'required', 'integer',
                'min:'.\App\Support\QuizRules::MIN_SECONDS_PER_QUESTION,
                'max:'.\App\Support\QuizRules::MAX_SECONDS_PER_QUESTION,
            ],
        ], [
            'monthly_leave_allowance.min' => 'Monthly leave allowance must be at least 1.',
            'monthly_leave_allowance.required' => 'Monthly leave allowance must be at least 1.',
            'monthly_absent_allowance.min' => 'Monthly absent allowance must be at least 1.',
            'monthly_absent_allowance.required' => 'Monthly absent allowance must be at least 1.',
            'academy_working_days.required' => 'Pick at least one working day — the academy has to open sometime.',
            'academy_working_days.min' => 'Pick at least one working day — the academy has to open sometime.',
            'holiday_announce_days_before.min' => 'Holiday notice must go out at least 1 day before.',
            'holiday_announce_days_before.required' => 'Holiday notice must go out at least 1 day before.',
            'quiz_default_attempts.min' => 'A quiz has to allow at least 1 attempt.',
            'quiz_default_attempts.required' => 'A quiz has to allow at least 1 attempt.',
            'quiz_seconds_per_question.min' => 'Give a question at least 5 seconds.',
            'quiz_seconds_per_question.required' => 'Give a question at least 5 seconds.',
        ]);

        // Stored as sorted ISO-8601 numbers, the same shape as a slot's days,
        // so the two lists can be compared without translating between them.
        $data['academy_working_days'] = collect($data['academy_working_days'])
            ->map(fn ($day) => (int) $day)->unique()->sort()->values()->all();

        $data['attendance_day_start'] = \Illuminate\Support\Carbon::createFromFormat(
            'g:i A',
            sprintf('%d:%02d %s',
                $data['attendance_day_start_hour'],
                $data['attendance_day_start_minute'],
                $data['attendance_day_start_meridiem'],
            ),
        )->format('H:i');
        unset(
            $data['attendance_day_start_hour'],
            $data['attendance_day_start_minute'],
            $data['attendance_day_start_meridiem'],
        );

        $data['maintenance_mode'] = $request->boolean('maintenance_mode');


        // The PIN is stored hashed and only replaced when a new one is typed.
        if (! empty($data['attendance_edit_pin'])) {
            \App\Support\AttendanceConfig::setEditPin($data['attendance_edit_pin']);
            AuditLogger::log('updated', 'settings', null, null, ['key' => 'attendance_edit_pin']);
        }
        unset($data['attendance_edit_pin']);

        foreach ($data as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'group' => 'general']);
        }

        // Only one source may write to the register, so instructors have to be
        // told which way it is being filled today — otherwise they either mark a
        // register that rejects them, or leave one unmarked expecting devices.
        \App\Support\AttendanceConfig::setMode($data['attendance_mode'], $request->user());
        unset($data['attendance_mode']);

        // The layout reads these from cache on every render.
        Setting::forgetCached();

        return redirect()->route('admin.settings.edit')->with('success', 'Settings saved.');
    }

    public function runBackup(): RedirectResponse
    {
        try {
            Artisan::queue('backup:run', ['--only-db' => true]);

            AuditLogger::log('backup_queued', 'backups', null, null, ['command' => 'backup:run --only-db']);

            return back()->with('success', 'Backup queued — it will appear below once the queue worker processes it.');
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Could not queue the backup: '.$exception->getMessage());
        }
    }

    /** @return array<int, array{name: string, size: int, date: \Illuminate\Support\Carbon}> */
    protected function backups(): array
    {
        return rescue(function () {
            $disk = Storage::disk(config('backup.backup.destination.disks.0', 'local'));
            $directory = config('backup.backup.name', config('app.name'));

            return collect($disk->exists($directory) ? $disk->files($directory) : [])
                ->filter(fn (string $file) => str_ends_with($file, '.zip'))
                ->map(fn (string $file) => [
                    'name' => basename($file),
                    'size' => $disk->size($file),
                    'date' => \Illuminate\Support\Carbon::createFromTimestamp($disk->lastModified($file)),
                ])
                ->sortByDesc('date')
                ->values()
                ->all();
        }, [], false);
    }
}
