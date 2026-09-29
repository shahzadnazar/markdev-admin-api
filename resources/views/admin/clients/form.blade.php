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

            <div class="border-t border-surface-ice pt-5">
                <x-form.select label="Client login" name="user_id"
                    hint="Optional, and nothing reads it yet — the client portal is a later phase. Setting it now saves coming back.">
                    <option value="">No login</option>
                    @foreach ($logins as $login)
                        <option value="{{ $login->id }}" @selected((string) old('user_id', $client?->user_id) === (string) $login->id)>{{ $login->name }} · {{ $login->email }}</option>
                    @endforeach
                </x-form.select>
            </div>

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
