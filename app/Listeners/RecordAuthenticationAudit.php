<?php

namespace App\Listeners;

use App\Support\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;

/** Streams every authentication event into the audit trail. */
class RecordAuthenticationAudit
{
    public function handleLogin(Login $event): void
    {
        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();

        AuditLogger::log('login', 'auth', $event->user->getAuthIdentifier(), user: $event->user);
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user) {
            AuditLogger::log('logout', 'auth', $event->user->getAuthIdentifier(), user: $event->user);
        }
    }

    public function handleFailed(Failed $event): void
    {
        AuditLogger::log(
            'failed_login',
            'auth',
            null,
            null,
            ['email' => $event->credentials['email'] ?? null],
            $event->user,
        );
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        AuditLogger::log('password_reset', 'auth', $event->user->getAuthIdentifier(), user: $event->user);
    }
}
