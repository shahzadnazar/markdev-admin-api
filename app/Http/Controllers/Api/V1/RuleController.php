<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\RuleBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Rules & Regulations page, finished, in one request.
 *
 * The portal renders what it is given: whole sentences, a weight table, the
 * student's own slot and the holidays ahead. It holds no default for any
 * number and never assembles a sentence from one, so an admin changing a
 * setting changes what students read with nothing redeployed.
 *
 * Every value comes through Setting::cached — the whole settings table read
 * once per request and memoised, with a missing table rescued rather than
 * fatal. Deliberately not Cache::remember: on a database cache store that put
 * cache-table writes into a render path and cost /admin/students/register a
 * 30-second timeout.
 */
class RuleController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $student = $request->user()?->loadMissing('studentProfile.attendanceSlot');

        return response()->json(['data' => RuleBook::forStudent($student)]);
    }
}
