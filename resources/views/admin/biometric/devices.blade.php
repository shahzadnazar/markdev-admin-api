<x-admin.layout title="Biometric devices">
    <x-page-header eyebrow="Learning" title="Biometric devices"
        description="Fingerprint and face terminals that push student check-ins into attendance.">
        <x-slot:actions>
            <x-btn variant="secondary" :href="route('admin.biometric.punches')">
                <x-icon name="clipboard" class="size-4" /> Punch log
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    {{-- One-time key reveal after register/regenerate --}}
    @if (session('device_key'))
        <x-card class="mb-6 border border-primary/30 bg-primary/[0.04]">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="min-w-0">
                    <p class="eyebrow">Device key — {{ session('device_key')['name'] }}</p>
                    <p class="mt-1 font-mono text-sm break-all text-on-surface">{{ session('device_key')['key'] }}</p>
                    <p class="mt-1 text-xs text-outline">Copy it now — it is only shown this once. Configure the device (or its bridge software) to send it as the <span class="font-mono">X-Device-Key</span> header.</p>
                </div>
                <x-btn variant="secondary" size="sm" type="button"
                    x-data x-on:click="navigator.clipboard.writeText(@js(session('device_key')['key'])); $el.innerText = 'Copied!'">
                    Copy key
                </x-btn>
            </div>
        </x-card>
    @endif

    <div class="grid gap-6 xl:grid-cols-[1fr_24rem]">
        <div>
            <x-filter-bar :action="route('admin.biometric.devices')">
                <div class="w-full sm:w-72">
                    <x-form.label for="search" value="Search" />
                    <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Name, serial or location…" class="field">
                </div>
            </x-filter-bar>

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
                    <x-slot:footer>{{ $devices->links() }}</x-slot:footer>
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
        </div>

        @can('attendance.manage')
            <div class="space-y-6">
                <x-card>
                    <h2 class="font-display text-lg font-semibold text-on-surface">Register device</h2>
                    <p class="mt-1 text-sm text-on-surface-variant">You'll get the device key right after saving.</p>
                    <form method="POST" action="{{ route('admin.biometric.devices.store') }}" class="mt-5 space-y-4">
                        @csrf
                        @include('admin.biometric.partials.device-fields', ['device' => null])
                        <x-btn class="w-full"><x-icon name="plus" class="size-4" /> Register device</x-btn>
                    </form>
                </x-card>

                <x-card>
                    <p class="eyebrow mb-2">How it connects</p>
                    <ol class="list-decimal space-y-1.5 pl-4 text-sm leading-6 text-on-surface-variant">
                        <li>Give each student a <span class="font-mono text-xs">biometric id</span> on their user profile — the same id enrolled on the terminal.</li>
                        <li>Point the device (or its bridge software) at
                            <span class="font-mono text-xs break-all">POST /api/v1/biometric/punches</span>
                            with the <span class="font-mono text-xs">X-Device-Key</span> header.</li>
                        <li>Check-ins land as <em>present</em> or <em>late</em> based on the session start and grace window.</li>
                        <li>No network on the device? Import its CSV export from the punch log page.</li>
                    </ol>
                </x-card>
            </div>
        @endcan
    </div>
</x-admin.layout>
