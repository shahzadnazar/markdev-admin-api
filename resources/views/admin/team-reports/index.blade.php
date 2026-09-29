{{-- The four team reports. What a viewer may produce is decided in
     TeamReportController's report map and again inside each export's query —
     an export is a query and not a screen, so the gate cannot live here. --}}
<x-admin.layout title="Team reports">
    <x-page-header eyebrow="Team" title="Reports"
        description="Spreadsheets about the work, scoped to what you can already see."
        :crumbs="['Dashboard' => \App\Support\PortalHome::url(), 'Reports' => null]">
        <x-slot:actions>
            <form method="GET" action="{{ route('admin.team-reports.index') }}" class="flex items-center gap-2">
                <label for="month" class="sr-only">Month</label>
                <input type="month" id="month" name="month" value="{{ $month->format('Y-m') }}"
                    class="rounded-lg border border-outline-variant/70 bg-white px-3 py-2 text-[13px] text-on-surface focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/15">
                <x-btn variant="secondary" size="sm" type="submit">Set month</x-btn>
            </form>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($reports as $key => $report)
            <x-card>
                <div class="flex h-full flex-col">
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="font-display text-[15px] font-semibold text-on-surface">{{ $report['label'] }}</h2>
                        @if ($report['dated'])
                            <x-badge>{{ $month->format('M Y') }}</x-badge>
                        @else
                            <x-badge variant="neutral">All time</x-badge>
                        @endif
                    </div>

                    <p class="mt-1.5 flex-1 text-[13px] leading-5 text-on-surface-variant">{{ $report['description'] }}</p>

                    <div class="mt-4">
                        @can('team-reports.export')
                            <x-btn variant="secondary" size="sm"
                                :href="route('admin.team-reports.export', ['report' => $key, 'month' => $month->format('Y-m')])">
                                <x-icon name="download" class="size-4" /> Export .xlsx
                            </x-btn>
                        @else
                            {{-- Offered to nobody who would be refused: `.view`
                                 and `.export` are separate permissions, so a
                                 role can be granted the list without the file. --}}
                            <p class="font-mono text-[11px] text-outline">You can see this report's description, not its file.</p>
                        @endcan
                    </div>
                </div>
            </x-card>
        @endforeach
    </div>
</x-admin.layout>
