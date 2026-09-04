<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A unique index behind the "slot names are unique" rule.
 *
 * Validation alone races: two admins submitting "Morning" at the same moment
 * both pass the check and both insert. The index closes that.
 *
 * It sits on a normalised copy of the name rather than on `name` itself for
 * two reasons. Case-insensitivity at the database layer would otherwise follow
 * the column collation — MariaDB folds case, SQLite does not — and a soft
 * deleted slot must not keep its name reserved forever. A trashed row holds a
 * null key, and unique indexes allow repeated nulls on both engines, so
 * deleting "Morning" frees the name again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_slots', function (Blueprint $table) {
            $table->string('name_key', 80)->nullable()->after('name');
        });

        $this->backfill();

        Schema::table('attendance_slots', function (Blueprint $table) {
            $table->unique('name_key');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_slots', function (Blueprint $table) {
            $table->dropUnique(['name_key']);
            $table->dropColumn('name_key');
        });
    }

    /**
     * Slots created before the rule existed may already share a name. Those
     * rows keep the name the admin sees and take a suffixed key, so the index
     * can be created without rewriting anyone's data; the form makes them pick
     * a free name the next time that slot's name is edited.
     */
    protected function backfill(): void
    {
        $taken = [];

        DB::table('attendance_slots')->orderBy('id')->get(['id', 'name', 'deleted_at'])
            ->each(function (object $slot) use (&$taken) {
                if ($slot->deleted_at !== null) {
                    return;
                }

                $key = mb_strtolower(trim((string) $slot->name));

                if (isset($taken[$key])) {
                    $key = mb_substr($key, 0, 70).'-'.$slot->id;
                }

                $taken[$key] = true;

                DB::table('attendance_slots')->where('id', $slot->id)->update(['name_key' => $key]);
            });
    }
};
