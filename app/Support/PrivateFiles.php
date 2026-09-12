<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Which uploads are private, and how a private one is linked to.
 *
 * Every upload used to land on the `public` disk and be served straight off
 * the filesystem by the storage symlink. Measured before this changed: a GET
 * for a student's photo returned 200 and 38 KB with no session at all, and the
 * same for an assignment submission. There was no authorisation step anywhere
 * in that path because there was no request handler in it.
 *
 * So the sensitive kinds move to the `local` disk, whose root is
 * storage/app/private — outside the document root, unreachable by any URL —
 * and are reached only through FileController, which asks "may THIS user see
 * THIS file" before streaming a byte.
 *
 * PUBLIC is a deliberately short list and is written down here rather than
 * inferred, so adding an upload is a decision someone has to make rather than
 * a default they inherit.
 */
final class PrivateFiles
{
    /** The disk private uploads live on. */
    public const DISK = 'local';

    /**
     * Path prefixes that must never be on the public disk.
     *
     * Used by the migration to find what to move and by a test to assert no
     * public URL is minted for any of them.
     */
    public const PRIVATE_PREFIXES = [
        'students/documents',   // CNIC and degree scans
        'students/photos',      // a photograph of a named minor or adult
        'submissions',          // a student's own work
        'receipts',             // what someone paid, and when
        'notes',                // course material, for enrolled students
        'resources',            // course material, for enrolled students
        'attachments',          // assignment briefs, for the assigned students
    ];

    /**
     * Prefixes that stay public, and why.
     *
     * Thumbnails render as <img> in the portal, which authenticates with a
     * bearer token from localStorage — an <img> cannot carry that header, so a
     * private thumbnail would need a signed URL minted into every catalog row.
     * They are marketing imagery for a catalog the academy wants seen; the
     * trade is not worth it. Avatars are the same: a user's own chosen picture,
     * shown beside their comments to everyone else in the course anyway.
     */
    public const PUBLIC_PREFIXES = [
        'courses',   // course and lesson/video thumbnails
        'avatars',   // profile pictures, shown to other users by design
    ];

    /** True when this stored path belongs to a kind that must be private. */
    public static function isPrivatePath(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        foreach (self::PRIVATE_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delete a stored file, wherever it currently lives.
     *
     * A path does not say which disk it is on: the migration moves the bytes
     * and leaves the path string alone, so the same string names a public file
     * before the move and a private one after. Deleting from both is idempotent
     * — Flysystem's delete does not care if the file is absent — and means a
     * delete during a half-finished migration still removes the copy that
     * would otherwise have been left readable.
     */
    public static function forget(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        \Illuminate\Support\Facades\Storage::disk(self::DISK)->delete($path);

        if (self::isPrivatePath($path)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
        }
    }

    /**
     * A link to a private file that works from the portal.
     *
     * The admin panel holds a session cookie, so it can call these routes
     * directly. The portal holds a bearer token in localStorage, and neither an
     * <img src> nor an <a href> can carry a header — so an API-facing link is
     * signed instead, with the viewer's id inside the signature.
     *
     * The signature is not the authorisation. It carries WHO is asking across a
     * hop that cannot carry a token; FileController still runs the same
     * ownership and enrollment checks it runs for a session request. A signed
     * link for someone else's document is therefore still a 403, not a hole.
     *
     * Short-lived on purpose: long enough to click, not long enough to be a
     * bookmark that outlives someone's enrollment. This is the same mechanism
     * the certificate and invoice downloads already use.
     */
    public static function signedUrl(string $route, array $parameters, ?User $viewer): ?string
    {
        if ($viewer === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            $route,
            now()->addMinutes(30),
            $parameters + ['u' => $viewer->id],
        );
    }
}
