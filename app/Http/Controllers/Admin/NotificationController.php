<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

/**
 * The bell, and the list behind it — for EVERY panel user.
 *
 * ## Why this is not in the academy group
 *
 * It used to be: `notifications/read-all` sat inside the academy routes, whose
 * door admits super-admin, admin, manager and instructor. The bell is drawn by
 * the shared admin layout, so a team-lead and a team member were offered it and
 * answered with a 403 when they cleared it. Its own comment said "available to
 * every panel user", which is what it should have been and was not.
 *
 * It now sits behind PortalHome::gate() — the union of the two panel doors,
 * derived from the destinations PortalHome can land somebody on, so it cannot
 * drift from them.
 *
 * ## Nobody reads anybody else's
 *
 * Every query here starts from `$request->user()->notifications()`, which is
 * keyed on the notifiable. There is no id-in-the-URL path that could reach
 * another person's row: `read` looks the notification up THROUGH the
 * relationship, so a uuid belonging to somebody else is a 404 and not a
 * refusal — a refusal would confirm the row exists.
 */
class NotificationController extends Controller
{
    /**
     * The full list: unread first, then everything else, newest first.
     *
     * Unread first rather than strictly newest: the point of the page is what
     * still needs attention, and a notice from this morning that has been read
     * is less urgent than one from last week that has not.
     */
    public function index(Request $request): View
    {
        return view('admin.notifications.index', [
            'notifications' => $request->user()
                ->notifications()
                ->orderByRaw('case when read_at is null then 0 else 1 end')
                ->orderByDesc('created_at')
                ->paginate(30),
            'unread' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark one read, and go where it pointed.
     *
     * The link and the mark are one action on purpose: a notification you have
     * opened is read, and asking somebody to tick it afterwards is how a list
     * stays permanently full.
     */
    public function read(Request $request, string $notification): RedirectResponse
    {
        $row = $request->user()->notifications()->whereKey($notification)->firstOrFail();

        $row->markAsRead();

        $target = $this->target($row);

        return $target === null ? back() : redirect()->to($target);
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('success', 'All notifications marked read.');
    }

    /**
     * Where a notification points, for this panel.
     *
     * The academy's six notification classes were written for the student
     * portal and carry portal paths; AnnouncementPublished added
     * `admin_action_url` when instructors started receiving it. The same
     * precedence the topbar dropdown already uses is applied here, so the two
     * cannot disagree about where one notification goes — and a portal-only
     * path resolves to null rather than to a 404 in the panel.
     */
    protected function target(DatabaseNotification $notification): ?string
    {
        $admin = $notification->data['admin_action_url'] ?? null;

        if (is_string($admin) && $admin !== '') {
            return $admin;
        }

        $action = $notification->data['action_url'] ?? null;

        return is_string($action) && str_starts_with($action, '/admin') ? $action : null;
    }
}
