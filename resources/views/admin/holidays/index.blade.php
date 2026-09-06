<x-admin.layout title="Holidays">
    <x-page-header eyebrow="System" title="Holidays"
        description="Dates the academy is closed. Nobody is expected on a holiday, so nobody is marked absent and nobody is fined — whatever slot they are on."
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Settings' => route('admin.settings.edit'), 'Holidays' => null]">
        <x-slot:actions>
            @can('settings.update')
                <x-btn :href="route('admin.holidays.create')">
                    <x-icon name="plus" class="size-4" /> New holiday
                </x-btn>
            @endcan
            <x-btn variant="ghost" :href="route('admin.settings.edit')">
                <x-icon name="arrow-left" class="size-4" /> Back to settings
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    {{-- The weekly pattern is a setting, not a row here; saying so once stops
         anyone adding 52 Sundays. --}}
    <x-card class="mb-4 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
        <x-icon name="calendar" class="size-4 shrink-0 text-primary" />
        <span class="text-on-surface-variant">The academy's working week is</span>
        <span class="font-medium text-on-surface">{{ $workingDaysLabel }}</span>
        <span class="text-on-surface-variant">— weekends need no row here.</span>
        <a href="{{ route('admin.settings.edit') }}#attendance" class="text-primary hover:underline">Change it in Settings</a>
    </x-card>

    <form method="GET" class="mb-4 flex items-center gap-2">
        <label for="year" class="text-sm text-on-surface-variant">Year</label>
        <select id="year" name="year" class="field w-32" onchange="this.form.submit()">
            @foreach ($years as $option)
                <option value="{{ $option }}" @selected($option === $year)>{{ $option }}</option>
            @endforeach
        </select>
        <noscript><x-btn type="submit" size="sm">Go</x-btn></noscript>
    </form>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Date</th>
                <th class="th">Holiday</th>
                <th class="th">Falls on</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($holidays as $holiday)
                <tr class="row">
                    <td class="td">
                        <span class="font-mono text-xs text-on-surface">{{ $holiday->date->format('j M Y') }}</span>
                    </td>
                    <td class="td"><p class="font-medium text-on-surface">{{ $holiday->name }}</p></td>
                    <td class="td"><span class="text-sm text-on-surface-variant">{{ $holiday->date->format('l') }}</span></td>
                    <td class="td text-right">
                        <div class="flex items-center justify-end gap-1">
                            @can('settings.update')
                                <a href="{{ route('admin.holidays.edit', $holiday) }}" aria-label="Edit holiday" title="Edit holiday" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                    <x-icon name="pencil" class="size-4" />
                                </a>
                                <x-confirm-form :action="route('admin.holidays.destroy', $holiday)" method="DELETE"
                                    title="Remove holiday"
                                    :message="'Remove '.$holiday->name.' on '.$holiday->date->format('j M Y').'? From then on it is an ordinary working day. Register rows already settled as a holiday keep that status.'"
                                    confirm-label="Remove"
                                    class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                    <x-icon name="trash" class="size-4" />
                                </x-confirm-form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">
                        <x-empty-state icon="calendar" title="No holidays in {{ $year }}"
                            description="Add Eid, 14 August and anything else the academy closes for. Weekends are already covered by the working week in Settings." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-table>
</x-admin.layout>
