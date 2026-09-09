<x-admin.layout title="Profile">
    <x-page-header eyebrow="Account" title="Profile" description="Manage your account information, password and session security." />

    <div class="grid max-w-3xl gap-6">
        <x-card>
            @include('profile.partials.update-profile-information-form')
        </x-card>

        <x-card>
            @include('profile.partials.update-password-form')
        </x-card>
    </div>

    {{-- Breeze's "Delete Account" card used to sit here.

         The academy already has a rule about this: UserController::destroy
         refuses with "You cannot delete your own account from here." An admin
         cannot remove themselves from the Users screen, so offering it here —
         now one click from every page in the sidebar — would be the same
         account deletion by a quieter door, and a super admin who took it
         would lock the academy out of its own panel.

         Removing an account stays where it already lives: People → Staff &
         Users, done by someone else. The partial is left in place rather than
         deleted, so restoring the card is one @include if that is wanted. --}}
</x-admin.layout>
