<x-admin.layout :title="'Attempts — '.$quiz->title">
    <x-page-header
        eyebrow="Learning"
        :title="'Attempts · '.$quiz->title"
        description="Every finished attempt with its score and outcome."
    >
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.quizzes.show', $quiz)">
                <x-icon name="arrow-left" class="size-4" /> Back to builder
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Student</th>
                <th class="th">Started</th>
                <th class="th">Submitted</th>
                <th class="th">Score</th>
                <th class="th">Result</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($attempts as $attempt)
                <tr class="row">
                    <td class="td">
                        <p class="font-medium text-on-surface">{{ $attempt->user?->name ?? 'Deleted user' }}</p>
                        <p class="text-xs text-outline">{{ $attempt->user?->email }}</p>
                    </td>
                    <td class="td font-mono text-xs text-outline">{{ $attempt->started_at?->format('M j, Y · H:i') }}</td>
                    <td class="td font-mono text-xs text-outline">{{ $attempt->submitted_at?->format('M j, Y · H:i') ?? '—' }}</td>
                    <td class="td">
                        @if ($attempt->submitted_at)
                            <span class="font-mono text-sm text-on-surface">{{ $attempt->score }} / {{ $attempt->max_score }}</span>
                            <span class="ml-1 font-mono text-xs text-outline">({{ $attempt->max_score ? round($attempt->score / max(1, $attempt->max_score) * 100) : 0 }}%)</span>
                        @else
                            <span class="text-xs text-outline">In progress</span>
                        @endif
                    </td>
                    <td class="td">
                        @if (! $attempt->submitted_at)
                            <x-badge variant="warning">open</x-badge>
                        @elseif ($attempt->passed)
                            <x-badge variant="success">passed</x-badge>
                        @else
                            <x-badge variant="danger">failed</x-badge>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5"><x-empty-state icon="quiz" title="No attempts yet" description="Attempts appear here as soon as students take this quiz." /></td></tr>
            @endforelse
        </tbody>
        @if ($attempts->hasPages())
            <x-slot:footer>{{ $attempts->links() }}</x-slot:footer>
        @endif
    </x-table>
</x-admin.layout>
