<x-admin.layout title="Profile">
    <x-page-header eyebrow="Account" title="Profile" description="Manage your account information, password and session security." />

    <div class="grid max-w-3xl gap-6">
        <x-card>
            @include('profile.partials.update-profile-information-form')
        </x-card>

        <x-card>
            @include('profile.partials.update-password-form')
        </x-card>

        <x-card>
            @include('profile.partials.delete-user-form')
        </x-card>
    </div>
</x-admin.layout>
