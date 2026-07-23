<x-admin.layout title="Settings">
    <x-page-header eyebrow="System" title="Settings" description="Platform configuration and backups — Super Admin territory." />

    <div class="grid gap-6 lg:grid-cols-[1fr_24rem]">
        {{-- General settings --}}
        <form method="POST" action="{{ route('admin.settings.update') }}">
            @csrf
            @method('PUT')

            <x-card class="space-y-5">
                <h2 class="font-display text-lg font-semibold text-on-surface">General</h2>

                <x-form.input label="Site name" name="site_name" :value="$settings['site_name']" required />
                <x-form.input type="email" label="Support email" name="support_email" :value="$settings['support_email']"
                    hint="Shown to students in the Help Center." />
                <x-form.input label="Support phone" name="support_phone" :value="$settings['support_phone']"
                    hint="Shown on the student fee-payment screen." placeholder="+92 300 1234567" />

                <x-form.select label="Default timezone" name="timezone" required>
                    @foreach ($timezones as $timezone)
                        <option value="{{ $timezone }}" @selected(old('timezone', $settings['timezone']) === $timezone)>{{ $timezone }}</option>
                    @endforeach
                </x-form.select>

                <div class="grid gap-5 sm:grid-cols-3 border-t border-surface-ice pt-5">
                    <x-form.input type="number" label="Defaulter fine / day" name="defaulter_fine_per_day"
                        :value="$settings['defaulter_fine_per_day']" required min="0" step="0.01"
                        hint="Added daily once the grace period ends." />
                    <x-form.input type="number" label="Grace days" name="billing_grace_days"
                        :value="$settings['billing_grace_days']" required min="0" max="60"
                        hint="Warning window after the due date." />
                    <x-form.input type="number" label="Activation days" name="billing_activation_days"
                        :value="$settings['billing_activation_days']" required min="0" max="28"
                        hint="How many days before the due date an installment opens." />
                </div>

                <x-form.toggle label="Maintenance mode" name="maintenance_mode" :checked="(bool) old('maintenance_mode', $settings['maintenance_mode'])"
                    hint="Shows a maintenance banner to admin users; plan portal downtime with your team." />

                @can('settings.update')
                    <div class="pt-1">
                        <x-btn><x-icon name="check" class="size-4" /> Save settings</x-btn>
                    </div>
                @endcan
            </x-card>
        </form>

        {{-- Backups --}}
        <div>
            <x-card>
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="font-display text-lg font-semibold text-on-surface">Backups</h2>
                        <p class="mt-1 text-sm text-on-surface-variant">Database snapshots via spatie/laravel-backup.</p>
                    </div>
                    <div class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-secondary/10 text-secondary">
                        <x-icon name="server" class="size-5" />
                    </div>
                </div>

                @can('backups.run')
                    <form method="POST" action="{{ route('admin.settings.backups.run') }}" class="mt-5">
                        @csrf
                        <x-btn variant="secondary" class="w-full">
                            <x-icon name="upload" class="size-4" /> Run backup now
                        </x-btn>
                    </form>
                    <p class="mt-2 text-xs text-outline">Queued — a worker must be running (php artisan queue:work).</p>
                @endcan

                <div class="mt-6 border-t border-surface-ice pt-4">
                    <p class="eyebrow mb-3">Recent backups</p>
                    @forelse ($backups as $backup)
                        <div class="flex items-center justify-between gap-3 py-2">
                            <div class="min-w-0">
                                <p class="truncate font-mono text-xs text-on-surface">{{ $backup['name'] }}</p>
                                <p class="font-mono text-[10px] text-outline">{{ $backup['date']->format('M j, Y · H:i') }}</p>
                            </div>
                            <span class="shrink-0 font-mono text-[11px] text-on-surface-variant">{{ number_format($backup['size'] / 1024, 0) }} KB</span>
                        </div>
                    @empty
                        <p class="text-sm text-on-surface-variant">No backups yet.</p>
                    @endforelse
                </div>
            </x-card>
        </div>
    </div>
</x-admin.layout>
