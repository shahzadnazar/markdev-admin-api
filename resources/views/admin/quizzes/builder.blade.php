<x-admin.layout :title="'Quiz builder — '.$quiz->title">
    <x-page-header
        eyebrow="Learning"
        :title="$quiz->title"
        :description="$quiz->course?->title.($quiz->lesson ? ' · '.$quiz->lesson->title : '')"
    >
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.quizzes.index')">
                <x-icon name="arrow-left" class="size-4" /> All quizzes
            </x-btn>
            <x-btn variant="secondary" :href="route('admin.quizzes.attempts', $quiz)">
                <x-icon name="chart" class="size-4" /> Attempts ({{ $quiz->attempts_count }})
            </x-btn>
            @can('quizzes.update')
                <x-btn :href="route('admin.quizzes.edit', $quiz)">
                    <x-icon name="pencil" class="size-4" /> Edit settings
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    {{-- Rules strip --}}
    <x-card class="mb-6">
        <dl class="grid gap-6 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ([
                'Questions' => $quiz->questions->count(),
                'Total points' => $quiz->questions->sum('points'),
                'Time limit' => $quiz->time_limit_minutes ? $quiz->time_limit_minutes.' min' : 'No limit',
                'Attempts allowed' => $quiz->attempts_allowed,
                'Passing score' => $quiz->passing_score.'%',
            ] as $label => $value)
                <div>
                    <dt class="font-mono text-[11px] font-medium uppercase tracking-[0.12em] text-on-surface-variant">{{ $label }}</dt>
                    <dd class="mt-1 font-display text-xl font-bold text-on-surface">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </x-card>

    {{-- Questions --}}
    <div class="space-y-4">
        @forelse ($quiz->questions as $question)
            <x-card :padding="false">
                <div class="flex items-start gap-4 p-6">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 font-mono text-sm font-semibold text-primary">{{ $loop->iteration }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-badge variant="secondary">{{ str_replace('_', ' ', $question->type) }}</x-badge>
                            <span class="font-mono text-[11px] uppercase tracking-[0.08em] text-outline">{{ $question->points }} {{ Str::plural('pt', $question->points) }}</span>
                        </div>
                        <p class="mt-2 font-medium text-on-surface">{{ $question->prompt }}</p>

                        @if ($question->options->isNotEmpty())
                            <ul class="mt-3 space-y-1.5">
                                @foreach ($question->options as $option)
                                    <li class="flex items-center gap-2 text-sm {{ $option->is_correct ? 'text-success' : 'text-on-surface-variant' }}">
                                        <x-icon :name="$option->is_correct ? 'check' : 'chevron-right'" class="size-3.5 shrink-0" />
                                        {{ $option->text }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($question->explanation)
                            <p class="mt-3 rounded-lg bg-surface-ice/70 px-3 py-2 text-xs leading-5 text-on-surface-variant">{{ $question->explanation }}</p>
                        @endif
                    </div>

                    @can('quizzes.update')
                        <div class="flex shrink-0 items-center gap-1">
                            <button type="button" x-data x-on:click="$dispatch('open-modal', 'question-{{ $question->id }}')"
                                class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/5 hover:text-primary" aria-label="Edit question">
                                <x-icon name="pencil" class="size-4" />
                            </button>
                            <x-confirm-form
                                :action="route('admin.questions.destroy', $question)"
                                method="DELETE"
                                title="Delete this question?"
                                message="Students' past attempts keep their scores, but the question disappears from the quiz."
                                confirm-label="Delete question"
                                class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error"
                                aria-label="Delete question"
                            >
                                <x-icon name="trash" class="size-4" />
                            </x-confirm-form>
                        </div>
                    @endcan
                </div>
            </x-card>

            @can('quizzes.update')
                <x-modal :name="'question-'.$question->id" max-width="2xl">
                    @include('admin.quizzes.partials.question-form', ['quiz' => $quiz, 'question' => $question, 'formId' => 'question-'.$question->id])
                </x-modal>
            @endcan
        @empty
            <x-card>
                <x-empty-state icon="quiz" title="No questions yet" description="Add the first question below — students can't start an empty quiz." />
            </x-card>
        @endforelse
    </div>

    {{-- Add question --}}
    @can('quizzes.update')
        <div class="mt-6">
            <x-btn x-data x-on:click="$dispatch('open-modal', 'question-new')">
                <x-icon name="plus" class="size-4" /> Add question
            </x-btn>
        </div>

        <x-modal name="question-new" max-width="2xl" :show="$errors->any() && old('_question') === 'new'">
            @include('admin.quizzes.partials.question-form', ['quiz' => $quiz, 'question' => null, 'formId' => 'question-new'])
        </x-modal>

        @if ($errors->any() && old('_question') && old('_question') !== 'new')
            <div x-data x-init="$dispatch('open-modal', 'question-{{ old('_question') }}')"></div>
        @endif
    @endcan
</x-admin.layout>
