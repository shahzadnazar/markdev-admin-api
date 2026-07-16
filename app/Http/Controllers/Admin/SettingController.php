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
                'timezone' => $settings['timezone'] ?? config('app.timezone'),
                'maintenance_mode' => (bool) ($settings['maintenance_mode'] ?? false),
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
            'timezone' => ['required', 'timezone:all'],
            'maintenance_mode' => ['nullable', 'boolean'],
        ]);

        $data['maintenance_mode'] = $request->boolean('maintenance_mode');

        foreach ($data as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'group' => 'general']);
        }

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
