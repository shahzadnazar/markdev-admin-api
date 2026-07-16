<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use App\Models\BiometricPunch;
use App\Services\BiometricAttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Device-facing ingestion endpoint. Devices (ZKTeco/ESSL/Hikvision middleware,
 * a Raspberry Pi bridge, etc.) POST batches of punches with their device key.
 */
class BiometricPunchController extends Controller
{
    public function store(Request $request, BiometricAttendanceService $service): JsonResponse
    {
        /** @var BiometricDevice $device */
        $device = $request->attributes->get('biometricDevice');

        $data = $request->validate([
            'punches' => ['required', 'array', 'min:1', 'max:500'],
            'punches.*.biometric_id' => ['required', 'string', 'max:64'],
            'punches.*.punched_at' => ['required', 'date'],
            'punches.*.direction' => ['nullable', 'string', 'max:10'],
        ]);

        $results = ['processed' => 0, 'unmatched' => 0, 'skipped' => 0, 'duplicate' => 0];

        foreach ($data['punches'] as $row) {
            $punch = $service->ingest($device, $row);

            if (! $punch->wasRecentlyCreated) {
                $results['duplicate']++;

                continue;
            }

            $results[match ($punch->status) {
                BiometricPunch::STATUS_PROCESSED => 'processed',
                BiometricPunch::STATUS_UNMATCHED => 'unmatched',
                default => 'skipped',
            }]++;
        }

        return response()->json([
            'data' => [
                'device' => $device->name,
                'received' => count($data['punches']),
                ...$results,
            ],
        ], 201);
    }
}
