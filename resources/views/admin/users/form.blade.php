<x-admin.layout :title="$user ? 'Edit user' : 'New user'">
    <x-page-header
        eyebrow="People"
        :title="$user ? 'Edit '.$user->name : 'New user'"
        :description="$user ? 'Update account details, roles and status.' : 'Create an account and assign its roles.'"
    >
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.users.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to users
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ $user ? route('admin.users.update', $user) : route('admin.users.store') }}" class="max-w-3xl">
        @csrf
        @if ($user) @method('PUT') @endif

        <x-form.errors-summary />

        <x-card class="space-y-5">
            <x-form.section title="Identity" description="Who this account belongs to and how to reach them.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-form.input label="Full name" name="name" :value="$user?->name" required />
                <x-form.input label="Email" name="email" type="email" :value="$user?->email" required />
                <x-form.input label="Phone" name="phone" :value="$user?->phone" />
                <x-form.input label="Biometric ID" name="biometric_id" :value="$user?->biometric_id"
                    hint="The user id enrolled on the fingerprint/face terminal." />
                <x-form.input label="Headline" name="headline" :value="$user?->headline" hint="Shown on instructor profiles." />
            </div>

            </x-form.section>

            <x-form.section title="Security" description="Password for portal sign-in.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-form.input label="Password" name="password" type="password" :required="! $user" :hint="$user ? 'Leave blank to keep the current password.' : 'Minimum 8 characters.'" autocomplete="new-password" />
                <x-form.input label="Confirm password" name="password_confirmation" type="password" :required="! $user" autocomplete="new-password" />
            </div>

            </x-form.section>

            <x-form.section title="Access & status" description="Roles decide what this user can see and do.">
            <div>
                <x-form.label value="Roles" />
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($roles as $role)
                        <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-outline-variant/60 px-4 py-3 transition hover:border-primary/40">
                            <input type="checkbox" name="roles[]" value="{{ $role->name }}" class="check"
                                @checked(in_array($role->name, old('roles', $user?->roles->pluck('name')->all() ?? (request('role') ? [request('role')] : []))))>
                            <span class="text-sm font-medium text-on-surface">{{ $role->name }}</span>
                        </label>
                    @endforeach
                </div>
                @unless (auth()->user()->hasRole('super-admin'))
                    <p class="mt-2 text-xs text-outline">Only a super admin can grant the admin or super-admin roles.</p>
                @endunless
                <x-form.error name="roles" />
                <x-form.error name="roles.*" />
            </div>

            <x-form.toggle label="Active account" name="is_active" :checked="$user?->is_active ?? true" hint="Inactive users cannot sign in." />
            </x-form.section>
        </x-card>

        <x-form.actions :cancel="route('admin.users.index')">
            <x-btn>
                <x-icon name="check" class="size-4" />
                {{ $user ? 'Save changes' : 'Create user' }}
            </x-btn>
        </x-form.actions>
    </form>
</x-admin.layout>
