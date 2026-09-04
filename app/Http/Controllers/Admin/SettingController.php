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
                'monthly_leave_allowance' => \App\Support\LeaveAllowance::perMonth(),
                'monthly_absent_allowance' => \App\Support\AbsenceFine::allowance(),
                'absent_fine_amount' => \App\Support\AbsenceFine::perAbsence(),
            ],
            // Lateness is judged per slot now; the two keys above are what a
            // student without one falls back to.
            'slots' => \App\Models\AttendanceSlot::ordered()->get(),
            'slotCount' => \App\Models\AttendanceSlot::count(),
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
            // At least one: zero would not be an allowance, it would be a ban,
            // and there is a toggle-shaped way to say that if it is ever wanted.
            'monthly_leave_allowance' => ['required', 'integer', 'min:1', 'max:31'],
            'monthly_absent_allowance' => ['required', 'integer', 'min:1', 'max:31'],
            // Zero is meaningful here, unlike the allowances: it is how an
            // academy says absences are tracked but never charged for.
            'absent_fine_amount' => ['required', 'numeric', 'min:0', 'max:100000'],
            'attendance_mode' => ['required', Rule::in(\App\Support\AttendanceConfig::MODES)],
        ], [
            'monthly_leave_allowance.min' => 'Monthly leave allowance must be at least 1.',
            'monthly_leave_allowance.required' => 'Monthly leave allowance must be at least 1.',
            'monthly_absent_allowance.min' => 'Monthly absent allowance must be at least 1.',
            'monthly_absent_allowance.required' => 'Monthly absent allowance must be at least 1.',
        ]);

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
