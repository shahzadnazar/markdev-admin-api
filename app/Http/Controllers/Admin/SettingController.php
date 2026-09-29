<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RecacheCourseProgress;
use App\Jobs\RecacheDeliveryScores;
use App\Models\AttendanceSlot;
use App\Models\Holiday;
use App\Models\Setting;
use App\Support\AbsenceFine;
use App\Support\AcademyCalendar;
use App\Support\AttendanceConfig;
use App\Support\AttendanceWeights;
use App\Support\AuditLogger;
use App\Support\DeliveryScore;
use App\Support\LeaveAllowance;
use App\Support\ProgressWeights;
use App\Support\QuizRules;
use App\Support\TeamAttendanceConfig;
use App\Support\TeamFineRules;
use App\Support\TeamLeaveAllowance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
                // The wording whoever is blocked actually reads. Shown as the
                // stored value or blank, NOT pre-filled with the default: a box
                // that already contains text reads as "somebody wrote this",
                // and the placeholder says what happens if it is left empty.
                'maintenance_message' => (string) ($settings['maintenance_message'] ?? ''),
                'attendance_pin_set' => AttendanceConfig::hasEditPin(),
                'attendance_day_start' => AttendanceConfig::dayStart(),
                'attendance_mode' => AttendanceConfig::mode(),
                'attendance_late_after_minutes' => AttendanceConfig::lateAfterMinutes(),
                'academy_working_days' => AcademyCalendar::workingDays(),
                'holiday_announce_days_before' => AcademyCalendar::announceDaysBefore(),
                'attendance_weights' => AttendanceWeights::all(),
                // Four components, each a checkbox and a percentage. The
                // checked ones must total 100; nothing redistributes on its own.
                'progress_weights' => ProgressWeights::all(),
                'monthly_leave_allowance' => LeaveAllowance::perMonth(),
                'monthly_absent_allowance' => AbsenceFine::allowance(),
                'absent_fine_amount' => AbsenceFine::perAbsence(),
                // Defaults for a NEW quiz. A quiz storing NULL follows these
                // and keeps following them; one storing a number has been
                // pinned on its own form.
                'quiz_default_attempts' => QuizRules::defaultAttempts(),
                'quiz_seconds_per_question' => QuizRules::defaultSecondsPerQuestion(),
                // The team portal's delivery score. Two dials and no more: a
                // third would be a third way for two academies to disagree
                // about what the number means.
                'delivery_minimum_stints' => DeliveryScore::minimumStints(),
                'delivery_early_mode' => DeliveryScore::earlyMode(),
                // The team portal's own attendance numbers. Every one of these
                // is team-specific: changing a student number must never move a
                // team number, and TeamSettingsIsolationTest asserts both ways.
                'team_office_start_time' => TeamAttendanceConfig::officeStart(),
                'team_late_after_minutes' => TeamAttendanceConfig::lateAfterMinutes(),
                'team_leave_allowance_per_month' => TeamLeaveAllowance::perMonth(),
                'team_absent_allowance_per_month' => TeamFineRules::allowance(),
                'team_absent_fine_amount' => TeamFineRules::perAbsence(),
            ],
            // Lateness is judged per slot now; the two keys above are what a
            // student without one falls back to.
            'slots' => AttendanceSlot::ordered()->get(),
            'slotCount' => AttendanceSlot::count(),
            'holidayCount' => Holiday::count(),
            'nextHoliday' => Holiday::where('date', '>=', today()->toDateString())
                ->orderBy('date')->first(),
            'activeSlotCount' => AttendanceSlot::active()->count(),
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
            // Optional: empty means MaintenanceMode::DEFAULT_MESSAGE, which is
            // at least true and says what to do. Capped because this is one
            // paragraph on a page with nothing else on it.
            'maintenance_message' => ['nullable', 'string', 'max:500'],
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
            'academy_working_days.*' => ['integer', Rule::in(array_keys(AttendanceSlot::DAYS))],
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
            'attendance_weight_absent' => ['required', 'integer', 'min:0', 'max:100'],
            // At least one: zero would not be an allowance, it would be a ban,
            // and there is a toggle-shaped way to say that if it is ever wanted.
            'monthly_leave_allowance' => ['required', 'integer', 'min:1', 'max:31'],
            'monthly_absent_allowance' => ['required', 'integer', 'min:1', 'max:31'],
            // Zero is meaningful here, unlike the allowances: it is how an
            // academy says absences are tracked but never charged for.
            'absent_fine_amount' => ['required', 'numeric', 'min:0', 'max:100000'],
            'attendance_mode' => ['required', Rule::in(AttendanceConfig::MODES)],
            // Course progress components. The percentage is required whether
            // or not the box is ticked, which is what lets an unchecked
            // component keep its number and get it back when re-checked.
            'progress_weight_attendance' => ['required', 'integer', 'min:0', 'max:100'],
            'progress_weight_quiz' => ['required', 'integer', 'min:0', 'max:100'],
            'progress_weight_assignment' => ['required', 'integer', 'min:0', 'max:100'],
            'progress_weight_premium' => ['required', 'integer', 'min:0', 'max:100'],
            'progress_enabled_attendance' => ['nullable', 'boolean'],
            'progress_enabled_quiz' => ['nullable', 'boolean'],
            'progress_enabled_assignment' => ['nullable', 'boolean'],
            'progress_enabled_premium' => ['nullable', 'boolean'],
            // One attempt is the academy default; more is a per-quiz decision.
            // Zero would not be an allowance, it would be a quiz nobody can
            // sit, and unpublishing is the way to say that.
            'quiz_default_attempts' => [
                'required', 'integer',
                'min:'.QuizRules::MIN_ATTEMPTS,
                'max:'.QuizRules::MAX_ATTEMPTS,
            ],
            // Seconds PER QUESTION, not per quiz: the total is this times the
            // questions the quiz has when the attempt starts. The floor stops
            // a typo creating a quiz that expires before it renders.
            'quiz_seconds_per_question' => [
                'required', 'integer',
                'min:'.QuizRules::MIN_SECONDS_PER_QUESTION,
                'max:'.QuizRules::MAX_SECONDS_PER_QUESTION,
            ],
            // How much finished work somebody needs before a percentage is
            // shown at all. At least one, because a score computed from
            // nothing is not a score; capped so a typo cannot hide every
            // figure in the portal for good.
            'delivery_minimum_stints' => ['required', 'integer', 'min:1', 'max:50'],
            'delivery_early_mode' => ['required', Rule::in(array_keys(DeliveryScore::EARLY_MODES))],
            // Office start, entered 12-hour with an AM/PM selector like every
            // other time here, and stored as the 24-hour string.
            'team_office_start_hour' => ['required', 'integer', 'min:1', 'max:12'],
            'team_office_start_minute' => ['required', 'integer', 'min:0', 'max:59'],
            'team_office_start_meridiem' => ['required', Rule::in(['AM', 'PM'])],
            'team_late_after_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            // At least one: zero would not be an allowance, it would be a ban,
            // and there are clearer ways to say that.
            'team_leave_allowance_per_month' => ['required', 'integer', 'min:1', 'max:31'],
            'team_absent_allowance_per_month' => ['required', 'integer', 'min:1', 'max:31'],
            // Zero IS meaningful here: it is how an academy says team absences
            // are tracked but never charged for.
            'team_absent_fine_amount' => ['required', 'numeric', 'min:0', 'max:100000'],
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
            'delivery_minimum_stints.min' => 'A delivery score needs at least 1 finished stint to be computed from.',
            'delivery_minimum_stints.required' => 'A delivery score needs at least 1 finished stint to be computed from.',
            'delivery_early_mode.required' => 'Say whether finishing early counts the same as on time or for more.',
            'delivery_early_mode.in' => 'Say whether finishing early counts the same as on time or for more.',
            'team_leave_allowance_per_month.min' => 'Team leave allowance must be at least 1.',
            'team_leave_allowance_per_month.required' => 'Team leave allowance must be at least 1.',
            'team_absent_allowance_per_month.min' => 'Team absent allowance must be at least 1.',
            'team_absent_allowance_per_month.required' => 'Team absent allowance must be at least 1.',
        ]);

        /*
         * The checked components have to total exactly 100.
         *
         * Validated here rather than in the rules array because it is a
         * question about the SET: which boxes are ticked decides which numbers
         * are added up, and no per-field rule can see both. The message names
         * the actual total, because "must total 100%" leaves the admin adding
         * up four boxes by hand to find the one that is wrong.
         */
        $checked = [];
        foreach (array_keys(ProgressWeights::DEFAULTS) as $component) {
            $data['progress_enabled_'.$component] = $request->boolean('progress_enabled_'.$component);

            if ($data['progress_enabled_'.$component]) {
                $checked[$component] = (int) $data['progress_weight_'.$component];
            }
        }

        if ($checked === []) {
            throw ValidationException::withMessages([
                'progress_enabled_premium' => 'Tick at least one progress component — progress has to be measured on something.',
            ]);
        }

        if (array_sum($checked) !== 100) {
            throw ValidationException::withMessages([
                'progress_weight_attendance' => sprintf(
                    'Checked components must total 100%% — they currently total %d%%.',
                    array_sum($checked),
                ),
            ]);
        }

        // Stored as sorted ISO-8601 numbers, the same shape as a slot's days,
        // so the two lists can be compared without translating between them.
        $data['academy_working_days'] = collect($data['academy_working_days'])
            ->map(fn ($day) => (int) $day)->unique()->sort()->values()->all();

        $data['attendance_day_start'] = Carbon::createFromFormat(
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

        // Folded into the 24-hour string the setting holds, exactly as the
        // academy day start is. The AM/PM wording is an input concern only.
        $data['team_office_start_time'] = Carbon::createFromFormat(
            'g:i A',
            sprintf('%d:%02d %s',
                $data['team_office_start_hour'],
                $data['team_office_start_minute'],
                $data['team_office_start_meridiem'],
            ),
        )->format('H:i');
        unset(
            $data['team_office_start_hour'],
            $data['team_office_start_minute'],
            $data['team_office_start_meridiem'],
        );

        $data['maintenance_mode'] = $request->boolean('maintenance_mode');
        // Trimmed to empty rather than stored as whitespace, so "the admin has
        // written nothing" is one value and not several.
        $data['maintenance_message'] = trim((string) ($data['maintenance_message'] ?? ''));

        // The PIN is stored hashed and only replaced when a new one is typed.
        if (! empty($data['attendance_edit_pin'])) {
            AttendanceConfig::setEditPin($data['attendance_edit_pin']);
            AuditLogger::log('updated', 'settings', null, null, ['key' => 'attendance_edit_pin']);
        }
        unset($data['attendance_edit_pin']);

        foreach ($data as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'group' => 'general']);
        }

        // Only one source may write to the register, so instructors have to be
        // told which way it is being filled today — otherwise they either mark a
        // register that rejects them, or leave one unmarked expecting devices.
        AttendanceConfig::setMode($data['attendance_mode'], $request->user());
        unset($data['attendance_mode']);

        // The layout reads these from cache on every render.
        Setting::forgetCached();

        /*
         * Every cached progress figure is now wrong.
         *
         * The live surfaces — a student's own Progress page, one student in the
         * admin — already reflect the new weights, because they compute on
         * read. The LIST screens read enrollments.progress_percent, which still
         * holds figures worked out with the old weights, so without this an
         * admin who moved attendance from 40 to 30 would see two different
         * numbers for the same student depending on which page they opened.
         *
         * Dispatched rather than run inline: on the sync driver this project
         * ships with it runs here, and on a real queue driver it goes to a
         * worker so a large academy's settings save does not block.
         */
        RecacheCourseProgress::dispatch();

        /*
         * And every cached delivery score, for the same reason.
         *
         * Raising the minimum from three stints to five hides some people's
         * percentages; changing whether early counts for more moves others.
         * A person's own page computes live and would show the new answer
         * immediately, so the list screens have to catch up or the two
         * surfaces disagree about the same person.
         */
        RecacheDeliveryScores::dispatch();

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

    /** @return array<int, array{name: string, size: int, date: Carbon}> */
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
                    'date' => Carbon::createFromTimestamp($disk->lastModified($file)),
                ])
                ->sortByDesc('date')
                ->values()
                ->all();
        }, [], false);
    }
}
