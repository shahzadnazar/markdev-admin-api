<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use App\Models\BiometricPunch;
use App\Models\Course;
use App\Services\BiometricAttendanceService;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BiometricController extends Controller
{
    /* -------------------------------- Devices ------------------------------ */

    public function devices(Request $request): View
    {
        $devices = BiometricDevice::query()
            ->with('course:id,title')
            ->withCount([
                'punches',
                'punches as unmatched_punches_count' => fn ($query) => $query->where('status', BiometricPunch::STATUS_UNMATCHED),
            ])
            ->when($request->filled('search'), function ($query) use ($request) {
                $like = '%'.trim($request->string('search')).'%';
                $query->where(fn ($inner) => $inner->where('name', 'like', $like)
                    ->orWhere('serial_number', 'like', $like)
                    ->orWhere('location', 'like', $like));
            })
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return view('admin.biometric.devices', [
            'devices' => $devices,
            'courses' => Course::orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function storeDevice(Request $request): RedirectResponse
    {
        $data = $this->deviceData($request);
        $data['api_key'] = BiometricDevice::generateKey();

        $device = BiometricDevice::create($data);

        return redirect()
            ->route('admin.biometric.devices')
            ->with('success', "Device \"{$device->name}\" registered.")
            ->with('device_key', ['name' => $device->name, 'key' => $device->api_key]);
    }

    public function updateDevice(Request $request, BiometricDevice $device): RedirectResponse
    {
        $device->update($this->deviceData($request, $device));

        return redirect()->route('admin.biometric.devices')->with('success', "Device \"{$device->name}\" updated.");
    }

    public function regenerateKey(BiometricDevice $device): RedirectResponse
    {
        $device->update(['api_key' => BiometricDevice::generateKey()]);

        AuditLogger::log('key_regenerated', 'biometric_devices', $device->id);

        return redirect()
            ->route('admin.biometric.devices')
            ->with('success', "New key issued for \"{$device->name}\" — the old key stopped working.")
            ->with('device_key', ['name' => $device->name, 'key' => $device->api_key]);
    }

    public function reprocess(BiometricDevice $device, BiometricAttendanceService $service): RedirectResponse
    {
        $count = $service->reprocessUnmatched($device);

        return back()->with('success', "Reprocessed unmatched punches — {$count} attendance record(s) created.");
    }

    public function destroyDevice(BiometricDevice $device): RedirectResponse
    {
        $name = $device->name;
        $device->delete();

        return redirect()->route('admin.biometric.devices')->with('success', "Device \"{$name}\" removed.");
    }

    /* -------------------------------- Punches ------------------------------ */

    public function punches(Request $request): View
    {
        $punches = BiometricPunch::query()
            ->with(['device:id,name', 'user:id,name'])
            ->when($request->filled('device'), fn ($query) => $query->where('biometric_device_id', $request->integer('device')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('from'), fn ($query) => $query->where('punched_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($query) => $query->where('punched_at', '<=', $request->date('to')->endOfDay()))
            ->orderByDesc('punched_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.biometric.punches', [
            'punches' => $punches,
            'devices' => BiometricDevice::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** CSV fallback for devices without network push: biometric_id,punched_at[,direction]. */
    public function import(Request $request, BiometricAttendanceService $service): RedirectResponse
    {
        $request->validate([
            'device_id' => ['required', Rule::exists('biometric_devices', 'id')],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $device = BiometricDevice::findOrFail($request->integer('device_id'));

        $handle = fopen($request->file('file')->getRealPath(), 'rb');
        $results = ['processed' => 0, 'unmatched' => 0, 'skipped' => 0, 'duplicate' => 0, 'invalid' => 0];
        $row = 0;

        while (($line = fgetcsv($handle)) !== false) {
            $row++;

            // Tolerate a header row.
            if ($row === 1 && ! strtotime((string) ($line[1] ?? ''))) {
                continue;
            }

            $biometricId = trim((string) ($line[0] ?? ''));
            $punchedAt = trim((string) ($line[1] ?? ''));

            if ($biometricId === '' || ! strtotime($punchedAt)) {
                $results['invalid']++;

                continue;
            }

            $punch = $service->ingest($device, [
                'biometric_id' => $biometricId,
                'punched_at' => Carbon::parse($punchedAt)->toDateTimeString(),
                'direction' => isset($line[2]) ? trim((string) $line[2]) : null,
            ]);

            if (! $punch->wasRecentlyCreated) {
                $results['duplicate']++;
            } elseif ($punch->status === BiometricPunch::STATUS_PROCESSED) {
                $results['processed']++;
            } elseif ($punch->status === BiometricPunch::STATUS_UNMATCHED) {
                $results['unmatched']++;
            } else {
                $results['skipped']++;
            }
        }

        fclose($handle);

        AuditLogger::log('imported', 'biometric_punches', null, null, ['device_id' => $device->id, ...$results]);

        return redirect()
            ->route('admin.biometric.punches', ['device' => $device->id])
            ->with('success', sprintf(
                'Import finished: %d marked, %d unmatched, %d skipped, %d duplicates, %d invalid rows.',
                $results['processed'],
                $results['unmatched'],
                $results['skipped'],
                $results['duplicate'],
                $results['invalid'],
            ));
    }

    protected function deviceData(Request $request, ?BiometricDevice $device = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'vendor' => ['nullable', 'string', 'max:120'],
            'serial_number' => ['required', 'string', 'max:120', Rule::unique('biometric_devices', 'serial_number')->ignore($device?->id)],
            'location' => ['nullable', 'string', 'max:160'],
            'course_id' => ['nullable', Rule::exists('courses', 'id')],
            'session_start' => ['nullable', 'date_format:H:i'],
            'late_after_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
