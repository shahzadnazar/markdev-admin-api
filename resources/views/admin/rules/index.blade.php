<x-admin.layout title="Rules & Regulations">
    <x-page-header eyebrow="System" :title="'Rules & Regulations'"
        description="The page students read. Numbers are never typed here — they are placeholders filled from Settings when the page is served, so a reworded rule can never carry a stale figure."
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Settings' => route('admin.settings.edit'), 'Rules' => null]">
        <x-slot:meta>
            @if ($lastUpdated)
                <span class="font-mono text-xs text-on-surface-variant">Last updated {{ $lastUpdated->format('j M Y, g:i A') }}</span>
            @endif
        </x-slot:meta>
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.settings.edit')">
                <x-icon name="arrow-left" class="size-4" /> Back to settings
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    {{-- A refused save redirects back here, so the reason has to be visible
         somewhere on the page — otherwise a typo looks like a silent no-op. --}}
    @error('body')
        <div class="mb-4 flex items-start gap-2.5 rounded-lg border border-error/30 bg-error/10 px-3.5 py-2.5 text-[13px] text-on-surface">
            <x-icon name="warning" class="mt-0.5 size-4 shrink-0 text-error" />
            <p>{{ $message }}</p>
        </div>
    @enderror

    <x-card class="mb-4 text-sm">
        <p class="font-medium text-on-surface">Rules cannot be added or removed here — only reworded.</p>
        <p class="mt-1 text-xs leading-5 text-outline">
            Every sentence below has code enforcing it. A rule the system does not actually apply would be worse than no rule at all,
            so the set is fixed and the wording is yours. A placeholder that does not exist is refused when you save.
            Previews are rendered against <em>your</em> account, so slot placeholders show a dash if you are not on one — a student sees their own.
        </p>
    </x-card>

    @foreach ($sections as $key => $title)
        @php $sectionRules = $rules->get($key, collect()); @endphp
        @continue ($sectionRules->isEmpty())

        <x-card class="mb-4">
            <p class="font-mono text-[11px] font-medium uppercase tracking-[0.12em] text-on-surface-variant">{{ $title }}</p>

            <div class="mt-3 space-y-4">
                @foreach ($sectionRules as $rule)
                    <div class="border-t border-surface-ice pt-4 first:border-t-0 first:pt-0">
                        <form method="POST" action="{{ route('admin.rules.update', $rule) }}">
                            @csrf @method('PUT')
                            <label class="font-mono text-[10px] uppercase tracking-[0.1em] text-outline" for="rule-{{ $rule->id }}">
                                {{ $rule->key }}
                                @if ($rule->isEdited())
                                    <span class="ml-1 rounded-full bg-primary/10 px-1.5 py-0.5 text-primary">edited</span>
                                @endif
                            </label>
                            <textarea id="rule-{{ $rule->id }}" name="body" rows="2"
                                @class(['field mt-1 w-full text-sm', 'ring-1 ring-error' => $errors->any() && old('rule_id') == $rule->id])
                                >{{ old('rule_id') == $rule->id ? old('body') : $rule->body }}</textarea>
                            {{-- Which form the rejected text belongs to, so a
                                 refused save re-fills that box and no other. --}}
                            <input type="hidden" name="rule_id" value="{{ $rule->id }}">

                            {{-- What a student will actually read, with this admin's own
                                 slot and currency standing in for theirs. --}}
                            <p class="mt-1.5 text-xs leading-5 text-on-surface-variant">
                                <span class="font-medium text-on-surface">Preview:</span>
                                {{ \App\Support\RuleBook::render($rule->body, $context) }}
                            </p>

                            <div class="mt-2 flex items-center gap-2">
                                <x-btn size="sm"><x-icon name="check" class="size-3.5" /> Save</x-btn>
                                @if ($rule->isEdited())
                                    <x-confirm-form :action="route('admin.rules.reset', $rule)" method="POST"
                                        title="Reset wording"
                                        message="Put back the wording this release ships for this rule?"
                                        confirm-label="Reset" variant="primary"
                                        class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                        <x-icon name="restore" class="size-3.5" /> Reset
                                    </x-confirm-form>
                                @endif
                            </div>
                        </form>
                    </div>
                @endforeach
            </div>
        </x-card>
    @endforeach

    <x-card>
        <p class="font-mono text-[11px] font-medium uppercase tracking-[0.12em] text-on-surface-variant">Available placeholders</p>
        <div class="mt-2 flex flex-wrap gap-1.5">
            @foreach ($context as $name => $value)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-ice px-2.5 py-1 font-mono text-[11px] text-on-surface-variant"
                    title="{{ $value }}">
                    {{ '{'.$name.'}' }}
                    <span class="text-outline">→ {{ \Illuminate\Support\Str::limit($value, 28) }}</span>
                </span>
            @endforeach
        </div>
    </x-card>
</x-admin.layout>
