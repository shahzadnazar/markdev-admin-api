<?php

namespace App\Http\Middleware;

use App\Models\BiometricDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Authenticates a hardware device (or its middleware) by X-Device-Key. */
class AuthenticateBiometricDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Device-Key');

        if (! $key) {
            return response()->json(['message' => 'Missing X-Device-Key header.'], 401);
        }

        $device = BiometricDevice::where('api_key', $key)->first();

        if (! $device || ! $device->is_active) {
            return response()->json(['message' => 'Unknown or inactive device.'], 403);
        }

        $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        $request->attributes->set('biometricDevice', $device);

        return $next($request);
    }
}
