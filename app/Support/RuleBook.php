<?php

namespace App\Support;

use App\Models\AttendanceSlot;
use App\Models\Holiday;
use App\Models\RuleTemplate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Rules & Regulations page, assembled server-side.
 *
 * Every sentence is a stored template with {placeholders}; every placeholder
 * resolves to a value the system actually reads when it enforces that rule.
 * The portal receives finished text and a weight table and renders them — it
 * holds no default for any number and never builds a sentence from one, so an
 * admin changing a setting changes the page with nothing redeployed.
 *
 * The rule that governs what may be written here: a sentence only exists if
 * code enforces it. Where a setting is a default that something else can
 * override — a fee plan's own fine, a student's own registration fee — the
 * sentence says so rather than stating the default as the rule.
 *
 * Everything is read through Setting::cached, which loads the settings table
 * once per request and rescues a missing one. Deliberately not Cache::remember:
 * on a database cache store that put cache-table writes into a render path and
 * cost /admin/students/register a 30-second timeout.
 */
class RuleBook
{
    public const SECTIONS = [
        'attendance' => 'Attendance',
        'leave' => 'Leave',
        'absence' => 'Absence and fines',
        'fees' => 'Fees',
        'assignments' => 'Assignments',
        'percentage' => 'How attendance percentage is calculated',
        'contact' => 'Contact',
    ];

    /**
     * The rules that ship, in order.
     *
     * Each is one sentence the code behind it enforces; the comment on any
     * that needed care says where. An admin may reword these, but not add to
     * them: a rule with nothing behind it is what this page must never carry.
     *
     * @return array<int, array{key: string, section: string, body: string}>
     */
    public static function defaults(): array
    {
        return [
            // ---------------------------------------------------- attendance
            ['key' => 'attendance.slot', 'section' => 'attendance',
                'body' => 'Your attendance slot is {slot_name}, {slot_times} on {slot_days}.'],
            ['key' => 'attendance.no_slot', 'section' => 'attendance',
                'body' => 'You are not assigned to a slot, so the academy-wide day applies to you: it starts at {day_start} on {working_days}.'],
            // Per student, not the academy setting: AttendanceConfig::graceMinutesFor
            // takes the grace from the student's own slot and only falls back
            // to attendance_late_after_minutes when they have none.
            ['key' => 'attendance.late', 'section' => 'attendance',
                'body' => 'Arriving more than {late_after_minutes} minutes after {day_start} is marked late.'],
            ['key' => 'attendance.mode', 'section' => 'attendance',
                'body' => 'Attendance is currently recorded {attendance_mode_phrase}.'],
            ['key' => 'attendance.absent_final', 'section' => 'attendance',
                'body' => 'An absence is final once recorded. Only an admin can change it, and only with a written reason, which is kept on the record.'],
            ['key' => 'attendance.closed_days', 'section' => 'attendance',
                'body' => 'No attendance is taken on {non_working_days_phrase}, or on a public holiday, and those days are never counted against you.'],

            // --------------------------------------------------------- leave
            ['key' => 'leave.allowance', 'section' => 'leave',
                'body' => 'You may take up to {monthly_leave_allowance} leave days per month.'],
            ['key' => 'leave.no_carry', 'section' => 'leave',
                'body' => 'Unused leave expires at the end of the month. Nothing carries over — every month starts at {monthly_leave_allowance} again.'],
            // LeaveApplicationDay::COUNTED = [pending, approved].
            ['key' => 'leave.pending_reserves', 'section' => 'leave',
                'body' => 'A request holds its days against your allowance from the moment you apply, before anyone reviews it.'],
            // LeaveApplication::days() skips non-working days at apply time.
            ['key' => 'leave.non_working', 'section' => 'leave',
                'body' => 'Weekends and public holidays inside a leave request cost you nothing — only the days you would have attended are counted.'],
            // recordDecisions writes approved/declined per day; a declined day
            // has no special handling in the close and falls through to absent.
            ['key' => 'leave.partial', 'section' => 'leave',
                'body' => 'An instructor can approve some days of a request and decline others. A declined day is marked absent when that day closes.'],
            // review_note is required when any day is declined.
            ['key' => 'leave.reason', 'section' => 'leave',
                'body' => 'Whenever any day is declined you are told why — a reason is required before the decision can be saved.'],

            // ------------------------------------------------------- absence
            ['key' => 'absence.allowance', 'section' => 'absence',
                'body' => 'You may be absent {monthly_absent_allowance} days in a calendar month without a fine.'],
            ['key' => 'absence.fine', 'section' => 'absence',
                'body' => 'Each absence beyond that costs {absent_fine_amount}.'],
            ['key' => 'absence.charged', 'section' => 'absence',
                'body' => 'The month\'s absence fine is totalled at month end and added to your next invoice as its own line, separate from any late-payment fine.'],
            ['key' => 'absence.no_carry', 'section' => 'absence',
                'body' => 'Absences do not carry over. The count starts again at zero each month.'],
            // AbsenceFine::reconcile credits the difference onto the next
            // invoice when a day stops being a chargeable absence.
            ['key' => 'absence.corrections', 'section' => 'absence',
                'body' => 'If an absence is corrected after it was charged, the difference is credited back on your next invoice.'],

            // ---------------------------------------------------------- fees
            // BillingConfig::registrationFee is the default; a student can be
            // admitted on a different one, so this does not state it as final.
            ['key' => 'fees.registration', 'section' => 'fees',
                'body' => 'The registration fee is {registration_fee}, charged once at admission. Your own admission may have been agreed at a different amount — your invoice is what applies.'],
            ['key' => 'fees.activation', 'section' => 'fees',
                'body' => 'An installment becomes payable {billing_activation_days} days before its due date.'],
            ['key' => 'fees.grace', 'section' => 'fees',
                'body' => 'You have {billing_grace_days} days after the due date to pay before an installment is treated as overdue.'],
            // BillingConfig::finePerDay falls back to the setting but a fee
            // plan may carry its own, so this is stated as the default.
            ['key' => 'fees.late_fine', 'section' => 'fees',
                'body' => 'After that a late-payment fine of {defaulter_fine_per_day} per day accrues, unless your fee plan sets its own rate.'],

            // --------------------------------------------------- assignments
            ['key' => 'assignments.file', 'section' => 'assignments',
                'body' => 'Every submission needs a file attached. Text on its own will not submit.'],
            ['key' => 'assignments.query', 'section' => 'assignments',
                'body' => 'The Query box is for asking your instructor a question about the assignment. It is not where your work goes.'],
            // AssignmentController::submit refuses a resubmission once
            // graded_at is set and returned_at is not.
            ['key' => 'assignments.resubmit', 'section' => 'assignments',
                'body' => 'You can replace a submission until it is graded. After grading it is locked unless your instructor returns it for changes.'],

            // ---------------------------------------------------- percentage
            ['key' => 'percentage.weights', 'section' => 'percentage',
                'body' => 'Each day you are marked is worth a share of 100%, and your percentage is the average across those days.'],
            ['key' => 'percentage.excluded', 'section' => 'percentage',
                'body' => 'Days the academy is closed — {non_working_days_phrase}, plus public holidays — are not counted at all, neither for you nor against you.'],
            ['key' => 'percentage.example', 'section' => 'percentage',
                'body' => 'For example: {weighted_example}'],

            // ------------------------------------------------------- contact
            ['key' => 'contact.email', 'section' => 'contact',
                'body' => 'Email us at {support_email}.'],
            ['key' => 'contact.phone', 'section' => 'contact',
                'body' => 'Call us on {support_phone}.'],
        ];
    }

    /**
     * Finished values for every {placeholder}, for this student.
     *
     * Some are the same for everyone; the slot, its times and the late rule
     * are not, because the system judges each student against their own slot
     * and only falls back to the academy-wide day for those without one.
     *
     * @return array<string, string>
     */
    public static function context(?User $student = null): array
    {
        $slot = AttendanceConfig::slotFor($student);
        $currency = $student !== null ? AbsenceFine::currencyFor($student->id) : 'PKR';
        $days = $slot?->dayNumbers() ?? AcademyCalendar::workingDays();

        return [
            'slot_name' => $slot?->name ?? '—',
            'slot_times' => $slot?->rangeLabel() ?? '—',
            'slot_days' => $slot !== null ? $slot->daysLabel() : AcademyCalendar::workingDaysLabel(),
            'working_days' => AcademyCalendar::workingDaysLabel(),
            'non_working_days_phrase' => static::nonWorkingDaysPhrase($days),
            // 12-hour, like every time this system shows. A student on a slot
            // is judged against that slot's start, not the academy's.
            'day_start' => static::dayStartLabel($slot),
            'late_after_minutes' => (string) ($slot?->late_after_minutes ?? AttendanceConfig::lateAfterMinutes()),
            'attendance_mode' => AttendanceConfig::mode(),
            'attendance_mode_phrase' => AttendanceConfig::isBiometric()
                ? 'by biometric device — your fingerprint scan fills the register'
                : 'by your instructor, who marks the register',

            'monthly_leave_allowance' => (string) LeaveAllowance::perMonth(),
            'monthly_absent_allowance' => (string) AbsenceFine::allowance(),
            'absent_fine_amount' => static::money(AbsenceFine::perAbsence(), $currency),

            'registration_fee' => static::money(BillingConfig::registrationFee(), $currency),
            'defaulter_fine_per_day' => static::money(BillingConfig::finePerDay(), $currency),
            'billing_grace_days' => (string) BillingConfig::graceDays(),
            'billing_activation_days' => (string) BillingConfig::activationDays(),

            'weighted_example' => static::weightedExample(),

            'support_email' => (string) (Setting::cached('support_email') ?? ''),
            'support_phone' => (string) (Setting::cached('support_phone') ?? ''),
            'site_name' => (string) (Setting::cached('site_name') ?? config('app.name')),
        ];
    }

    /**
     * The whole page for one student.
     *
     * @return array<string, mixed>
     */
    public static function forStudent(?User $student = null): array
    {
        $context = static::context($student);
        $rules = static::rules();
        $slot = AttendanceConfig::slotFor($student);

        $sections = collect(self::SECTIONS)
            ->map(fn (string $title, string $key) => [
                'key' => $key,
                'title' => $title,
                'rules' => $rules->get($key, collect())
                    ->map(fn (RuleTemplate $rule) => [
                        'key' => $rule->key,
                        'text' => static::render($rule->body, $context),
                    ])
                    // A rule whose placeholders have nothing behind them for
                    // this student — no slot, no support phone — is left out
                    // rather than rendered with a blank in the middle.
                    ->reject(fn (array $rule) => static::isEmptyFor($rule['key'], $context, $slot))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->reject(fn (array $section) => $section['rules'] === [])
            ->values()
            ->all();

        return [
            'sections' => $sections,
            'weights' => static::weightTable(),
            'slot' => $slot === null ? null : [
                'name' => $slot->name,
                'starts_at' => $slot->startLabel(),
                'ends_at' => $slot->endLabel(),
                'days' => $slot->daysLabel(),
                'late_after_minutes' => $slot->late_after_minutes,
            ],
            'holidays' => static::upcomingHolidays(),
            'attendance_mode' => AttendanceConfig::mode(),
            'updated_at' => static::lastUpdatedAt()?->toISOString(),
        ];
    }

    /* ------------------------------- Pieces -------------------------------- */

    /** @return Collection<string, Collection<int, RuleTemplate>> */
    public static function rules(): Collection
    {
        return RuleTemplate::ordered()->get()->groupBy('section');
    }

    /** Fill {placeholders}; an unknown one is left visible rather than blanked. */
    public static function render(string $body, array $context): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z0-9_]+)\}/i',
            fn (array $match) => $context[$match[1]] ?? $match[0],
            $body,
        );
    }

    /**
     * The weight table, from the live settings.
     *
     * @return array<int, array{status: string, label: string, weight: int}>
     */
    public static function weightTable(): array
    {
        $labels = [
            'present' => 'Present',
            'late' => 'Late',
            'leave' => 'Approved leave',
            'excused' => 'Excused',
            'absent' => 'Absent',
        ];

        return collect(AttendanceWeights::all())
            ->map(fn (int $weight, string $status) => [
                'status' => $status,
                'label' => $labels[$status] ?? ucfirst($status),
                'weight' => $weight,
            ])
            ->values()
            ->all();
    }

    /**
     * A worked example computed from the live weights.
     *
     * Deliberately calculated rather than written down: an admin who changes
     * the late weight to 80 must see the example change with it, or the page
     * teaches a sum that no longer holds.
     */
    public static function weightedExample(): string
    {
        $present = 9;
        $late = 1;
        $percent = \App\Models\DailyAttendance::weightedPercent(['present' => $present, 'late' => $late]);

        return sprintf(
            '%d days present + %d day late over %d days = %s%%.',
            $present,
            $late,
            $present + $late,
            rtrim(rtrim(number_format((float) $percent, 1), '0'), '.'),
        );
    }

    /** Holidays from today on, soonest first. */
    public static function upcomingHolidays(int $limit = 8): array
    {
        return Holiday::query()
            // Half-open from the start of today: `date` is a date-cast column
            // and an equality — or a plain >= against a datetime — is the trap
            // that has bitten this codebase repeatedly.
            ->where('date', '>=', today()->toDateString())
            ->orderBy('date')
            ->limit($limit)
            ->get(['id', 'date', 'name'])
            ->map(fn (Holiday $holiday) => [
                'date' => $holiday->date->toDateString(),
                'label' => $holiday->date->format('l, j F Y'),
                'name' => $holiday->name,
            ])
            ->all();
    }

    /** When the rules themselves were last reworded. */
    public static function lastUpdatedAt(): ?Carbon
    {
        $latest = RuleTemplate::max('updated_at');

        return $latest === null ? null : Carbon::parse($latest);
    }

    /* ------------------------------- Helpers ------------------------------- */

    protected static function dayStartLabel(?AttendanceSlot $slot): string
    {
        if ($slot !== null) {
            return $slot->startLabel();
        }

        return Carbon::parse(AttendanceConfig::dayStart())->format('g:i A');
    }

    /** e.g. "Saturday and Sunday" — the days the given week leaves out. */
    protected static function nonWorkingDaysPhrase(array $workingDays): string
    {
        $off = array_values(array_diff(array_keys(AttendanceSlot::DAYS), $workingDays));

        if ($off === []) {
            return 'days the academy is closed';
        }

        $names = array_map(fn (int $day) => AttendanceSlot::DAYS[$day], $off);

        if (count($names) === 1) {
            return $names[0].'s';
        }

        $last = array_pop($names);

        return implode(', ', array_map(fn (string $name) => $name.'s', $names)).' and '.$last.'s';
    }

    /** Rules that would render with a hole in them for this student. */
    protected static function isEmptyFor(string $key, array $context, ?AttendanceSlot $slot): bool
    {
        return match ($key) {
            'attendance.slot' => $slot === null,
            'attendance.no_slot' => $slot !== null,
            'contact.email' => trim($context['support_email']) === '',
            'contact.phone' => trim($context['support_phone']) === '',
            default => false,
        };
    }

    /** Amounts carry the student's own billing currency, never an assumed one. */
    protected static function money(float $amount, string $currency): string
    {
        return $currency.' '.number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2);
    }
}
