<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Enterprise audit trail writer.
 *
 * Every state-changing event in the system funnels through here so the log
 * rows are uniform: who, what, which module/record, old/new values, and the
 * full request fingerprint (IP, browser, OS, device, URL, method).
 */
class AuditLogger
{
    public static function log(
        string $action,
        string $module,
        int|string|null $recordId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Model $user = null,
    ): AuditLog {
        $user ??= Auth::user();
        $request = request();
        $agent = $request?->userAgent() ?? '';

        return AuditLog::create([
            'user_id' => $user?->getKey(),
            'user_name' => $user?->name ?? 'System',
            'user_role' => $user?->roles?->pluck('name')->implode(', ') ?: null,
            'action' => $action,
            'module' => $module,
            'record_id' => $recordId !== null ? (int) $recordId : null,
            'old_values' => static::scrub($oldValues),
            'new_values' => static::scrub($newValues),
            'ip_address' => $request?->ip(),
            'browser' => static::browser($agent),
            'os' => static::os($agent),
            'device' => static::device($agent),
            'url' => $request ? Str::limit($request->fullUrl(), 500, '') : null,
            'http_method' => $request?->method(),
        ]);
    }

    /** Never persist secrets into the audit trail. */
    protected static function scrub(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $hidden = ['password', 'password_confirmation', 'current_password', 'remember_token', 'token'];

        foreach ($hidden as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[redacted]';
            }
        }

        return $values;
    }

    protected static function browser(string $agent): ?string
    {
        return match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') && str_contains($agent, 'Version/') => 'Safari',
            str_contains($agent, 'Firefox/') => 'Firefox',
            $agent === '' => null,
            default => 'Other',
        };
    }

    protected static function os(string $agent): ?string
    {
        return match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            $agent === '' => null,
            default => 'Other',
        };
    }

    protected static function device(string $agent): ?string
    {
        return match (true) {
            str_contains($agent, 'iPad') || str_contains($agent, 'Tablet') => 'Tablet',
            str_contains($agent, 'Mobile') || str_contains($agent, 'iPhone') || str_contains($agent, 'Android') => 'Mobile',
            $agent === '' => null,
            default => 'Desktop',
        };
    }
}
