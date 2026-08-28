<x-admin.layout title="Attendance slots">
    <x-page-header eyebrow="System" title="Attendance slots"
        description="The parts of the teaching day students are admitted into. Each slot decides when the students on it count as late."
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Settings' => route('admin.settings.edit'), 'Attendance slots' => null]">
        <x-slot:actions>
            @can('settings.update')
                <x-btn :href="route('admin.attendance-slots.create')">
                    <x-icon name="plus" class="size-4" /> New slot
                </x-btn>
            @endcan
            <x-btn variant="ghost" :href="route('admin.settings.edit')">
                <x-icon name="arrow-left" class="size-4" /> Back to settings
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Slot</th>
                <th class="th">Runs</th>
                <th class="th">Late after</th>
                <th class="th td-num">Students</th>
                <th class="th">Status</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($slots as $slot)
                <tr class="row">
                    <td class="td">
                        <p class="font-medium text-on-surface">{{ $slot->name }}</p>
                    </td>
                    <td class="td">
                        {{-- Every time in the panel reads 12-hour, whatever the column stores. --}}
                        <span class="font-mono text-xs text-on-surface-variant">{{ $slot->rangeLabel() }}</span>
                        <p class="mt-0.5 text-[11px] text-outline">Every day</p>
                    </td>
                    <td class="td">
                        <span class="text-sm text-on-surface">{{ $slot->late_after_minutes }} min</span>
                        <p class="mt-0.5 font-mono text-[11px] text-outline">from {{ $slot->startLabel() }}</p>
                    </td>
                    <td class="td td-num"><x-badge variant="primary">{{ $slot->student_profiles_count }}</x-badge></td>
                    <td class="td">
                        @if ($slot->is_active)
                            <x-badge variant="success">Active</x-badge>
                        @else
                            <x-badge variant="neutral">Hidden</x-badge>
                        @endif
                    </td>
                    <td class="td text-right">
                        <div class="flex items-center justify-end gap-1">
                            @can('settings.update')
                                <form method="POST" action="{{ route('admin.attendance-slots.move', $slot) }}">
                                    @csrf <input type="hidden" name="direction" value="up">
                                    <button type="submit" @disabled($loop->first) class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary disabled:opacity-30" title="Move up">
                                        <x-icon name="arrow-up" class="size-4" />
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('admin.attendance-slots.move', $slot) }}">
                                    @csrf <input type="hidden" name="direction" value="down">
                                    <button type="submit" @disabled($loop->last) class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary disabled:opacity-30" title="Move down">
                                        <x-icon name="arrow-down" class="size-4" />
                                    </button>
                                </form>
                                <x-confirm-form :action="route('admin.attendance-slots.toggle', $slot)" method="POST"
                                    :title="$slot->is_active ? 'Hide slot' : 'Offer slot'"
                                    :message="$slot->is_active
                                        ? 'Stop offering '.$slot->name.' on the registration form? Students already on it keep it and keep their timings.'
                                        : 'Offer '.$slot->name.' on the registration form again?'"
                                    :confirm-label="$slot->is_active ? 'Hide slot' : 'Offer slot'"
                                    variant="primary"
                                    class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                    <x-icon :name="$slot->is_active ? 'eye' : 'restore'" class="size-4" />
                                </x-confirm-form>
                                <a href="{{ route('admin.attendance-slots.edit', $slot) }}" aria-label="Edit slot" title="Edit slot" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                    <x-icon name="pencil" class="size-4" />
                                </a>
                                <x-confirm-form :action="route('admin.attendance-slots.destroy', $slot)" method="DELETE"
                                    title="Delete slot"
                                    :message="'Delete '.$slot->name.'? '.($slot->student_profiles_count > 0
                                        ? $slot->student_profiles_count.' student(s) lose this assignment and fall back to the academy day start until reassigned.'
                                        : 'No students are assigned to it.')"
                                    confirm-label="Delete"
                                    class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                    <x-icon name="trash" class="size-4" />
                                </x-confirm-form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-empty-state icon="clock" title="No slots yet"
                            description="Create the first slot to judge lateness per group. Until then every student is measured against the academy-wide day start in Settings." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-table>
</x-admin.layout>
