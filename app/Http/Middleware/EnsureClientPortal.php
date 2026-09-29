<?php

namespace App\Http\Middleware;

use App\Support\ClientPortal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The client group's door: a client record has to point at this login.
 *
 * ONE CHECK, AT THE DOOR, and not a permission. The client role holds no
 * permission at all and deliberately gains none in this phase — a permission
 * would appear on the Roles & Permissions screen as something to grant, and
 * "make this instructor a client" is not a sentence anybody should be offered.
 * What entitles somebody to the client portal is that MarkDev does work for
 * them, which is a row in `clients`.
 *
 * 403 and not 404. The client portal's own EXISTENCE is not a secret — the login
 * form is public and so is the fact that MarkDev has clients. What is secret is
 * which projects exist, and that is answered a layer in, where a project outside
 * your own is a 404 rather than a refusal.
 *
 * A staff account that happens to be linked to a client record passes here, and
 * that is correct: they would see that client's projects and nothing else, which
 * is what the link means. It is also how an admin checks what a client can see
 * without a "view as" mechanism nobody asked for.
 */
class EnsureClientPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            ClientPortal::isClient($request->user()),
            403,
            'This area is for MarkDev clients. Your account is not linked to a client record.',
        );

        return $next($request);
    }
}
