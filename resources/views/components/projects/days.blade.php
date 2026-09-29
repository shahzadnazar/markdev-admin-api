@props(['project'])

{{-- The one place a day figure is rendered, so the rule lives in one file.

     A PAUSED project has no number. Its days are not being counted — a team
     told to stop is not spending its schedule — so showing a countdown would
     be charging them for the wait. A closed project has none either: there is
     nothing left to run. Both are decided on the status BEHAVIOUR, never on
     its label, because an admin may rename "On Hold" whenever they like. --}}
@php $days = $project->daysRemaining(); @endphp

@if ($days !== null)
    <span class="text-sm {{ $days < 0 ? 'font-medium text-error' : 'text-on-surface' }}">
        {{ $days < 0 ? abs($days).' days over' : $days.' days left' }}
    </span>
    <p class="mt-0.5 font-mono text-[11px] text-outline">{{ $project->due_date?->format('j M Y') }}</p>
@elseif ($project->isPaused())
    <span class="text-sm text-on-surface-variant">Paused</span>
    <p class="mt-0.5 text-[11px] text-outline">Days are not counting</p>
@else
    <span class="text-sm text-outline">—</span>
    @if ($project->due_date)
        <p class="mt-0.5 font-mono text-[11px] text-outline">{{ $project->due_date->format('j M Y') }}</p>
    @endif
@endif
