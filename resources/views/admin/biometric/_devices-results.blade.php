<x-table>
    <thead class="bg-surface-ice/60">
        <tr>
            <th class="th">Device</th>
            <th class="th">Course</th>
            <th class="th">Session</th>
            <th class="th">Punches</th>
            <th class="th">Status</th>
            <th class="th text-right">Actions</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($devices as $device)
            <tr class="row">
                <td class="td">
                    <p class="font-medium text-on-surface">{{ $device->name }}</p>
                    <p class="font-mono text-[11px] text-outline">{{ $device->serial_number }}{{ $device->vendor ? ' · '.$device->vendor : '' }}{{ $device->location ? ' · '.$device->location : '' }}</p>
                </td>
                <td class="td max-w-[14rem]"><p class="truncate text-sm text-on-surface-variant">{{ $device->course?->title ?? '—' }}</p></td>
                <td class="td font-mono text-xs text-on-surface-variant">
                    {{ $device->session_start ? \Illuminate\Support\Str::of($device->session_start)->substr(0, 5) : 'Any time' }}
                    <span class="text-outline">+{{ $device->late_after_minutes }}m</span>
                </td>
                <td class="td">
                    <span class="font-mono text-xs text-on-surface-variant">{{ $device->punches_count }}</span>
                    @if ($device->unmatched_punches_count > 0)
                        <x-badge variant="warning" class="ml-1">{{ $device->unmatched_punches_count }} unmatched</x-badge>
                    @endif
                </td>
                <td class="td">
                    <x-badge :variant="$device->is_active ? 'success' : 'neutral'">{{ $device->is_active ? 'active' : 'disabled' }}</x-badge>
                    <p class="mt-1 font-mono text-[10px] text-outline">
                        {{ $device->last_seen_at ? 'seen '.$device->last_seen_at->diffForHumans() : 'never seen' }}
                    </p>
                </td>
                <td class="td text-right">
                    @can('attendance.manage')
                        <div class="inline-flex items-center gap-1">
                            @if ($device->unmatched_punches_count > 0)
                                <form method="POST" action="{{ route('admin.biometric.devices.reprocess', $device) }}">
                                    @csrf
                                    <x-btn variant="ghost" size="sm" title="Retry unmatched punches">
                                        <x-icon name="restore" class="size-4" />
                                    </x-btn>
                                </form>
                            @endif
                            <button type="button" x-data x-on:click="$dispatch('open-modal', 'edit-device-{{ $device->id }}')"
                                class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/5 hover:text-primary" aria-label="Edit device">
                                <x-icon name="pencil" class="size-4" />
                            </button>
                            <x-confirm-form
                                :action="route('admin.biometric.devices.key', $device)"
                                title="Issue a new key?"
                                message="The device stops authenticating until you configure the new key on it."
                                confirm-label="Regenerate key"
                                variant="primary"
                                class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/5 hover:text-primary"
                                aria-label="Regenerate key"
                            >
                                <x-icon name="shield" class="size-4" />
                            </x-confirm-form>
                            <x-confirm-form
                                :action="route('admin.biometric.devices.destroy', $device)"
                                method="DELETE"
                                title="Remove this device?"
                                message="Its punch history is kept, but it can no longer send check-ins."
                                confirm-label="Remove"
                                class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error"
                                aria-label="Remove device"
                            >
                                <x-icon name="trash" class="size-4" />
                            </x-confirm-form>
                        </div>
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td colspan="6"><x-empty-state icon="server" title="No devices registered" description="Register your first fingerprint or face terminal to start collecting check-ins." /></td></tr>
        @endforelse
    </tbody>
    @if ($devices->hasPages())
        <x-slot:footer><div data-pagination>{{ $devices->links() }}</div></x-slot:footer>
    @endif
</x-table>

    {{-- Edit modals — rendered outside the table so <tbody> stays valid HTML --}}
    @can('attendance.manage')
        @foreach ($devices as $device)
<x-modal :name="'edit-device-'.$device->id" max-width="lg">
    <form method="POST" action="{{ route('admin.biometric.devices.update', $device) }}" class="space-y-4 p-6">
        @csrf
        @method('PUT')
        <h3 class="font-display text-lg font-semibold text-on-surface">Edit device</h3>
        @include('admin.biometric.partials.device-fields', ['device' => $device])
        <div class="flex justify-end gap-3">
            <x-btn type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'edit-device-{{ $device->id }}')">Cancel</x-btn>
            <x-btn><x-icon name="check" class="size-4" /> Save device</x-btn>
        </div>
    </form>
</x-modal>
        @endforeach
    @endcan
