<x-admin.layout title="Certificates">
    <x-page-header eyebrow="Learning" title="Certificates" description="Issued certificates across all courses.">
        <x-slot:actions>
            @can('certificates.issue')
                <x-btn :href="route('admin.certificates.create')">
                    <x-icon name="plus" class="size-4" /> Issue certificate
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar :action="route('admin.certificates.index')">
        <div class="w-full sm:w-72">
            <x-form.label for="search" value="Search" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Student, course or number…" class="field">
        </div>
    </x-filter-bar>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Number</th>
                <th class="th">Student</th>
                <th class="th">Course</th>
                <th class="th">Issued</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($certificates as $certificate)
                <tr class="row">
                    <td class="td font-mono text-xs text-primary">{{ $certificate->certificate_number }}</td>
                    <td class="td">
                        <p class="font-medium text-on-surface">{{ $certificate->user?->name ?? 'Deleted user' }}</p>
                        <p class="text-xs text-outline">{{ $certificate->user?->email }}</p>
                    </td>
                    <td class="td max-w-[18rem]"><p class="truncate text-on-surface-variant">{{ $certificate->course?->title ?? '—' }}</p></td>
                    <td class="td font-mono text-xs text-outline">{{ $certificate->issued_at?->format('M j, Y') }}</td>
                    <td class="td text-right">
                        @can('certificates.delete')
                            <x-confirm-form
                                :action="route('admin.certificates.destroy', $certificate)"
                                method="DELETE"
                                title="Revoke this certificate?"
                                message="The student will no longer be able to download it."
                                confirm-label="Revoke"
                                class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error"
                                aria-label="Revoke certificate"
                            >
                                <x-icon name="trash" class="size-4" />
                            </x-confirm-form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="5"><x-empty-state icon="certificate" title="No certificates yet" description="Certificates are issued automatically at 100% course completion, or manually from here." /></td></tr>
            @endforelse
        </tbody>
        @if ($certificates->hasPages())
            <x-slot:footer>{{ $certificates->links() }}</x-slot:footer>
        @endif
    </x-table>
</x-admin.layout>
