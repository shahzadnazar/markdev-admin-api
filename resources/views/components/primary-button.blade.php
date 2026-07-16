<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-white shadow-card transition duration-150 hover:bg-primary-deep hover:-translate-y-px active:translate-y-0 focus:outline-none focus-visible:ring-4 focus-visible:ring-primary/25 disabled:opacity-50']) }}>
    {{ $slot }}
</button>
