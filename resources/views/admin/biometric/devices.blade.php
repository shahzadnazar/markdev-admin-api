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
            <x-filter-bar results="devices-results" :action="route('admin.biometric.devices')">
                <div class="w-full sm:w-72">
                    <x-form.label for="search" value="Search" />
                    <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Name, serial or location…" class="field">
                </div>
            </x-filter-bar>

            <div id="devices-results" class="transition-opacity duration-150">
                @include('admin.biometric._devices-results')
            </div>
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
