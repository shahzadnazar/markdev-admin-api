<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The rules that decide whether a set of sign-in details may become a `users` row.
 *
 * ONE SOURCE, TWO SCREENS. Users → New user has always enforced these. Clients →
 * New client now creates a login too, and a second hand-written copy of the same
 * rules is a copy that drifts: raise Password::defaults() or add a rule on one
 * screen and the other keeps letting through what it always did, which is the
 * shape of bug nobody finds until an account exists that should not.
 *
 * PREFIXED, because the client form already has its own `name` and `email` —
 * the company's, not the person's. Those are different columns on a different
 * table and they must not collide, so the portal fields arrive as
 * `portal_name`, `portal_email` and `portal_password` and the rules are built
 * under that prefix rather than restated for it.
 */
trait ValidatesLoginCredentials
{
    /**
     * @param  User|null  $user  the account being edited, whose own email does not clash with itself
     * @return array<string, array<int, mixed>>
     */
    protected function loginCredentialRules(?User $user = null, string $prefix = ''): array
    {
        return [
            $prefix.'name' => ['required', 'string', 'max:255'],
            $prefix.'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            // Nullable when editing: leaving the box empty keeps the old
            // password, which is what UserController::update relies on.
            $prefix.'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::defaults()],
        ];
    }
}
