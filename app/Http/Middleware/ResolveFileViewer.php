<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who is asking for this file.
 *
 * Two clients reach the same routes by different means. The admin panel is
 * Blade with a session cookie, so auth() already knows. The student portal
 * authenticates with a bearer token from localStorage, and a browser sending
 * an <img src> or following an <a href> sends neither — so those links are
 * signed, with the viewer's id inside the signature.
 *
 * The signature establishes IDENTITY and nothing else. It is tamper-proof, so
 * `u` can be trusted as "the person this link was minted for", and the
 * controller then runs exactly the same authorisation it runs for a session
 * request. A signed link is never a bypass: minting one for a document you may
 * not read still gets you a 403 when you follow it.
 *
 * 404 rather than 403 for an unidentified caller. Someone with no session and
 * no valid signature should not learn from the status code whether the path
 * they guessed exists.
 */
class ResolveFileViewer
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null) {
            return $next($request);
        }

        // hasValidSignature() covers expiry as well as tampering.
        if ($request->hasValidSignature() && $request->filled('u')) {
            $viewer = User::find($request->integer('u'));

            if ($viewer !== null) {
                auth()->setUser($viewer);

                return $next($request);
            }
        }

        abort(404);
    }
}
