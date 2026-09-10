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

    {{-- Said once, above the table, rather than beside any one attempt: the
         signal is weak and the note has to travel with it. Without this an
         instructor could read a count as evidence of something it cannot
         evidence. --}}
    <p class="mb-4 max-w-3xl text-xs text-outline">
        Where an attempt records tab switches, they are shown under the student's name for context only.
        A notification, a clock check and a second monitor all look the same from here, and nothing outside
        the browser tab — a phone, a printed page, another device — is visible at all. One switch is
        ordinary; a pattern is a reason to look at the attempt, not a conclusion about it.
    </p>

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
                        {{-- Nothing at all when the count is zero. An empty row
                             invites reading meaning into silence, and every
                             attempt taken before this was measured reads zero.
                             Same colour as the email above it: this is context,
                             not a verdict, and a red one would be the system
                             deciding something it is in no position to decide. --}}
                        @if ($summary = $attempt->awaySummary())
                            <p class="mt-1 font-mono text-[11px] text-outline">{{ $summary }}</p>
                        @endif
                    </td>
                    <td class="td font-mono text-xs text-outline">{{ $attempt->started_at?->format('j M Y, g:i A') }}</td>
                    <td class="td font-mono text-xs text-outline">{{ $attempt->submitted_at?->format('j M Y, g:i A') ?? '—' }}</td>
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
