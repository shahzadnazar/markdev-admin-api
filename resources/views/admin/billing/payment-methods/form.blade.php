<x-admin.layout :title="$method ? 'Edit payment method' : 'Add payment method'">
    <div class="mb-5 flex flex-wrap items-start justify-between gap-x-4 gap-y-3 sm:flex-nowrap">
        <div class="min-w-0">
            <p class="eyebrow mb-1">Finance · Payment methods</p>
            <h1 class="font-display text-2xl font-bold leading-8 tracking-[-0.02em] text-on-surface">{{ $method ? 'Edit '.$method->name : 'Add payment method' }}</h1>
            <p class="mt-0.5 text-[13px] leading-5 text-on-surface-variant">These details are shown to the student when they choose this method while paying.</p>
        </div>
        <div class="flex shrink-0 items-center gap-2 pt-1.5">
            <x-btn variant="ghost" size="sm" :href="route('admin.billing.payment-methods.index')">
                <x-icon name="arrow-left" class="size-4" /> Payment methods
            </x-btn>
        </div>
    </div>

    <form method="POST"
        action="{{ $method ? route('admin.billing.payment-methods.update', $method) : route('admin.billing.payment-methods.store') }}"
        class="max-w-3xl" x-data="{ channel: @js(old('channel', $method?->channel ?? 'jazzcash')) }">
        @csrf
        @if ($method) @method('PUT') @endif

        <x-card class="space-y-5">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-form.input label="Display name" name="name" :value="$method?->name" required
                    placeholder="e.g. JazzCash — MarkDev" hint="Shown in the student's method list." />
                <x-form.select label="Channel" name="channel" required x-model="channel">
                    @foreach (\App\Models\PaymentMethod::CHANNELS as $value => $channel)
                        <option value="{{ $value }}" @selected(old('channel', $method?->channel) === $value)>{{ $channel['label'] }}</option>
                    @endforeach
                </x-form.select>
            </div>

            <div x-show="channel !== 'cash_deposit'" class="grid gap-5 sm:grid-cols-2">
                <x-form.input label="Account title" name="account_title" :value="$method?->account_title"
                    placeholder="e.g. Mark Dev Solutions" x-bind:required="channel !== 'cash_deposit'" />
                <x-form.input label="Account number / IBAN" name="account_number" :value="$method?->account_number"
                    placeholder="e.g. 0300-1234567 or PK36…" x-bind:required="channel !== 'cash_deposit'" />
            </div>

            <p x-show="channel === 'cash_deposit'" x-cloak class="rounded-lg bg-surface-ice px-3 py-2.5 text-xs leading-5 text-on-surface-variant">
                Cash is collected at the counter — no account details are shown. When paying, the student is asked
                for the <span class="font-semibold text-on-surface">receipt number</span> from the fee receipt you hand over, plus a photo of it.
            </p>

            <div class="grid gap-5 sm:grid-cols-2">
                <div x-show="channel !== 'cash_deposit'">
                    <x-form.input label="Bank name" name="bank_name" :value="$method?->bank_name"
                        placeholder="e.g. Meezan Bank" hint="Only for bank accounts — leave blank for wallets." />
                </div>
                <x-form.input type="number" label="Sort order" name="sort_order" :value="$method?->sort_order ?? 0" min="0" max="999"
                    hint="Lower numbers show first." />
            </div>

            <x-form.textarea label="Instructions (optional)" name="instructions" :value="$method?->instructions" rows="2"
                placeholder="e.g. Send the fee then upload the TID screenshot." />

            <div class="border-t border-surface-ice pt-5">
                <p class="mb-1.5 text-[13px] font-medium text-on-surface">Courses that use this method</p>
                <p class="mb-3 text-xs text-on-surface-variant">Leave every box unticked to make it available for all courses.</p>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($courses as $course)
                        <label class="flex cursor-pointer items-center gap-2.5 rounded-lg border border-outline/30 px-3 py-2 text-sm text-on-surface transition hover:border-primary/50">
                            <input type="checkbox" name="courses[]" value="{{ $course->id }}" class="check"
                                @checked(in_array($course->id, old('courses', $method?->courses->pluck('id')->all() ?? [])))>
                            {{ $course->title }}
                        </label>
                    @endforeach
                </div>
            </div>

            <label class="flex cursor-pointer items-center gap-3 border-t border-surface-ice pt-5">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" class="check" @checked(old('is_active', $method?->is_active ?? true))>
                <span class="text-sm font-medium text-on-surface">Active — students can pick this method</span>
            </label>
        </x-card>

        <div class="mt-5 flex justify-end gap-2.5">
            <x-btn variant="ghost" :href="route('admin.billing.payment-methods.index')">Cancel</x-btn>
            <x-btn type="submit"><x-icon name="check" class="size-4" /> {{ $method ? 'Save changes' : 'Add method' }}</x-btn>
        </div>
    </form>
</x-admin.layout>
