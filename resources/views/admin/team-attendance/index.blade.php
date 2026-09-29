{{-- Marking a team's day. Leads and admins only — a member marks nobody.
     An absence already recorded cannot be undone here: the control is not
     offered, and the model refuses it even if it were. --}}
<x-admin.layout title="Team attendance">
    <x-page-header eyebrow="Team" title="Team attendance"
        :description="'Office starts at '.$lateAfter.'; arriving more than '.$grace.' minutes after that is late. No slots — one rule for everybody.'"
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Team attendance' => null]" />

    <x-form.errors-summary />

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
        <x-form.select label="Team" name="team" class="w-56">
            @foreach ($teams as $option)
                <option value="{{ $option->id }}" @selected($team && $team->id === $option->id)>{{ $option->name }}</option>
            @endforeach
        </x-form.select>
        <x-form.input type="date" label="Day" name="date" :value="$day->toDateString()" class="w-48" />
        <x-btn variant="secondary" size="md" type="submit">
            <x-icon name="funnel" class="size-4" /> Show
        </x-btn>
    </form>

    @if (! $isWorkingDay)
        <div class="mb-4 rounded-xl border border-outline-variant/60 bg-surface-ice/60 px-4 py-3">
            <p class="text-[13px] text-on-surface-variant">
                {{ $holiday ? $holiday.' — the office is closed.' : 'Not a working day.' }}
                Marks made here still save; the nightly close leaves the day alone.
            </p>
        </div>
    @endif

    @if ($team)
        <form method="POST" action="{{ route('admin.team-attendance.store') }}">
            @csrf
            <input type="hidden" name="team" value="{{ $team->id }}">
            <input type="hidden" name="date" value="{{ $day->toDateString() }}">

            <x-table>
                <thead class="bg-surface-ice/60">
                    <tr>
                        <th class="th">Member</th>
                        <th class="th">Today</th>
                        <th class="th">Mark</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($members as $member)
                        @php $record = $records->get($member->id); @endphp
                        <tr class="row">
                            <td class="td"><span class="font-medium text-on-surface">{{ $member->name }}</span></td>
                            <td class="td">
                                @if ($record)
                                    <x-badge :variant="match ($record->status) {
                                        'present' => 'success',
                                        'late' => 'warning',
                                        'absent' => 'danger',
                                        'leave' => 'primary',
                                        default => 'neutral',
                                    }">{{ ucfirst($record->status) }}</x-badge>
                                @else
                                    <span class="text-sm text-outline">Not marked</span>
                                @endif
                            </td>
                            <td class="td">
                                @if ($record?->isLockedAbsence())
                                    {{-- Presentation only: the model refuses the
                                         write whether or not this is rendered. --}}
                                    <span class="text-[13px] text-outline">Absent is final — ask an admin to correct it.</span>
                                @else
                                    <select name="status[{{ $member->id }}]" class="field w-40">
                                        <option value="">— leave as is —</option>
                                        @foreach (\App\Models\TeamAttendance::STATUSES as $status)
                                            <option value="{{ $status }}" @selected($record?->status === $status)>{{ ucfirst($status) }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-table>

            <div class="mt-5">
                <x-btn><x-icon name="check" class="size-4" /> Save marks</x-btn>
            </div>
        </form>
    @else
        <x-empty-state icon="users" title="No team to mark"
            description="You are not on a team yet, so there is nobody here to mark." />
    @endif
</x-admin.layout>
