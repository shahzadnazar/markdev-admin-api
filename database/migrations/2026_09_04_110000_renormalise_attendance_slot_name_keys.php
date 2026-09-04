<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes name_key now that spacing no longer distinguishes two names.
 *
 * The keys written by the previous migration only folded case, so a table
 * holding both "Slot 1" and "Slot1" carries two keys that the rule now says
 * are one. Rewriting them here keeps the unique index enforcing the same rule
 * the form does.
 *
 * Cleared in one pass before being written in another: the index would
 * otherwise reject a row midway through, when a key is being moved onto a
 * value another row has not yet moved off.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('attendance_slots')->whereNull('deleted_at')->update(['name_key' => null]);

        $taken = [];

        DB::table('attendance_slots')->whereNull('deleted_at')->orderBy('id')->get(['id', 'name'])
            ->each(function (object $slot) use (&$taken) {
                // Inlined rather than calling the model, so a later change to
                // the rule cannot quietly change what this migration did.
                $key = mb_strtolower((string) preg_replace('/\s+/u', '', (string) $slot->name));

                // Names that only now count as duplicates keep the name the
                // admin sees and take a suffixed key. The form makes them pick
                // a free one the next time that slot's name is edited.
                if ($key === '' || isset($taken[$key])) {
                    $key = mb_substr($key, 0, 70).'-'.$slot->id;
                }

                $taken[$key] = true;

                DB::table('attendance_slots')->where('id', $slot->id)->update(['name_key' => $key]);
            });
    }

    public function down(): void
    {
        // The keys are derived from the names; nothing here to restore.
    }
};
