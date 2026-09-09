<div x-data="{ detail: null }">
    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">When</th>
                <th class="th">User</th>
                <th class="th">Action</th>
                <th class="th">Module</th>
                <th class="th">Record</th>
                <th class="th">Request</th>
                <th class="th">Client</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($logs as $log)
                <tr class="row cursor-pointer" tabindex="0" role="button"
                    aria-label="Open audit entry #{{ $log->id }}"
                    x-on:click="detail = {{ $log->id }}"
                    x-on:keydown.enter.prevent="detail = {{ $log->id }}"
                    x-on:keydown.space.prevent="detail = {{ $log->id }}">
                    <td class="td whitespace-nowrap font-mono text-xs text-outline">{{ $log->created_at->format('j M · H:i:s') }}</td>
                    <td class="td">
                        <p class="font-medium text-on-surface">{{ $log->user_name }}</p>
                        @if ($log->user_role)
                            <p class="font-mono text-[10px] uppercase tracking-[0.08em] text-outline">{{ $log->user_role }}</p>
                        @endif
                    </td>
                    <td class="td">
                        <x-badge :variant="match (true) {
                            in_array($log->action, ['created', 'restored', 'login']) => 'success',
                            in_array($log->action, ['updated', 'graded', 'attendance_marked', 'exported']) => 'primary',
                            in_array($log->action, ['deleted', 'force_deleted', 'failed_login']) => 'danger',
                            default => 'neutral',
                        }">{{ str_replace('_', ' ', $log->action) }}</x-badge>
                    </td>
                    <td class="td font-mono text-xs text-on-surface-variant">{{ str_replace('_', ' ', $log->module) }}</td>
                    <td class="td font-mono text-xs text-outline">{{ $log->record_id ? '#'.$log->record_id : '—' }}</td>
                    <td class="td max-w-[16rem]">
                        <p class="truncate font-mono text-xs text-on-surface-variant" title="{{ $log->url }}">
                            <span class="text-primary">{{ $log->http_method }}</span> {{ $log->url ? parse_url($log->url, PHP_URL_PATH) : '—' }}
                        </p>
                        <p class="font-mono text-[10px] text-outline">{{ $log->ip_address }}</p>
                    </td>
                    <td class="td whitespace-nowrap text-xs text-outline">{{ collect([$log->browser, $log->os, $log->device])->filter()->implode(' · ') ?: '—' }}</td>
                </tr>

                {{-- Detail modal --}}
                <template x-teleport="body">
                    <div x-show="detail === {{ $log->id }}" x-cloak class="fixed inset-0 z-50 overflow-y-auto p-4"
                        x-on:keydown.escape.window="detail = null">
                        <div class="fixed inset-0 bg-primary-deep/20 backdrop-blur-[2px]" x-on:click="detail = null"></div>
                        <div class="relative mx-auto mt-10 w-full max-w-3xl rounded-2xl bg-white p-6 shadow-elevated">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="eyebrow">Audit entry #{{ $log->id }}</p>
                                    <h3 class="mt-1 font-display text-xl font-bold text-on-surface">
                                        {{ str_replace('_', ' ', ucfirst($log->action)) }} · {{ str_replace('_', ' ', $log->module) }}{{ $log->record_id ? ' #'.$log->record_id : '' }}
                                    </h3>
                                    <p class="mt-1 text-sm text-on-surface-variant">
                                        {{ $log->user_name }}{{ $log->user_role ? ' ('.$log->user_role.')' : '' }} · {{ $log->created_at->format('j M Y · H:i:s') }}
                                    </p>
                                </div>
                                <button type="button" x-on:click="detail = null" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-surface-ice" aria-label="Close">
                                    <x-icon name="x-mark" class="size-5" />
                                </button>
                            </div>

                            <dl class="mt-5 grid gap-x-6 gap-y-3 rounded-xl bg-surface-ice/70 p-4 font-mono text-xs sm:grid-cols-2">
                                <div><dt class="text-outline">URL</dt><dd class="mt-0.5 break-all text-on-surface-variant">{{ $log->http_method }} {{ $log->url ?? '—' }}</dd></div>
                                <div><dt class="text-outline">IP</dt><dd class="mt-0.5 text-on-surface-variant">{{ $log->ip_address ?? '—' }}</dd></div>
                                <div><dt class="text-outline">Browser / OS</dt><dd class="mt-0.5 text-on-surface-variant">{{ collect([$log->browser, $log->os])->filter()->implode(' · ') ?: '—' }}</dd></div>
                                <div><dt class="text-outline">Device</dt><dd class="mt-0.5 text-on-surface-variant">{{ $log->device ?? '—' }}</dd></div>
                            </dl>

                            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                                <div>
                                    <p class="eyebrow mb-2">Old values</p>
                                    <pre class="scroll-thin max-h-72 overflow-auto rounded-xl bg-error-container/40 p-4 font-mono text-xs leading-5 text-on-surface">{{ $log->old_values ? json_encode($log->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '—' }}</pre>
                                </div>
                                <div>
                                    <p class="eyebrow mb-2">New values</p>
                                    <pre class="scroll-thin max-h-72 overflow-auto rounded-xl bg-success-container/40 p-4 font-mono text-xs leading-5 text-on-surface">{{ $log->new_values ? json_encode($log->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '—' }}</pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            @empty
                <tr><td colspan="7"><x-empty-state icon="audit" title="No audit entries" description="System activity will appear here as it happens." /></td></tr>
            @endforelse
        </tbody>
        @if ($logs->hasPages())
            <x-slot:footer><div data-pagination>{{ $logs->links() }}</div></x-slot:footer>
        @endif
    </x-table>
</div>
