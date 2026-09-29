{{-- Shared by task statuses and project statuses: one screen, two tables.
     $routeName, $noun, $heading, $description and $behaviours come from the
     controller, so the two lists cannot drift apart in wording or behaviour. --}}
<x-admin.layout :title="$heading">
    <x-page-header eyebrow="Team" :title="$heading" :description="$description"
        :crumbs="['Settings' => route('admin.settings.edit'), $heading => null]">
        <x-slot:actions>
            <x-btn :href="route('admin.'.$routeName.'.create')">
                <x-icon name="plus" class="size-4" /> New {{ $noun }}
            </x-btn>
            <x-btn variant="ghost" :href="route('admin.settings.edit')">
                <x-icon name="arrow-left" class="size-4" /> Back to settings
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    {{-- Where the "keep one active per behaviour" refusal lands. It comes back
         from a button rather than a form, so there is no field to put it beside. --}}
    <x-form.errors-summary />

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">{{ ucfirst($noun) }}</th>
                <th class="th">Behaviour</th>
                <th class="th">On offer</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($statuses as $status)
                <tr class="row">
                    <td class="td">
                        <div class="flex items-center gap-2.5">
                            <span class="size-3 shrink-0 rounded-full ring-1 ring-inset ring-black/10" style="background-color: {{ $status->colour }}"></span>
                            <p class="font-medium text-on-surface">{{ $status->label }}</p>
                        </div>
                    </td>
                    <td class="td">
                        {{-- The label above is wording and may be renamed at any
                             time. THIS is what the code branches on. --}}
                        <span class="text-sm text-on-surface-variant">{{ $status->behaviourLabel() }}</span>
                        <p class="mt-0.5 font-mono text-[11px] text-outline">{{ $status->behaviour }}</p>
                    </td>
                    <td class="td">
                        @if ($status->is_active)
                            <x-badge variant="success">On offer</x-badge>
                        @else
                            <x-badge variant="neutral">Retired</x-badge>
                        @endif
                    </td>
                    <td class="td text-right">
                        <div class="flex items-center justify-end gap-1">
                            <form method="POST" action="{{ route('admin.'.$routeName.'.move', $status) }}">
                                @csrf <input type="hidden" name="direction" value="up">
                                <button type="submit" @disabled($loop->first) class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary disabled:opacity-30" title="Move up">
                                    <x-icon name="arrow-up" class="size-4" />
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.'.$routeName.'.move', $status) }}">
                                @csrf <input type="hidden" name="direction" value="down">
                                <button type="submit" @disabled($loop->last) class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary disabled:opacity-30" title="Move down">
                                    <x-icon name="arrow-down" class="size-4" />
                                </button>
                            </form>
                            <x-confirm-form :action="route('admin.'.$routeName.'.toggle', $status)" method="POST"
                                :title="$status->is_active ? 'Retire status' : 'Offer status'"
                                :message="$status->is_active
                                    ? 'Stop offering '.$status->label.'? Anything already on it keeps it. Refused if it is the last active status for its behaviour.'
                                    : 'Offer '.$status->label.' again?'"
                                :confirm-label="$status->is_active ? 'Retire' : 'Offer'"
                                variant="primary"
                                class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                <x-icon :name="$status->is_active ? 'eye' : 'restore'" class="size-4" />
                            </x-confirm-form>
                            <a href="{{ route('admin.'.$routeName.'.edit', $status) }}" aria-label="Edit {{ $noun }}" title="Edit {{ $noun }}" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                <x-icon name="pencil" class="size-4" />
                            </a>
                            <x-confirm-form :action="route('admin.'.$routeName.'.destroy', $status)" method="DELETE"
                                title="Delete status"
                                :message="'Delete '.$status->label.'? Refused if anything is on it, or if it is the last active status for its behaviour — retire it instead.'"
                                confirm-label="Delete"
                                class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                <x-icon name="trash" class="size-4" />
                            </x-confirm-form>
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </x-table>

    <x-card class="mt-5">
        <h2 class="font-display text-[15px] font-semibold text-on-surface">The behaviours</h2>
        <p class="mt-0.5 text-[13px] leading-5 text-on-surface-variant">
            Fixed in code — pick one, never invent one. Rename a {{ $noun }} as often as you like; what the system does about it follows the behaviour beside it, so nothing breaks. Every behaviour has to keep at least one status on offer.
        </p>
        <dl class="mt-4 grid gap-2 sm:grid-cols-2">
            @foreach ($behaviours as $behaviour => $label)
                <div class="rounded-xl border border-outline-variant/60 px-4 py-3">
                    <dt class="font-mono text-[11px] uppercase tracking-[0.08em] text-outline">{{ $behaviour }}</dt>
                    <dd class="mt-0.5 text-[13px] text-on-surface">{{ $label }}</dd>
                </div>
            @endforeach
        </dl>
    </x-card>
</x-admin.layout>
