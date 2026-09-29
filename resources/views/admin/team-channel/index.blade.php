{{-- The one cross-team channel. Every team-portal user; no clients, no
     instructors, no managers, no students. Admin announcements are pinned
     above the conversation and drawn distinctly. --}}
<x-admin.layout title="Channel">
    <x-page-header eyebrow="Team" title="Channel"
        description="One place where every team talks to every other. Announcements from an administrator are pinned at the top."
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Channel' => null]" />

    <x-form.errors-summary />

    <div class="max-w-3xl">
        <x-team.comment-thread
            :threads="$threads"
            :store-route="route('admin.team-channel.store')"
            :update-route="fn ($m) => route('admin.team-channel.update', $m)"
            :destroy-route="fn ($m) => route('admin.team-channel.destroy', $m)"
            :may-announce="$mayAnnounce"
            placeholder="Say something to every team…"
            empty-title="Nobody has posted yet" />
    </div>
</x-admin.layout>
