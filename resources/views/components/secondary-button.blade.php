<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center gap-2 rounded-lg border border-primary/40 bg-white px-4 py-2.5 text-sm font-medium text-primary transition duration-150 hover:border-primary hover:bg-primary/5 focus:outline-none focus-visible:ring-4 focus-visible:ring-primary/25 disabled:opacity-50']) }}>
    {{ $slot }}
</button>
