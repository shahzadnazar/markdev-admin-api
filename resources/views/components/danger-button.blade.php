<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center gap-2 rounded-lg bg-error px-4 py-2.5 text-sm font-medium text-white transition duration-150 hover:bg-error/90 hover:-translate-y-px active:translate-y-0 focus:outline-none focus-visible:ring-4 focus-visible:ring-error/25 disabled:opacity-50']) }}>
    {{ $slot }}
</button>
