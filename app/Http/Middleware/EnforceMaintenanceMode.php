<?php

namespace App\Http\Middleware;

use App\Support\MaintenanceMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds students and clients out while the academy is down, and nobody else.
 *
 * ONE middleware for both surfaces, applied to the student API's authenticated
 * group and to the client route group — and to NEITHER admin group. Staff are
 * the people doing the maintenance; an admin who turns this on and is then
 * locked out of the setting that turns it off has a problem no banner can fix.
 *
 * ## 503, never 200 with a flag
 *
 * A client that does not understand the body should still fail loudly. 200 with
 * `{"maintenance": true}` renders as an empty screen in anything that has not
 * been taught the flag, is cached by proxies as a successful response, and
 * cannot be told from success by a health check. 503 is the status that means
 * exactly this, and it carries Retry-After for anything that honours it.
 *
 * ## The body carries the admin's message
 *
 * The React portal has no maintenance screen yet, so today it shows whatever its
 * generic error handling does with a 503. That is the right order to build the
 * two halves in — the API cannot be taught to say this later without a release
 * on both sides — and the message is in the body from the first day so the
 * portal has something to render the moment it grows one.
 */
class EnforceMaintenanceMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! MaintenanceMode::blocks($request->user())) {
            return $next($request);
        }

        $message = MaintenanceMode::message();

        // `is('api/*')` as well as expectsJson(): a caller that sends no Accept
        // header — curl, a health check, an SDK with a terse default — is still
        // asking the API, and handing it a page of HTML would be a second thing
        // for it to fail to understand.
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => $message,
                // A machine-readable marker beside the sentence, so the portal
                // can tell scheduled downtime from a server that fell over —
                // both of which are 503s.
                'maintenance' => true,
            ], Response::HTTP_SERVICE_UNAVAILABLE)->header('Retry-After', 3600);
        }

        return response()
            ->view('maintenance', ['message' => $message], Response::HTTP_SERVICE_UNAVAILABLE)
            ->header('Retry-After', 3600);
    }
}
