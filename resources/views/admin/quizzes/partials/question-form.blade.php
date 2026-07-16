{{--
    Shared question form for the quiz builder (create + edit).
    Expects: $quiz, $question (nullable), $formId (unique string).
--}}
@php
    $isEdit = $question !== null;
    $marker = $isEdit ? (string) $question->id : 'new';
    $stale = old('_question') === $marker;

    $initialType = $stale ? old('type', 'single_choice') : ($question->type ?? 'single_choice');
    $initialOptions = $stale
        ? collect(old('options', []))->map(fn ($o) => ['text' => $o['text'] ?? ''])->values()
        : ($question?->options->map(fn ($o) => ['text' => $o->text])->values() ?? collect([['text' => ''], ['text' => '']]));
    if ($initialOptions->isEmpty()) {
        $initialOptions = collect([['text' => ''], ['text' => '']]);
    }
    $initialCorrect = $stale
        ? collect(old('correct', []))->map(fn ($i) => (int) $i)->values()
        : ($question?->options->values()->filter(fn ($o) => $o->is_correct)->keys()->values() ?? collect());
@endphp

<form method="POST"
    action="{{ $isEdit ? route('admin.questions.update', $question) : route('admin.questions.store', $quiz) }}"
    class="p-6"
    x-data="{
        type: @js($initialType),
        options: @js($initialOptions->all()),
        correct: @js($initialCorrect->map(fn ($i) => (string) $i)->all()),
        setType() {
            if (this.type === 'true_false') {
                this.options = [{ text: 'True' }, { text: 'False' }];
                this.correct = this.correct.length ? [this.correct[0]] : [];
            }
            if (this.type === 'short_answer') { this.correct = []; }
            if (this.type === 'single_choice' && this.correct.length > 1) { this.correct = [this.correct[0]]; }
        },
        addOption() { this.options.push({ text: '' }) },
        removeOption(index) { this.options.splice(index, 1); this.correct = []; },
    }">
    @csrf
    @if ($isEdit) @method('PUT') @endif
    <input type="hidden" name="_question" value="{{ $marker }}">

    <h3 class="font-display text-lg font-semibold text-on-surface">{{ $isEdit ? 'Edit question' : 'Add question' }}</h3>

    @if ($stale && $errors->any())
        <div class="mt-3 rounded-xl bg-error-container/60 px-4 py-3 text-sm text-error">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="mt-5 space-y-4">
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <x-form.label :for="$formId.'-type'" value="Question type" />
                <select name="type" id="{{ $formId }}-type" class="field" x-model="type" x-on:change="setType()">
                    <option value="single_choice">Single choice</option>
                    <option value="multiple_choice">Multiple choice</option>
                    <option value="true_false">True / False</option>
                    <option value="short_answer">Short answer</option>
                </select>
            </div>
            <div>
                <x-form.label :for="$formId.'-points'" value="Points" />
                <input type="number" name="points" id="{{ $formId }}-points" min="1" max="100" class="field"
                    value="{{ $stale ? old('points', 1) : ($question->points ?? 1) }}" required>
            </div>
        </div>

        <div>
            <x-form.label :for="$formId.'-prompt'" value="Prompt" />
            <textarea name="prompt" id="{{ $formId }}-prompt" rows="2" required class="field" placeholder="Ask the question…">{{ $stale ? old('prompt') : ($question->prompt ?? '') }}</textarea>
        </div>

        {{-- Options (choice types only) --}}
        <div x-show="type !== 'short_answer'" x-cloak class="rounded-xl bg-surface-ice/70 p-4">
            <div class="mb-3 flex items-center justify-between">
                <p class="font-mono text-[11px] font-medium uppercase tracking-[0.1em] text-on-surface-variant">Options</p>
                <p class="text-[11px] text-outline" x-text="type === 'multiple_choice' ? 'Tick every correct answer' : 'Pick the one correct answer'"></p>
            </div>

            <div class="space-y-2">
                <template x-for="(option, index) in options" :key="index">
                    <div class="flex items-center gap-3">
                        <input :type="type === 'multiple_choice' ? 'checkbox' : 'radio'"
                            name="correct[]"
                            :value="index"
                            :checked="correct.includes(String(index))"
                            x-on:change="type === 'multiple_choice'
                                ? (correct.includes(String(index)) ? correct = correct.filter(i => i !== String(index)) : correct.push(String(index)))
                                : correct = [String(index)]"
                            class="check shrink-0">
                        <input type="text" :name="`options[${index}][text]`" x-model="option.text"
                            :readonly="type === 'true_false'" required
                            class="field" placeholder="Option text…">
                        <button type="button" x-on:click="removeOption(index)" x-show="type !== 'true_false' && options.length > 2"
                            class="shrink-0 rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                            <x-icon name="x-mark" class="size-4" />
                        </button>
                    </div>
                </template>
            </div>

            <button type="button" x-on:click="addOption()" x-show="type !== 'true_false'"
                class="mt-3 inline-flex items-center gap-2 text-sm font-medium text-primary transition hover:text-primary-deep">
                <x-icon name="plus" class="size-4" /> Add option
            </button>
        </div>

        <div>
            <x-form.label :for="$formId.'-explanation'" value="Explanation (optional)" />
            <textarea name="explanation" id="{{ $formId }}-explanation" rows="2" class="field" placeholder="Shown to students after answering.">{{ $stale ? old('explanation') : ($question->explanation ?? '') }}</textarea>
        </div>
    </div>

    <div class="mt-6 flex justify-end gap-3">
        <x-btn type="button" variant="ghost" x-on:click="$dispatch('close-modal', '{{ $formId }}')">Cancel</x-btn>
        <x-btn>
            <x-icon name="check" class="size-4" />
            {{ $isEdit ? 'Save question' : 'Add question' }}
        </x-btn>
    </div>
</form>
