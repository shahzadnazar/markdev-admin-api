<x-admin.layout title="Reports">
    <x-page-header eyebrow="System" title="Reports" description="Download platform data as spreadsheets (XLSX).">
    </x-page-header>

    <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($reports as $key => $report)
            <x-card class="flex flex-col">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex size-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
                        <x-icon name="chart" class="size-5" />
                    </div>
                    <span class="font-mono text-[11px] uppercase tracking-[0.1em] text-outline">{{ number_format($counts[$key] ?? 0) }} rows</span>
                </div>
                <h2 class="mt-4 font-display text-lg font-semibold text-on-surface">{{ $report['label'] }}</h2>
                <p class="mt-1 flex-1 text-sm leading-6 text-on-surface-variant">{{ $report['description'] }}</p>
                @can('reports.export')
                    <div class="mt-5">
                        <x-btn variant="secondary" :href="route('admin.reports.export', $key)">
                            <x-icon name="download" class="size-4" /> Export XLSX
                        </x-btn>
                    </div>
                @endcan
            </x-card>
        @endforeach
    </div>
</x-admin.layout>
