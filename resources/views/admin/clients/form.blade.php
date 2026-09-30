<x-admin.layout :title="$client ? 'Edit client' : 'New client'">
    <x-page-header
        eyebrow="Team"
        :title="$client ? 'Edit '.$client->name : 'New client'"
        description="Contact details and notes. Nothing here is ever shown to a team — they see a project by its name and code."
        :crumbs="['Clients' => route('admin.clients.index'), ($client ? 'Edit' : 'New') => null]">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.clients.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to clients
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ $client ? route('admin.clients.update', $client) : route('admin.clients.store') }}" class="max-w-2xl">
        @csrf
        @if ($client) @method('PUT') @endif

        <x-form.errors-summary />

        <x-card class="space-y-5">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-form.input label="Client name" name="name" :value="$client?->name" required
                    placeholder="e.g. Jane Doe" hint="The person MarkDev deals with." />
                <x-form.input label="Company" name="company" :value="$client?->company"
                    placeholder="e.g. Acme Ltd" hint="Optional — a sole trader has none." />
            </div>

            <div class="grid gap-5 border-t border-surface-ice pt-5 sm:grid-cols-2">
                <x-form.input type="email" label="Email" name="email" :value="$client?->email" />
                <x-form.input label="Phone" name="phone" :value="$client?->phone" />
            </div>

            <div class="border-t border-surface-ice pt-5">
                <x-form.textarea label="Address" name="address" rows="3" :value="$client?->address" />
            </div>

            <div class="border-t border-surface-ice pt-5">
                <x-form.textarea label="Notes" name="notes" rows="4" :value="$client?->notes"
                    hint="Internal. Never shown to the client or to a team." />
            </div>

            @php
                // The creation fields are offered while this client has no
                // account, on New client and on Edit alike. Once there is one,
                // the section is replaced by a panel naming it: changing
                // somebody's password belongs on the Users form, not here.
                //
                // The RELATION, matching ClientController::validated — a trashed
                // account leaves user_id set and the relation null, and that
                // client needs a new login rather than a panel about a name
                // nobody can reach.
                $mayCreateLogin = $client === null || $client->user === null;
            @endphp

            <div class="border-t border-surface-ice pt-5">
                <x-form.select label="Client login" name="user_id"
                    :hint="$mayCreateLogin
                        ? 'For an account that ALREADY exists. Leave it on “No login” if you are creating one below.'
                        : 'The account this client signs in with. Changing it here re-links the client; it does not rename or re-password anything.'">
                    <option value="">No login</option>
                    @foreach ($logins as $login)
                        <option value="{{ $login->id }}" @selected((string) old('user_id', $client?->user_id) === (string) $login->id)>{{ $login->name }} &middot; {{ $login->email }}</option>
                    @endforeach
                </x-form.select>
            </div>

            @unless ($mayCreateLogin)
                <x-form.section title="Portal access"
                    description="This client already signs in.">
                    <div class="rounded-xl border border-outline-variant/60 bg-surface-ice/50 px-4 py-3">
                        <p class="text-[13px] font-medium text-on-surface">{{ $client->user->name }}</p>
                        <p class="text-[13px] text-on-surface-variant">{{ $client->user->email }}</p>
                        <p class="mt-2 text-xs text-outline">
                            To rename the account or set a new password, edit it in Users — this form does not change
                            somebody else's sign-in details.
                        </p>
                        @can('users.update')
                            <a href="{{ route('admin.users.edit', $client->user) }}" class="mt-2 inline-flex items-center gap-1 text-[13px] font-semibold text-primary hover:underline">
                                Open this account in Users
                                <x-icon name="chevron-right" class="size-3.5" />
                            </a>
                        @endcan
                    </div>
                </x-form.section>
            @endunless

            {{-- WHILE THERE IS NO LOGIN — on New client, and on Edit for a
                 company that was added before anybody decided about access.
                 That second case is the common one, and leaving it out meant
                 deciding later still cost a trip to Users. --}}
            @if ($mayCreateLogin)
                {{-- x-form.section draws its own top border, so no wrapper. --}}
                <x-form.section title="Portal access"
                    :description="$client
                        ? 'Optional. Fill this in and saving also creates the sign-in for the client portal — this company has none yet.'
                        : 'Optional. Fill this in and saving also creates the sign-in for the client portal — no second screen, no separate step in Users. Leave it blank for a company that is not being given access yet; work often starts first.'">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-form.input label="Full name" name="portal_name" :value="old('portal_name')"
                            placeholder="e.g. Jane Doe" hint="The person who signs in — not the company." />
                        <x-form.input type="email" label="Sign-in email" name="portal_email" :value="old('portal_email')"
                            hint="Lowercase, and not already used by another account." />
                        <x-form.input type="password" label="Password" name="portal_password" autocomplete="new-password" />
                        <x-form.input type="password" label="Confirm password" name="portal_password_confirmation" autocomplete="new-password"
                            hint="Typed twice for the same reason Users asks twice — nobody can read back what was set." />
                    </div>
                    <p class="text-xs text-outline">
                        The account is created with the <strong>client</strong> role and nothing else. It reaches the client portal and no part of this admin panel.
                    </p>
                </x-form.section>
            @endif

            <div class="border-t border-surface-ice pt-5">
                <x-form.toggle label="Taking new projects" name="is_active"
                    :checked="$client?->is_active ?? true"
                    hint="Turning this off stops the client being offered on new projects. Their existing ones are unchanged." />
            </div>
        </x-card>

        <div class="mt-6 flex items-center gap-3">
            <x-btn>
                <x-icon name="check" class="size-4" />
                {{ $client ? 'Save changes' : 'Add client' }}
            </x-btn>
            <x-btn variant="ghost" :href="route('admin.clients.index')">Cancel</x-btn>
        </div>
    </form>
</x-admin.layout>
