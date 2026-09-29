{{-- Shared by both status lists; see the index view. --}}
<x-admin.layout :title="$status ? 'Edit '.$noun : 'New '.$noun">
    <x-page-header
        eyebrow="Team"
        :title="$status ? 'Edit '.$status->label : 'New '.$noun"
        description="The label is yours to word. The behaviour is what the system acts on — it is picked from a fixed list, never typed."
        :crumbs="[$heading => route('admin.'.$routeName.'.index'), ($status ? 'Edit' : 'New') => null]">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.'.$routeName.'.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to {{ $heading }}
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ $status ? route('admin.'.$routeName.'.update', $status) : route('admin.'.$routeName.'.store') }}" class="max-w-2xl">
        @csrf
        @if ($status) @method('PUT') @endif

        <x-form.errors-summary />

        <x-card class="space-y-5">
            <x-form.input label="Label" name="label" :value="$status?->label" required
                placeholder="e.g. Waiting on client" hint="What the team calls it. Rename it whenever the wording changes — nothing in the system reads this." />

            <div class="border-t border-surface-ice pt-5">
                <x-form.select label="Behaviour" name="behaviour" required
                    hint="What the system does about it. Several labels may share one behaviour — &quot;In Progress&quot; and &quot;Review&quot; are both work being done.">
                    <option value="">Pick a behaviour</option>
                    @foreach ($behaviours as $behaviour => $label)
                        <option value="{{ $behaviour }}" @selected(old('behaviour', $status?->behaviour) === $behaviour)>{{ $label }}</option>
                    @endforeach
                </x-form.select>
            </div>

            <div class="border-t border-surface-ice pt-5">
                <x-form.label for="colour" value="Colour" :required="true" />
                <input type="color" name="colour" id="colour" required
                    value="{{ old('colour', $status?->colour ?? '#124389') }}"
                    class="h-11 w-24 cursor-pointer rounded-lg border border-outline-variant/60 bg-white p-1">
                <p class="mt-1.5 text-xs text-outline">Shown on the board and in lists. Colour is decoration — nothing is decided by it.</p>
                <x-form.error name="colour" />
            </div>

            <div class="border-t border-surface-ice pt-5">
                <x-form.toggle label="On offer" name="is_active"
                    :checked="$status?->is_active ?? true"
                    hint="Retiring a status leaves everything already on it alone. Every behaviour has to keep at least one status on offer, so the last one cannot be retired." />
            </div>
        </x-card>

        <div class="mt-6 flex items-center gap-3">
            <x-btn>
                <x-icon name="check" class="size-4" />
                {{ $status ? 'Save changes' : 'Add '.$noun }}
            </x-btn>
            <x-btn variant="ghost" :href="route('admin.'.$routeName.'.index')">Cancel</x-btn>
        </div>
    </form>
</x-admin.layout>
