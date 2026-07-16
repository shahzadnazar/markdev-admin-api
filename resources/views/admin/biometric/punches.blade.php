<x-admin.layout title="Punch log">
    <x-page-header eyebrow="Learning" title="Punch log" description="Raw check-ins from every biometric device, and what they became.">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.biometric.devices')">
                <x-icon name="server" class="size-4" /> Devices
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-[1fr_24rem]">
        <div>
            <x-filter-bar :action="route('admin.biometric.punches')">
                <div class="w-56">
                    <x-form.label for="device" value="Device" />
                    <select name="device" id="device" class="field">
                        <option value="">All devices</option>
                        @foreach ($devices as $device)
                            <option value="{{ $device->id }}" @selected(request('device') == $device->id)>{{ $device->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-44">
                    <x-form.label for="status" value="Status" />
                    <select name="status" id="status" class="field">
                        <option value="">All statuses</option>
                        @foreach (['processed', 'unmatched', 'skipped', 'pending'] as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-40">
                    <x-form.label for="from" value="From" />
                    <input type="date" name="from" id="from" value="{{ request('from') }}" class="field">
                </div>
                <div class="w-40">
                    <x-form.label for="to" value="To" />
                    <input type="date" name="to" id="to" value="{{ request('to') }}" class="field">
                </div>
            </x-filter-bar>

            <x-table>
                <thead class="bg-surface-ice/60">
                    <tr>
                        <th class="th">Punched at</th>
                        <th class="th">Device</th>
                        <th class="th">Biometric ID</th>
                        <th class="th">Student</th>
                        <th class="th">Result</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($punches as $punch)
                        <tr class="row">
                            <td class="td whitespace-nowrap font-mono text-xs text-outline">{{ $punch->punched_at->format('M j · H:i:s') }}</td>
                            <td class="td text-sm text-on-surface-variant">{{ $punch->device?->name ?? '—' }}</td>
                            <td class="td font-mono text-xs text-on-surface">{{ $punch->biometric_id }}</td>
                            <td class="td">
                                @if ($punch->user)
                                    <p class="text-sm font-medium text-on-surface">{{ $punch->user->name }}</p>
                                @else
                                    <span class="text-xs text-outline">unknown</span>
                                @endif
                            </td>
                            <td class="td">
                                <x-badge :variant="['processed' => 'success', 'unmatched' => 'warning', 'skipped' => 'neutral', 'pending' => 'neutral'][$punch->status] ?? 'neutral'">
                                    {{ $punch->status }}
                                </x-badge>
                                @if ($punch->attendanceRecord)
                                    <x-badge :variant="$punch->attendanceRecord->status === 'present' ? 'success' : 'warning'" class="ml-1">
                                        {{ $punch->attendanceRecord->status }}
                                    </x-badge>
                                @endif
                                @if ($punch->note)
                                    <p class="mt-1 text-[11px] text-outline">{{ $punch->note }}</p>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-empty-state icon="clipboard" title="No punches yet" description="Check-ins appear here the moment a device (or import) sends them." /></td></tr>
                    @endforelse
                </tbody>
                @if ($punches->hasPages())
                    <x-slot:footer>{{ $punches->links() }}</x-slot:footer>
                @endif
            </x-table>
        </div>

        @can('attendance.manage')
            <x-card class="self-start">
                <h2 class="font-display text-lg font-semibold text-on-surface">Import punches (CSV)</h2>
                <p class="mt-1 text-sm text-on-surface-variant">
                    For offline devices: export the punch log from the device software and upload it here.
                </p>
                <form method="POST" action="{{ route('admin.biometric.punches.import') }}" enctype="multipart/form-data" class="mt-5 space-y-4">
                    @csrf
                    <x-form.select label="Device" name="device_id" required>
                        <option value="">Choose the source device…</option>
                        @foreach ($devices as $device)
                            <option value="{{ $device->id }}" @selected(old('device_id') == $device->id)>{{ $device->name }}</option>
                        @endforeach
                    </x-form.select>
                    <div>
                        <x-form.label for="file" value="CSV file" />
                        <input type="file" name="file" id="file" accept=".csv,.txt" required
                            class="field file:mr-3 file:rounded-md file:border-0 file:bg-primary/10 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-primary">
                        <p class="mt-1.5 text-xs text-outline">Columns: <span class="font-mono">biometric_id, punched_at[, direction]</span> — a header row is fine.</p>
                        <x-form.error name="file" />
                    </div>
                    <x-btn class="w-full"><x-icon name="upload" class="size-4" /> Import</x-btn>
                </form>
            </x-card>
        @endcan
    </div>
</x-admin.layout>
