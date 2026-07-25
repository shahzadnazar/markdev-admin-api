<x-admin.layout title="Quizzes">
    <x-page-header eyebrow="Learning" title="Quizzes" description="Knowledge checks, their questions and attempt history.">
        <x-slot:actions>
            @can('quizzes.create')
                <x-btn :href="route('admin.quizzes.create')">
                    <x-icon name="plus" class="size-4" /> New quiz
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar :action="route('admin.quizzes.index')">
        <div class="w-full sm:w-64">
            <x-form.label for="search" value="Search" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Quiz title…" class="field">
        </div>
        <div class="w-64">
            <x-form.label for="course" value="Course" />
            <select name="course" id="course" class="field">
                <option value="">All courses</option>
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}" @selected(request('course') == $course->id)>{{ $course->title }}</option>
                @endforeach
            </select>
        </div>
    </x-filter-bar>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Quiz</th>
                <th class="th">Course</th>
                <th class="th">Questions</th>
                <th class="th">Attempts</th>
                <th class="th">Pass mark</th>
                <th class="th">Status</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($quizzes as $quiz)
                <tr class="row">
                    <td class="td">
                        <a href="{{ route('admin.quizzes.show', $quiz) }}" class="font-medium text-on-surface hover:text-primary">{{ $quiz->title }}</a>
                        @if ($quiz->time_limit_minutes)
                            <p class="text-xs text-outline">{{ $quiz->time_limit_minutes }} min limit · {{ $quiz->attempts_allowed ?? '∞' }} attempts</p>
                        @endif
                    </td>
                    <td class="td max-w-[16rem]"><p class="truncate text-on-surface-variant">{{ $quiz->course?->title }}</p></td>
                    <td class="td font-mono text-xs">{{ $quiz->questions_count }}</td>
                    <td class="td font-mono text-xs">{{ $quiz->attempts_count }}</td>
                    <td class="td font-mono text-xs">{{ $quiz->passing_score !== null ? $quiz->passing_score.'%' : '—' }}</td>
                    <td class="td">
                        <x-badge :variant="$quiz->is_published ? 'success' : 'warning'">{{ $quiz->is_published ? 'published' : 'draft' }}</x-badge>
                    </td>
                    <td class="td">
                        <div class="flex items-center justify-end gap-1">
                            <a href="{{ route('admin.quizzes.show', $quiz) }}" title="Question builder" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                <x-icon name="eye" class="size-4" />
                            </a>
                            @can('quizzes.update')
                                <a href="{{ route('admin.quizzes.edit', $quiz) }}" aria-label="Edit quiz" title="Edit quiz" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                    <x-icon name="pencil" class="size-4" />
                                </a>
                            @endcan
                            @can('quizzes.delete')
                                <x-confirm-form :action="route('admin.quizzes.destroy', $quiz)" method="DELETE"
                                    title="Delete quiz" :message="'Delete '.$quiz->title.' including its questions and attempts?'" confirm-label="Delete"
                                    class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                    <x-icon name="trash" class="size-4" />
                                </x-confirm-form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-empty-state icon="quiz" title="No quizzes found" description="Create a quiz and start adding questions." />
                    </td>
                </tr>
            @endforelse
        </tbody>
        <x-slot:footer>
            {{ $quizzes->links() }}
        </x-slot:footer>
    </x-table>
</x-admin.layout>
