<x-admin.layout :title="'Submissions — '.$assignment->title">
    <x-page-header
        eyebrow="Grading"
        :title="$assignment->title"
        :description="($assignment->course?->title ?? '').' · max score '.$assignment->max_score.($assignment->due_at ? ' · due '.$assignment->due_at->format('M j, Y H:i') : '')"
    >
        <x-slot:actions>
            @can('assignments.update')
                <x-btn variant="secondary" :href="route('admin.assignments.edit', $assignment)">
                    <x-icon name="pencil" class="size-4" /> Edit assignment
                </x-btn>
            @endcan
            <x-btn variant="ghost" :href="route('admin.assignments.index')">
                <x-icon name="arrow-left" class="size-4" /> Back
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <div class="space-y-4">
        @forelse ($submissions as $submission)
            <x-card :padding="false" x-data="{ grading: {{ $errors->any() && old('_submission') == $submission->id ? 'true' : 'false' }} }">
                <div class="flex flex-wrap items-center gap-3 px-6 py-4">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary to-secondary font-display text-sm font-semibold text-white">
                        {{ strtoupper(mb_substr($submission->user?->name ?? '?', 0, 1)) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-on-surface">{{ $submission->user?->name ?? 'Deleted user' }}</p>
                        <p class="flex flex-wrap items-center gap-x-2 text-xs text-outline">
                            <span class="font-mono">{{ $submission->submitted_at?->format('M j, Y H:i') ?? 'not submitted' }}</span>
                            @if ($submission->is_late)
                                <x-badge variant="danger">late</x-badge>
                            @endif
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        @if ($submission->graded_at)
                            <div class="text-right">
                                <p class="font-mono text-lg font-semibold text-on-surface">{{ $submission->score }}<span class="text-xs text-outline">/{{ $assignment->max_score }}</span></p>
                                <p class="text-[11px] text-outline">by {{ $submission->grader?->name ?? '—' }} · {{ $submission->graded_at->diffForHumans() }}</p>
                            </div>
                            <x-badge variant="success">graded</x-badge>
                        @elseif ($submission->returned_at)
                            <x-badge variant="warning">returned for changes</x-badge>
                        @else
                            @if ($submission->feedback)
                                <x-badge variant="primary">resubmitted</x-badge>
                            @endif
                            <x-badge variant="warning">awaiting grade</x-badge>
                        @endif
                        @can('assignments.grade')
                            <x-btn type="button" size="sm" variant="secondary" x-on:click="grading = ! grading">
                                <x-icon name="pencil" class="size-3.5" />
                                {{ $submission->graded_at ? 'Regrade' : 'Grade' }}
                            </x-btn>
                        @endcan
                    </div>
                </div>

                <div class="border-t border-surface-ice px-6 py-4">
                    @if ($submission->content)
                        <p class="text-sm leading-6 text-on-surface-variant">{{ $submission->content }}</p>
                    @endif
                    @if ($submission->file_path)
                        <a href="{{ $submission->file_url }}" target="_blank" class="mt-2 inline-flex items-center gap-2 text-sm font-medium text-primary hover:underline">
                            <x-icon name="download" class="size-4" /> {{ $submission->file_name ?? 'Download attachment' }}
                        </a>
                    @endif
                    @if ($submission->query)
                        {{-- Student's question about the assignment — highlighted so it isn't missed while grading. --}}
                        <div class="mt-3 rounded-xl border border-warning/30 bg-warning/5 p-4">
                            <p class="font-mono text-[10px] font-medium uppercase tracking-[0.14em] text-outline">Student query</p>
                            <p class="mt-1 whitespace-pre-line text-sm leading-6 text-on-surface-variant">{{ $submission->query }}</p>
                        </div>
                    @endif
                    @if ($submission->feedback)
                        <div class="mt-3 rounded-xl bg-surface-ice/70 p-4">
                            <p class="font-mono text-[10px] font-medium uppercase tracking-[0.14em] text-outline">Feedback</p>
                            <p class="mt-1 text-sm leading-6 text-on-surface-variant">{{ $submission->feedback }}</p>
                        </div>
                    @endif
                </div>

                @can('assignments.grade')
                    <div x-show="grading" x-cloak class="border-t border-surface-ice bg-surface-ice/50 px-6 py-4">
                        <form method="POST" action="{{ route('admin.submissions.grade', $submission) }}" class="flex flex-wrap items-end gap-4">
                            @csrf
                            <input type="hidden" name="_submission" value="{{ $submission->id }}">
                            <div class="w-28">
                                <x-form.label :for="'score-'.$submission->id" value="Score" />
                                <input type="number" name="score" id="score-{{ $submission->id }}" min="0" max="{{ $assignment->max_score }}"
                                    value="{{ old('_submission') == $submission->id ? old('score') : $submission->score }}" required class="field">
                            </div>
                            <div class="min-w-64 flex-1">
                                <x-form.label :for="'feedback-'.$submission->id" value="Feedback" />
                                <textarea name="feedback" id="feedback-{{ $submission->id }}" rows="2" class="field" placeholder="What went well, what to improve…">{{ old('_submission') == $submission->id ? old('feedback') : $submission->feedback }}</textarea>
                            </div>
                            <div class="flex items-center gap-2 pb-0.5">
                                <x-btn size="sm">
                                    <x-icon name="check" class="size-3.5" /> Save grade
                                </x-btn>
                                <x-btn size="sm" variant="warning" formaction="{{ route('admin.submissions.return', $submission) }}" formnovalidate
                                    title="Send back with feedback so the student can resubmit — clears any grade">
                                    <x-icon name="arrow-left" class="size-3.5" /> Return for changes
                                </x-btn>
                                <x-btn type="button" size="sm" variant="ghost" x-on:click="grading = false">Cancel</x-btn>
                            </div>
                            @if (old('_submission') == $submission->id)
                                <div class="w-full">
                                    <x-form.error name="score" />
                                    <x-form.error name="feedback" />
                                </div>
                            @endif
                        </form>
                    </div>
                @endcan
            </x-card>
        @empty
            <x-card>
                <x-empty-state icon="inbox" title="No submissions yet" description="Student submissions will land here, ready for grading." class="py-10" />
            </x-card>
        @endforelse

        @if ($submissions->hasPages())
            <x-card :padding="false" class="p-4">
                {{ $submissions->links() }}
            </x-card>
        @endif
    </div>
</x-admin.layout>
