<aside class="admin-sidebar flex h-full w-[280px] flex-col border-r border-primary/10 bg-white">
    {{-- Brand --}}
    <div class="sidebar-brand flex items-center gap-3 px-6 pb-6 pt-7">
        <x-brand-mark class="size-10 shrink-0" gradient-id="sidebar" />
        <div class="sidebar-brand-text min-w-0 leading-tight">
            <p class="font-display text-lg font-bold tracking-[-0.01em] text-on-surface">MarkDev</p>
            <p class="font-mono text-[10px] font-medium uppercase tracking-[0.2em] text-primary">Admin Portal</p>
        </div>
    </div>

    {{-- Navigation --}}
    <div class="scroll-thin flex-1 overflow-y-auto px-4 pb-6">
        @can('dashboard.view')
        <x-admin.nav-section label="Overview">
            <x-admin.nav-item :href="route('admin.dashboard')" icon="dashboard" :active="request()->routeIs('admin.dashboard')">Dashboard</x-admin.nav-item>
        </x-admin.nav-section>
        @endcan

        @canany(['users.view', 'students.view'])
        <x-admin.nav-section label="People">
            @can('students.view')
            <x-admin.nav-item :href="route('admin.students.index')" icon="user-circle" :active="request()->routeIs('admin.students.*')">Students</x-admin.nav-item>
            @endcan
            @can('users.view')
            <x-admin.nav-item :href="route('admin.instructors.index')" icon="academic-cap" :active="request()->routeIs('admin.instructors.*')">Instructors</x-admin.nav-item>
            <x-admin.nav-item :href="route('admin.users.index')" icon="users" :active="request()->routeIs('admin.users.*')">Staff &amp; Users</x-admin.nav-item>
            @endcan
            @role('super-admin')
            <x-admin.nav-item :href="route('admin.roles.index')" icon="shield" :active="request()->routeIs('admin.roles.*')">Roles &amp; Permissions</x-admin.nav-item>
            @endrole
        </x-admin.nav-section>
        @endcanany

        @canany(['categories.view', 'notes.view', 'courses.view', 'enrollments.view', 'assignments.view', 'quizzes.view', 'attendance.view', 'certificates.view'])
        <x-admin.nav-section label="Learning">
            @can('categories.view')
            <x-admin.nav-item :href="route('admin.categories.index')" icon="tag" :active="request()->routeIs('admin.categories.*')">Categories</x-admin.nav-item>
            @endcan
            @can('courses.view')
            <x-admin.nav-item :href="route('admin.courses.index')" icon="academic-cap" :active="request()->routeIs('admin.courses.*') || request()->routeIs('admin.lessons.*')">Courses</x-admin.nav-item>
            @endcan
            <x-admin.nav-item
                :href="route('admin.notes.index')"
                icon="document"
                :active="request()->routeIs('admin.notes.*')">
                Notes
            </x-admin.nav-item>
            @can('enrollments.view')
            <x-admin.nav-item :href="route('admin.enrollments.index')" icon="user-plus" :active="request()->routeIs('admin.enrollments.*')">Enrollments</x-admin.nav-item>
            @endcan
            @can('assignments.view')
            <x-admin.nav-item :href="route('admin.assignments.index')" icon="clipboard" :active="request()->routeIs('admin.assignments.*') || request()->routeIs('admin.submissions.*')">Assignments</x-admin.nav-item>
            @endcan
            @can('quizzes.view')
            <x-admin.nav-item :href="route('admin.quizzes.index')" icon="quiz" :active="request()->routeIs('admin.quizzes.*') || request()->routeIs('admin.questions.*')">Quizzes</x-admin.nav-item>
            @endcan
            @can('attendance.view')
            @can('attendance.daily')
            <x-admin.nav-item :href="route('admin.attendance.daily')" icon="check" :active="request()->routeIs('admin.attendance.daily')">Daily Attendance</x-admin.nav-item>
            <x-admin.nav-item :href="route('admin.leaves.index')" icon="clipboard" :active="request()->routeIs('admin.leaves.*')">Leave Requests</x-admin.nav-item>
            @endcan
            <x-admin.nav-item :href="route('admin.attendance.index')" icon="calendar" :active="request()->routeIs('admin.attendance.index') || request()->routeIs('admin.attendance.log')">Class Attendance</x-admin.nav-item>
            @endcan
            @can('devices.view')
            <x-admin.nav-item :href="route('admin.biometric.devices')" icon="server" :active="request()->routeIs('admin.biometric.*')">Biometric</x-admin.nav-item>
            @endcan
            @can('certificates.view')
            <x-admin.nav-item :href="route('admin.certificates.index')" icon="certificate" :active="request()->routeIs('admin.certificates.*')">Certificates</x-admin.nav-item>
            @endcan
        </x-admin.nav-section>
        @endcanany

        @canany(['announcements.view', 'help.view'])
        <x-admin.nav-section label="Engagement">
            @can('announcements.view')
            <x-admin.nav-item :href="route('admin.announcements.index')" icon="megaphone" :active="request()->routeIs('admin.announcements.*')">Announcements</x-admin.nav-item>
            @endcan
            @can('help.view')
            <x-admin.nav-item :href="route('admin.help.index')" icon="lifebuoy" :active="request()->routeIs('admin.help.*')">Help Center</x-admin.nav-item>
            @endcan
        </x-admin.nav-section>
        @endcanany

        @can('billing.view')
        <x-admin.nav-section label="Finance">
            @php $pendingFees = rescue(fn () => \App\Models\Transaction::where('submitted_by_student', true)->where('status', 'pending')->count(), 0, false); @endphp
            <x-admin.nav-item :href="route('admin.billing.submissions')" icon="banknotes" :active="request()->routeIs('admin.billing.*') && ! request()->routeIs('admin.billing.payment-methods.*')">
                Billing
                @if ($pendingFees > 0)
                <span class="ml-auto rounded-full bg-warning-container px-2 py-0.5 font-mono text-[10px] font-semibold text-warning">{{ $pendingFees }}</span>
                @endif
            </x-admin.nav-item>
            <x-admin.nav-item :href="route('admin.billing.payment-methods.index')" icon="wallet" :active="request()->routeIs('admin.billing.payment-methods.*')">Payment Methods</x-admin.nav-item>
        </x-admin.nav-section>
        @endcan

        @canany(['audit-logs.view', 'reports.view', 'settings.view', 'media.view'])
        <x-admin.nav-section label="System">
            @can('audit-logs.view')
            <x-admin.nav-item :href="route('admin.audit-logs.index')" icon="audit" :active="request()->routeIs('admin.audit-logs.*')">Audit Logs</x-admin.nav-item>
            @endcan
            @can('reports.view')
            <x-admin.nav-item :href="route('admin.reports.index')" icon="chart" :active="request()->routeIs('admin.reports.*')">Reports</x-admin.nav-item>
            @endcan
            @can('settings.view')
            <x-admin.nav-item :href="route('admin.settings.edit')" icon="cog" :active="request()->routeIs('admin.settings.*')">Settings</x-admin.nav-item>
            @endcan
            @can('media.view')
            <x-admin.nav-item :href="route('admin.media.index')" icon="photo" :active="request()->routeIs('admin.media.*')">Media</x-admin.nav-item>
            @endcan
        </x-admin.nav-section>
        @endcanany
    </div>

    {{-- Current user --}}
    <div class="sidebar-footer border-t border-surface-ice px-6 py-4">
        <div class="flex items-center gap-3">
            <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary to-secondary font-display text-sm font-semibold text-white">
                {{ strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
            </div>
            <div class="sidebar-footer-meta min-w-0 leading-tight">
                <p class="truncate text-[13px] font-semibold text-on-surface">{{ auth()->user()->name }}</p>
                <p class="truncate font-mono text-[10px] uppercase tracking-[0.08em] text-outline">{{ auth()->user()->roles->pluck('name')->implode(', ') ?: 'member' }}</p>
            </div>
        </div>
    </div>
</aside>