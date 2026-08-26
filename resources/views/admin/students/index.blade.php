<x-admin.layout title="Students">
    <x-page-header eyebrow="People" title="Student management"
        description="Every registered student — admissions, documents, enrollments and status.">
        <x-slot:actions>
            @can('students.create')
                <x-btn :href="route('admin.students.create')">
                    <x-icon name="user-plus" class="size-4" /> Register student
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    {{-- Cohort stats --}}
    <div class="mb-6 grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-widget label="Total students" :value="number_format($totals['students'])" icon="users" tone="primary" />
        <x-stat-widget label="Active" :value="number_format($totals['active'])"
            :sub="$totals['students'] - $totals['active'].' inactive'" icon="check" tone="success" />
        <x-stat-widget label="New this month" :value="number_format($totals['new_month'])" icon="user-plus" tone="secondary" />
        <x-stat-widget label="Course enrollments" :value="number_format($totals['enrollments'])" icon="tag" tone="primary" />
    </div>


    @php
        // Everything except `status`, so the tabs keep the admin's filters.
        $carry = array_filter([
            'search' => $filters['search'],
            'name' => $filters['name'],
            'gender' => $filters['gender'],
            'joined_from' => $filters['joined_from'],
            'joined_to' => $filters['joined_to'],
            'course' => request('course'),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
        $hasFilters = $carry !== [];
    @endphp

    {{-- Filters --}}
    <div class="mb-6 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-4">
            @if ($trashed)
                <div class="flex items-center gap-2.5 rounded-lg border border-error/20 bg-error-container/40 px-4 py-2.5">
                    <x-icon name="trash" class="size-4 shrink-0 text-error" />
                    <p class="text-sm text-on-surface-variant">
                        {{ number_format($students->total()) }} removed {{ \Illuminate\Support\Str::plural('student', $students->total()) }}
                        — restore them or remove permanently.
                    </p>
                </div>
            @else
                <div class="inline-flex rounded-lg bg-white p-1 shadow-card">
                    @php
                        $tabCounts = [null => $totals['students'], 'active' => $totals['active'], 'inactive' => $totals['students'] - $totals['active']];
                    @endphp
                    @foreach (['' => 'All', 'active' => 'Active', 'inactive' => 'Inactive'] as $key => $label)
                        @php $isActive = ($status ?? '') === $key; @endphp
                        <a href="{{ route('admin.students.index', array_filter(['status' => $key]) + $carry) }}"
                            class="inline-flex items-center gap-1.5 rounded-md px-3.5 py-2 text-sm font-medium transition {{ $isActive ? 'bg-primary text-white shadow-card' : 'text-on-surface-variant hover:text-on-surface' }}">
                            {{ $label }}
                            <span class="rounded-full px-1.5 py-0.5 font-mono text-[10px] leading-none {{ $isActive ? 'bg-white/20 text-white' : 'bg-surface-ice text-on-surface-variant' }}">{{ number_format($tabCounts[$key]) }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        <form id="student-filters" method="GET" action="{{ route('admin.students.index') }}"
            class="rounded-2xl bg-white p-4 shadow-card">
            @if ($status)
                <input type="hidden" name="status" value="{{ $status }}">
            @endif

            {{-- Row 1 — free-text search across name, email, phone, reg #, CNIC, father name --}}
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-64 flex-1">
                    <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-outline" />
                    <input type="search" name="search" value="{{ $filters['search'] }}"
                        placeholder="Search name, email, phone, reg # or CNIC…"
                        class="field w-full pl-9" autocomplete="off" data-live>
                </div>

                <select name="course" class="field w-52" data-live>
                    <option value="">All courses</option>
                    @foreach ($courses as $course)
                        <option value="{{ $course->id }}" @selected(request('course') == $course->id)>{{ $course->title }}</option>
                    @endforeach
                </select>

                @can('students.delete')
                    <label class="flex h-[42px] cursor-pointer items-center gap-2 rounded-lg border border-outline-variant px-3">
                        <input type="checkbox" name="trashed" value="1" @checked($trashed) class="check" data-live>
                        <span class="text-sm text-on-surface-variant">Trash box</span>
                    </label>
                @endcan

                <a href="{{ route('admin.students.index') }}" data-clear
                    class="flex h-[42px] items-center rounded-lg px-3 text-sm font-medium text-on-surface-variant transition hover:bg-surface-ice hover:text-on-surface {{ $hasFilters ? '' : 'pointer-events-none opacity-40' }}">
                    Clear all
                </a>
            </div>

            {{-- Row 2 — each filter narrows the list on its own and combines with the rest --}}
            <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-3 border-t border-surface-ice pt-3">
                {{-- Name: typing ticks the box --}}
                <div class="flex items-center gap-2">
                    <input type="checkbox" class="check" data-toggle="name"
                        @checked($filters['name'] !== '') aria-label="Filter by name">
                    <label class="text-sm font-medium text-on-surface-variant" for="filter-name">Name</label>
                    <input id="filter-name" type="text" name="name" value="{{ $filters['name'] }}"
                        placeholder="Student name…" class="field w-48" autocomplete="off"
                        data-group="name" data-live>
                </div>

                {{-- Gender: ticking an option filters to it; tick both to see both --}}
                <div class="flex items-center gap-2">
                    <span class="text-sm font-medium text-on-surface-variant">Gender</span>
                    @foreach (['male' => 'Male', 'female' => 'Female'] as $value => $label)
                        <label class="flex cursor-pointer items-center gap-1.5 rounded-lg border border-outline-variant px-2.5 py-1.5">
                            <input type="checkbox" name="gender[]" value="{{ $value }}"
                                @checked(in_array($value, $filters['gender'], true)) class="check" data-live>
                            <span class="text-sm text-on-surface-variant">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>

                {{-- Joined date: same date in both boxes matches that single day --}}
                <div class="flex flex-wrap items-center gap-2">
                    <input type="checkbox" class="check" data-toggle="joined"
                        @checked($filters['joined_from'] || $filters['joined_to']) aria-label="Filter by joined date">
                    <span class="text-sm font-medium text-on-surface-variant">Joined date</span>
                    <label class="text-sm text-outline" for="filter-joined-from">From</label>
                    <input id="filter-joined-from" type="date" name="joined_from" value="{{ $filters['joined_from'] }}"
                        class="field w-40" data-group="joined" data-live>
                    <label class="text-sm text-outline" for="filter-joined-to">To</label>
                    <input id="filter-joined-to" type="date" name="joined_to" value="{{ $filters['joined_to'] }}"
                        class="field w-40" data-group="joined" data-live>
                </div>

                {{-- Live search handles submission; this is the no-JS fallback. --}}
                <noscript><x-btn variant="secondary" size="md">Apply</x-btn></noscript>
            </div>
        </form>
    </div>

    <div id="student-results" class="transition-opacity duration-150">
        @include('admin.students._results')
    </div>
    <script>
        (() => {
            const form = document.getElementById('student-filters');
            const results = document.getElementById('student-results');
            if (!form || !results) return;

            const base = new URL(form.action, window.location.origin);
            let timer = null;
            let inflight = null;

            const inputsOf = (group) => form.querySelectorAll(`[data-group="${group}"]`);

            /* A group's box reflects whether that filter actually holds a value. */
            const syncToggle = (group) => {
                const box = form.querySelector(`[data-toggle="${group}"]`);
                if (box) box.checked = [...inputsOf(group)].some((el) => el.value.trim() !== '');
            };

            const clearLink = form.querySelector('[data-clear]');

            /* Only the results table is swapped, so the chrome around it has to be
               kept in step by hand — otherwise "Clear all" stays greyed out while
               filters are plainly active. */
            const syncChrome = () => {
                if (!clearLink) return;
                const active = [...new FormData(form)].some(([key, value]) =>
                    key !== 'status' && typeof value === 'string' && value.trim() !== '');
                clearLink.classList.toggle('pointer-events-none', !active);
                clearLink.classList.toggle('opacity-40', !active);
            };

            const buildUrl = (extra) => {
                const params = new URLSearchParams();
                for (const [key, value] of new FormData(form)) {
                    if (typeof value === 'string' && value.trim() !== '') params.append(key, value);
                }
                if (extra) params.set(extra[0], extra[1]);
                const url = new URL(base);
                url.search = params.toString();
                return url;
            };

            const render = (pageUrl) => {
                /* Only the table is re-fetched, so the page never reloads and the
                   caret stays exactly where the admin left it. */
                const target = pageUrl ? new URL(pageUrl) : buildUrl();
                const fetchUrl = new URL(target);
                fetchUrl.searchParams.set('partial', '1');

                if (inflight) inflight.abort();
                inflight = new AbortController();
                results.classList.add('opacity-50');

                fetch(fetchUrl, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    signal: inflight.signal,
                })
                    .then((response) => {
                        if (!response.ok) throw new Error(response.status);
                        return response.text();
                    })
                    .then((html) => {
                        results.innerHTML = html;
                        window.history.replaceState(null, '', target);
                    })
                    .catch((error) => {
                        /* A superseded keystroke is not a failure; anything else
                           falls back to a normal page load so nothing is lost. */
                        if (error.name !== 'AbortError') form.submit();
                    })
                    .finally(() => {
                        results.classList.remove('opacity-50');
                        syncChrome();
                    });
            };

            const debounce = () => {
                syncChrome();
                clearTimeout(timer);
                timer = setTimeout(render, 300);
            };

            form.addEventListener('input', (event) => {
                if (!event.target.matches('[data-live]')) return;
                if (event.target.dataset.group) syncToggle(event.target.dataset.group);
                debounce();
            });

            form.addEventListener('change', (event) => {
                const group = event.target.dataset.toggle;
                if (group && !event.target.checked) {
                    inputsOf(group).forEach((el) => { el.value = ''; });
                } else if (group) {
                    return; /* ticking an empty box filters nothing yet */
                } else if (!event.target.matches('[data-live]')) {
                    return;
                }
                clearTimeout(timer);
                render();
            });

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                clearTimeout(timer);
                render();
            });

            form.querySelector('[data-clear]')?.addEventListener('click', (event) => {
                event.preventDefault();
                form.reset();
                form.querySelectorAll('[data-live]').forEach((el) => {
                    if (el.type === 'checkbox') el.checked = false;
                    else el.value = '';
                });
                form.querySelectorAll('[data-toggle]').forEach((el) => { el.checked = false; });
                clearTimeout(timer);
                render();
            });

            /* Pagination is inside the swapped markup, so delegate from the container. */
            results.addEventListener('click', (event) => {
                const link = event.target.closest('[data-pagination] a[href]');
                if (!link) return;
                event.preventDefault();
                render(link.href);
            });
        })();
    </script>
</x-admin.layout>
