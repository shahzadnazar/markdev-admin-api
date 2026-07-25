<x-admin.layout :title="$student->name">
    <x-page-header eyebrow="People" :title="$student->name"
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Students' => route('admin.students.index'), $student->name => null]"
        :description="$profile ? 'Reg # '.$profile->reg_no.($profile->applied_course ? ' · applied for '.$profile->applied_course : '') : 'No admission record on file yet.'">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.students.index')">
                <x-icon name="arrow-left" class="size-4" /> All students
            </x-btn>
            @can('students.update')
                <x-btn variant="secondary" :href="route('admin.students.edit', $student)">
                    <x-icon name="pencil" class="size-4" /> Edit
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-6 grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-widget label="Enrollments" :value="number_format($student->enrollments->count())" icon="tag" tone="primary" />
        <x-stat-widget label="Fees paid" :value="'Rs '.number_format($fees['paid'])" icon="banknotes" tone="success"
            :sub="$fees['invoices'].' invoices total'" />
        <x-stat-widget label="Outstanding" :value="'Rs '.number_format($fees['outstanding'])" icon="clock"
            :tone="$fees['outstanding'] > 0 ? 'warning' : 'success'" />
        <x-stat-widget label="Status" :value="$student->is_active ? 'Active' : 'Inactive'" icon="check"
            :tone="$student->is_active ? 'success' : 'danger'"
            :sub="'joined '.($profile?->date_of_joining ?? $student->created_at)->format('M j, Y')" />
    </div>

    <div class="grid items-start gap-6 xl:grid-cols-[1fr_24rem]">
        <div class="space-y-6">
            {{-- Identity card --}}
            <x-card>
                <div class="flex items-start gap-5">
                    @if ($student->avatar_url)
                        <img src="{{ $student->avatar_url }}" alt="" class="size-20 shrink-0 rounded-2xl object-cover ring-1 ring-outline/20">
                    @else
                        <span class="flex size-20 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-primary to-secondary font-display text-2xl font-bold text-white">
                            {{ strtoupper(mb_substr($student->name, 0, 1)) }}
                        </span>
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-3">
                            <h2 class="font-display text-lg font-semibold text-on-surface">{{ $student->name }}</h2>
                            @if ($profile)
                                <x-badge variant="primary">{{ $profile->reg_no }}</x-badge>
                            @endif
                        </div>
                        <div class="mt-1 flex flex-wrap gap-x-6 gap-y-1 font-mono text-xs text-outline">
                            <span>{{ $student->email }}</span>
                            @if ($student->phone)<span>{{ $student->phone }}</span>@endif
                        </div>

                        @if ($profile)
                            <dl class="mt-4 grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
                                <div class="flex justify-between gap-4 border-b border-surface-ice pb-2">
                                    <dt class="text-on-surface-variant">Father name</dt>
                                    <dd class="text-right font-medium text-on-surface">{{ $profile->father_name ?? '—' }}</dd>
                                </div>
                                <div class="flex justify-between gap-4 border-b border-surface-ice pb-2">
                                    <dt class="text-on-surface-variant">Date of birth</dt>
                                    <dd class="text-right font-medium text-on-surface">{{ $profile->date_of_birth?->format('M j, Y') ?? '—' }}</dd>
                                </div>
                                <div class="flex justify-between gap-4 border-b border-surface-ice pb-2">
                                    <dt class="text-on-surface-variant">Gender</dt>
                                    <dd class="text-right font-medium capitalize text-on-surface">{{ $profile->gender ?? '—' }}</dd>
                                </div>
                                <div class="flex justify-between gap-4 border-b border-surface-ice pb-2">
                                    <dt class="text-on-surface-variant">CNIC / B-Form</dt>
                                    <dd class="text-right font-mono text-xs font-medium text-on-surface">{{ $profile->cnic ?? '—' }}</dd>
                                </div>
                                <div class="flex justify-between gap-4 border-b border-surface-ice pb-2">
                                    <dt class="text-on-surface-variant">Guardian contact</dt>
                                    <dd class="text-right font-mono text-xs font-medium text-on-surface">{{ $profile->guardian_contact ?? '—' }}</dd>
                                </div>
                                <div class="flex justify-between gap-4 border-b border-surface-ice pb-2">
                                    <dt class="text-on-surface-variant">Qualification</dt>
                                    <dd class="text-right font-medium text-on-surface">{{ $profile->current_qualification ?? '—' }}</dd>
                                </div>
                                <div class="flex justify-between gap-4 border-b border-surface-ice pb-2 sm:col-span-2">
                                    <dt class="text-on-surface-variant">Address</dt>
                                    <dd class="text-right font-medium text-on-surface">{{ $profile->address ?? '—' }}</dd>
                                </div>
                            </dl>
                        @else
                            <p class="mt-4 rounded-lg bg-warning/10 px-4 py-3 text-sm text-on-surface">
                                This student was created before the registration module.
                                @can('students.update')
                                    <a href="{{ route('admin.students.edit', $student) }}" class="font-medium text-primary hover:underline">Complete their admission record →</a>
                                @endcan
                            </p>
                        @endif
                    </div>
                </div>
            </x-card>

            {{-- Enrollments --}}
            <x-table>
                <thead class="bg-surface-ice/60">
                    <tr>
                        <th class="th">Course</th>
                        <th class="th">Level</th>
                        <th class="th">Enrolled</th>
                        <th class="th">Progress</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($student->enrollments as $enrollment)
                        <tr class="row">
                            <td class="td max-w-[20rem]"><p class="truncate font-medium text-on-surface">{{ $enrollment->course?->title ?? '—' }}</p></td>
                            <td class="td"><x-badge variant="neutral">{{ $enrollment->course?->level ?? '—' }}</x-badge></td>
                            <td class="td font-mono text-xs text-on-surface-variant">{{ $enrollment->enrolled_at?->format('M j, Y') }}</td>
                            <td class="td">
                                <div class="flex items-center gap-2.5">
                                    <div class="h-1.5 w-24 overflow-hidden rounded-full bg-surface-ice">
                                        <div class="h-full rounded-full bg-gradient-to-r from-primary to-secondary" style="width: {{ (int) $enrollment->progress_percent }}%"></div>
                                    </div>
                                    <span class="font-mono text-[11px] text-on-surface-variant">{{ (int) $enrollment->progress_percent }}%</span>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-empty-state icon="tag" title="No enrollments"
                            description="Enroll this student from Learning → Enrollments." /></td></tr>
                    @endforelse
                </tbody>
            </x-table>

            {{-- Emergency contact + office record --}}
            @if ($profile)
                <div class="grid gap-6 lg:grid-cols-2">
                    <x-card>
                        <div class="mb-4 flex items-center gap-2.5">
                            <x-icon name="lifebuoy" class="size-4 text-primary" />
                            <h2 class="font-mono text-label-md uppercase text-on-surface">Emergency contact</h2>
                        </div>
                        <dl class="space-y-3 text-sm">
                            @foreach ([
                                'Name' => $profile->emergency_name,
                                'Contact' => $profile->emergency_contact,
                                'Relation' => $profile->emergency_relation,
                                'Residence' => $profile->emergency_residence,
                            ] as $label => $value)
                                <div class="flex justify-between gap-4 border-b border-surface-ice pb-2 last:border-0">
                                    <dt class="text-on-surface-variant">{{ $label }}</dt>
                                    <dd class="text-right font-medium text-on-surface">{{ $value ?? '—' }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </x-card>

                    <x-card>
                        <div class="mb-4 flex items-center gap-2.5">
                            <x-icon name="banknotes" class="size-4 text-primary" />
                            <h2 class="font-mono text-label-md uppercase text-on-surface">Admission record</h2>
                        </div>
                        <dl class="space-y-3 text-sm">
                            @foreach ([
                                'Date of joining' => $profile->date_of_joining?->format('M j, Y'),
                                'Total fee' => $profile->total_fee !== null ? 'Rs '.number_format((float) $profile->total_fee) : null,
                                'Submitted fee' => $profile->submitted_fee !== null ? 'Rs '.number_format((float) $profile->submitted_fee) : null,
                                'Registration fee' => $profile->registration_fee !== null ? 'Rs '.number_format((float) $profile->registration_fee) : null,
                                'Reference' => $profile->reference,
                            ] as $label => $value)
                                <div class="flex justify-between gap-4 border-b border-surface-ice pb-2 last:border-0">
                                    <dt class="text-on-surface-variant">{{ $label }}</dt>
                                    <dd class="text-right font-medium text-on-surface">{{ $value ?? '—' }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </x-card>
                </div>
            @endif
        </div>

        {{-- Documents --}}
        <x-card class="self-start">
            <div class="mb-4 flex items-center gap-2.5">
                <x-icon name="document" class="size-4 text-primary" />
                <h2 class="font-mono text-label-md uppercase text-on-surface">Documents</h2>
            </div>

            @php
                $documents = [
                    ['label' => 'Profile picture', 'path' => $profile?->photo_path],
                    ['label' => 'CNIC / B-Form copy', 'path' => $profile?->cnic_doc_path],
                    ['label' => 'Last degree / certificate', 'path' => $profile?->degree_doc_path],
                ];
            @endphp

            <div class="space-y-4">
                @foreach ($documents as $document)
                    <div class="overflow-hidden rounded-xl border border-outline/15">
                        <div class="flex items-center justify-between gap-3 bg-surface-ice/60 px-4 py-2.5">
                            <p class="text-[13px] font-medium text-on-surface">{{ $document['label'] }}</p>
                            @if ($document['path'])
                                <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($document['path']) }}" target="_blank"
                                    class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                                    <x-icon name="external" class="size-3.5" /> Open
                                </a>
                            @else
                                <x-badge variant="warning">missing</x-badge>
                            @endif
                        </div>
                        @if ($document['path'] && \App\Models\StudentProfile::isImagePath($document['path']))
                            <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($document['path']) }}" target="_blank">
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($document['path']) }}" alt="{{ $document['label'] }}"
                                    class="max-h-56 w-full object-cover transition hover:opacity-90">
                            </a>
                        @elseif ($document['path'])
                            <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($document['path']) }}" target="_blank"
                                class="flex items-center gap-3 px-4 py-4 transition hover:bg-surface-ice/40">
                                <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-error/10 font-mono text-[11px] font-bold text-error">PDF</span>
                                <span class="text-sm text-on-surface-variant">Open document in a new tab</span>
                            </a>
                        @else
                            <p class="px-4 py-4 text-xs text-outline">Not uploaded yet — add it from the edit screen.</p>
                        @endif
                    </div>
                @endforeach
            </div>

            @if ($profile)
                <div class="mt-5 border-t border-surface-ice pt-4 text-xs text-outline">
                    <p>Terms accepted {{ $profile->terms_accepted_at?->format('M j, Y · g:i A') ?? '—' }}</p>
                    <p class="mt-1">Registered by {{ $profile->registrar?->name ?? 'system' }} on {{ $profile->created_at->format('M j, Y') }}</p>
                </div>
            @endif
        </x-card>
    </div>
</x-admin.layout>
