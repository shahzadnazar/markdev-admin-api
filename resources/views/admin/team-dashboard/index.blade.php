{{-- ONE screen, three audiences. Every figure is already scoped by the time it
     reaches this file — App\Support\TeamDashboard runs Task::visibleTo,
     Project::visibleTo and the team-membership subquery — so nothing here
     filters anything, and a figure a viewer may not see arrives as NULL rather
     than as a zero they could read as news.

     The cards are x-stat-widget, the same component the academy dashboard uses.
     The score is x-team.delivery-score, which refuses to draw a percentage
     without its counts. Neither is re-implemented here. --}}
<x-admin.layout title="Team dashboard">
    <x-page-header eyebrow="Team" title="Dashboard"
        :description="match ($data['tier']) {
            \App\Support\TeamDashboard::TIER_EVERYTHING => 'Every team, every project.',
            \App\Support\TeamDashboard::TIER_TEAMS => 'The teams you are a member of.',
            default => 'Your work, your attendance and your own figures.',
        }"
        :crumbs="['Dashboard' => \App\Support\PortalHome::url(), 'Team dashboard' => null]">
        <x-slot:meta>
            <span class="rounded-full bg-surface-ice px-2.5 py-0.5 font-mono text-[11px] font-semibold text-on-surface-variant">
                {{ $data['month']->format('F Y') }}
            </span>
        </x-slot:meta>
        <x-slot:actions>
            <div class="flex items-center gap-1">
                <x-btn variant="ghost" size="sm" :href="route('admin.team-dashboard', ['month' => $data['month']->copy()->subMonth()->format('Y-m')])" aria-label="Previous month">
                    <x-icon name="arrow-left" class="size-4" />
                </x-btn>
                <x-btn variant="ghost" size="sm" :href="route('admin.team-dashboard')">This month</x-btn>
                <x-btn variant="ghost" size="sm" :href="route('admin.team-dashboard', ['month' => $data['month']->copy()->addMonth()->format('Y-m')])" aria-label="Next month">
                    <x-icon name="chevron-right" class="size-4" />
                </x-btn>
            </div>
            @can('team-reports.view')
                <x-btn variant="secondary" size="sm" :href="route('admin.team-reports.index')">
                    <x-icon name="chart" class="size-4" /> Reports
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    {{-- ------------------------- Their teams, or all ----------------------- --}}
    @if ($data['projects_running'] !== null)
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stat-widget label="Projects running" :value="$data['projects_running']" icon="clipboard"
                :sub="$teamCount === 1 ? 'Across your team' : 'Across '.$teamCount.' teams'" />

            <x-stat-widget label="Projects overdue" :value="$data['projects_overdue']"
                :tone="$data['projects_overdue'] > 0 ? 'danger' : 'success'" icon="warning"
                sub="Past the due date and still running" />

            <x-stat-widget label="Register today" :value="$data['attendance_today']['marked'].' / '.$data['attendance_today']['people']"
                icon="check" sub="Marked, of the people on your teams" />

            {{-- A COUNT and no link for a lead: reviewing leave is admin work,
                 and a door that answers 403 is the bug this build has spent
                 three phases removing. --}}
            @php $leaveCard = ['label' => 'Leave awaiting a decision', 'value' => $data['leave_pending'], 'icon' => 'inbox']; @endphp
            @can('clients.view')
                <a href="{{ route('admin.team-leave.index') }}" class="block">
                    <x-stat-widget :label="$leaveCard['label']" :value="$leaveCard['value']" :icon="$leaveCard['icon']"
                        :tone="$data['leave_pending'] > 0 ? 'warning' : 'primary'" sub="Open the review queue" />
                </a>
            @endcan
            @cannot('clients.view')
                <x-stat-widget :label="$leaveCard['label']" :value="$leaveCard['value']" :icon="$leaveCard['icon']"
                    :tone="$data['leave_pending'] > 0 ? 'warning' : 'primary'" sub="An administrator reviews these" />
            @endcannot
        </div>
    @endif

    {{-- -------------------------- The admin tier only ---------------------- --}}
    @if ($data['clients_active'] !== null)
        <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stat-widget label="Active clients" :value="$data['clients_active']" icon="user-circle" sub="Available for new projects" />

            <x-stat-widget label="Client questions open" :value="$data['client_questions_open']" icon="megaphone"
                :tone="$data['client_questions_open'] > 0 ? 'warning' : 'primary'" sub="Awaiting a reply from a team lead" />

            {{-- The money. Inside the admin branch, and the figure is not even
                 computed for anyone else — see TeamDashboard::for. --}}
            <x-stat-widget label="Contract value running" :value="number_format($data['contract_value'], 0)" icon="banknotes"
                tone="secondary" sub="Projects still counting days" />

            <x-stat-widget label="Fines outstanding" :value="number_format($data['fines_outstanding'], 0)" icon="banknotes"
                :tone="$data['fines_outstanding'] > 0 ? 'warning' : 'success'" sub="Unsettled across every team" />
        </div>
    @endif

    <div class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div class="space-y-5">
            {{-- ----------------------- Work by status ---------------------- --}}
            <x-card>
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="font-display text-[15px] font-semibold text-on-surface">
                            {{ $data['tier'] === \App\Support\TeamDashboard::TIER_OWN ? 'Your tasks' : 'Tasks by status' }}
                        </h2>
                        <p class="mt-0.5 text-[13px] text-on-surface-variant">
                            {{ $data['tier'] === \App\Support\TeamDashboard::TIER_OWN
                                ? 'The work you hold or have held a stint on.'
                                : 'Top-level tasks only — parts are counted under the task they belong to.' }}
                        </p>
                    </div>
                    @if ($data['tasks_overdue'] > 0)
                        <x-badge variant="danger">{{ $data['tasks_overdue'] }} overdue</x-badge>
                    @endif
                </div>

                <div class="mt-4 space-y-2">
                    @foreach ($data['tasks_by_status'] as $status)
                        <div class="flex items-center justify-between gap-3 rounded-xl border border-outline-variant/60 px-4 py-2.5">
                            <span class="flex min-w-0 items-center gap-2.5 text-sm text-on-surface">
                                <span class="size-2.5 shrink-0 rounded-full ring-1 ring-inset ring-black/10" style="background-color: {{ $status['colour'] }}"></span>
                                {{ $status['label'] }}
                            </span>
                            <span class="font-display text-lg font-bold text-on-surface">{{ $status['count'] }}</span>
                        </div>
                    @endforeach
                </div>
            </x-card>

            {{-- --------------------- Each member's score ------------------- --}}
            @if ($data['member_scores'] !== null)
                <x-card>
                    <h2 class="font-display text-[15px] font-semibold text-on-surface">Delivery</h2>
                    <p class="mt-0.5 text-[13px] text-on-surface-variant">
                        Day-weighted, from the cached figures. Never a percentage without its counts.
                    </p>

                    <div class="mt-4 space-y-3">
                        @forelse ($data['member_scores'] as $member)
                            <div class="rounded-xl border border-outline-variant/60 px-4 py-3">
                                <x-team.delivery-score :score="$member['score']" :name="$member['name']" compact />
                            </div>
                        @empty
                            <p class="text-[13px] text-on-surface-variant">Nobody has finished a stint yet.</p>
                        @endforelse
                    </div>
                </x-card>
            @endif
        </div>

        {{-- ------------------------ About the viewer ----------------------- --}}
        <div class="space-y-5">
            <x-card>
                <h2 class="font-display text-[15px] font-semibold text-on-surface">Your delivery</h2>
                <p class="mt-0.5 text-[13px] text-on-surface-variant">Computed live, not from the cache.</p>
                <div class="mt-3">
                    <x-team.delivery-score :score="$data['my_score']" />
                </div>
            </x-card>

            <x-card>
                <h2 class="font-display text-[15px] font-semibold text-on-surface">Your {{ $data['month']->format('F') }}</h2>

                <dl class="mt-4 space-y-3 text-[13px]">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-outline">Attendance</dt>
                        <dd class="font-display text-lg font-bold text-on-surface">
                            {{ $data['my_attendance']['percent'] === null ? '—' : $data['my_attendance']['percent'].'%' }}
                        </dd>
                    </div>
                    <div>
                        {{-- The counts beside the percentage, for the same reason
                             the delivery score carries its own: a weighted figure
                             with nothing behind it is a number to argue about. --}}
                        <dl class="flex flex-wrap gap-x-3 gap-y-1 font-mono text-[11px] text-outline">
                            @foreach (\App\Models\TeamAttendance::STATUSES as $status)
                                <div class="flex items-center gap-1">
                                    <dt class="sr-only">{{ $status }}</dt>
                                    <dd><span class="font-semibold text-on-surface-variant">{{ $data['my_attendance']['counts'][$status] ?? 0 }}</span> {{ $status }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>

                    <div class="flex items-baseline justify-between gap-3 border-t border-surface-ice pt-3">
                        <dt class="text-outline">Leave remaining</dt>
                        <dd class="font-display text-lg font-bold text-on-surface">
                            {{ $data['my_leave']['remaining'] }} <span class="font-mono text-[11px] font-medium text-outline">of {{ $data['my_leave']['allowance'] }}</span>
                        </dd>
                    </div>

                    {{-- THEIR OWN money, on every tier. A colleague's never
                         appears below the admin tier, and the all-teams figure
                         is a separate card inside the admin branch above. --}}
                    <div class="flex items-baseline justify-between gap-3 border-t border-surface-ice pt-3">
                        <dt class="text-outline">You owe</dt>
                        <dd class="font-display text-lg font-bold {{ $data['my_fines_owed'] > 0 ? 'text-warning' : 'text-on-surface' }}">
                            {{ number_format($data['my_fines_owed'], 0) }}
                        </dd>
                    </div>
                </dl>

                <div class="mt-4 flex flex-wrap gap-2">
                    <x-btn variant="ghost" size="sm" :href="route('admin.team-attendance.mine')">My attendance</x-btn>
                    <x-btn variant="ghost" size="sm" :href="route('admin.team-leave.mine')">My leave</x-btn>
                    <x-btn variant="ghost" size="sm" :href="route('admin.team-fines.mine')">My fines</x-btn>
                </div>
            </x-card>
        </div>
    </div>
</x-admin.layout>
