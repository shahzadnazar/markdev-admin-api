<?php

namespace App\Providers;

use App\Listeners\RecordAuthenticationAudit;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use App\Models\Setting;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Super Admin bypasses every permission gate (full ownership).
        Gate::before(function (User $user, string $ability) {
            return $user->hasRole('super-admin') ? true : null;
        });

        /*
         * Reading a student's private notes.
         *
         * A ROLE check, deliberately, in a codebase that otherwise gates on
         * permissions. A permission is something a super-admin can grant, and
         * this one must never be grantable: the whole value of the notebook is
         * that the list of people who can read it is fixed and short. Putting
         * `private-notes.view` in the matrix would put a "give this to an
         * admin" checkbox on the Roles page, which is exactly the promise the
         * portal now makes to students and would then have to un-make.
         *
         * Gate::before above already lets super-admin through everything, so
         * this line is what stops EVERYONE ELSE — and it is written out rather
         * than left implicit so the rule is greppable.
         */
        Gate::define('private-notes.read', fn (User $user) => $user->hasRole('super-admin'));

        // Settings are memoised per process, which is right for a web request
        // and wrong for anything long-lived. `schedule:work` and a queue
        // worker stay up for days, so without this the day close and the fine
        // run would keep whatever allowance, day start or marking mode they
        // read first — an admin's change would not reach them until a restart,
        // and students would be marked and billed against a stale number.
        Event::listen(CommandStarting::class, fn () => Setting::forgetCached());
        Event::listen(JobProcessing::class, fn () => Setting::forgetCached());

        Event::listen(Login::class, [RecordAuthenticationAudit::class, 'handleLogin']);
        Event::listen(Logout::class, [RecordAuthenticationAudit::class, 'handleLogout']);
        Event::listen(Failed::class, [RecordAuthenticationAudit::class, 'handleFailed']);
        Event::listen(PasswordReset::class, [RecordAuthenticationAudit::class, 'handlePasswordReset']);
    }
}
