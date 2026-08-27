<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
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
                'timezone' => $settings['timezone'] ?? config('app.timezone'),
                'maintenance_mode' => (bool) ($settings['maintenance_mode'] ?? false),
                'attendance_pin_set' => \App\Support\AttendanceConfig::hasEditPin(),
                'attendance_day_start' => \App\Support\AttendanceConfig::dayStart(),
                'attendance_late_after_minutes' => \App\Support\AttendanceConfig::lateAfterMinutes(),
            ],
            'backups' => $this->backups(),
            'timezones' => \DateTimeZone::listIdentifiers(),
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
            'timezone' => ['required', 'timezone:all'],
            'maintenance_mode' => ['nullable', 'boolean'],
            'attendance_edit_pin' => ['nullable', 'digits_between:4,8'],
            'attendance_day_start' => ['required', 'date_format:H:i'],
            'attendance_late_after_minutes' => ['required', 'integer', 'min:0', 'max:240'],
        ]);

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
