<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two status lists: one for tasks, one for projects.
 *
 * LABEL AND BEHAVIOUR ARE SEPARATE COLUMNS, and that is the whole point of
 * these tables. The label is the academy's wording and may be renamed at any
 * time; the behaviour is one of a fixed set the code branches on. Later phases
 * ask "is this task blocked?" and "is this project closed?", and they ask it of
 * `behaviour`, never of `label`.
 *
 * If code matched on the label, renaming "Blocked" to "Waiting on client" would
 * silently stop excluding those days from variance — the same trap as matching
 * the attendance status 'absent' by its display name.
 *
 * The behaviour set is a PHP constant on each model rather than a database enum
 * or a check constraint: the code is what branches on it, so the code is where
 * the list has to be true, and one list in two places is one list too many.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['task_statuses', 'project_statuses'] as $table) {
            Schema::create($table, function (Blueprint $blueprint) {
                $blueprint->id();

                // What the admin calls it. Renamed freely; nothing branches on it.
                $blueprint->string('label', 60);

                // What the code does about it. Validated against the model's
                // BEHAVIOURS — an admin picks one, never invents one.
                $blueprint->string('behaviour', 30);

                // '#RRGGBB', as an <input type="color"> posts it.
                $blueprint->string('colour', 7);

                $blueprint->unsignedInteger('sort_order')->default(0);

                // Retiring a status keeps every row that already points at it.
                // Every behaviour must keep at least one active status, or a
                // board would have a column nothing could ever be moved into.
                $blueprint->boolean('is_active')->default(true);

                $blueprint->timestamps();

                // The "at least one active per behaviour" check is the query
                // this index is for; it runs on every save, toggle and delete.
                $blueprint->index(['behaviour', 'is_active']);
            });
        }

        $now = now();

        /*
         * The defaults, written as literals rather than read from the models.
         *
         * A migration is a historical record: these are the rows the table
         * started life with, and they have to stay those rows even after an
         * academy has renamed every one of them. The BEHAVIOURS the second
         * column draws from are the models' constants — those cannot drift,
         * because the models are what validates against them.
         */
        $tasks = [
            ['To Do', 'open', '#727784'],
            ['In Progress', 'active', '#1D5AA6'],
            ['Blocked', 'blocked', '#BA1A1A'],
            ['Review', 'active', '#6B53C4'],
            ['Completed', 'done', '#0E7B54'],
        ];

        $projects = [
            ['Planning', 'planning', '#727784'],
            ['Active', 'running', '#124389'],
            ['On Hold', 'paused', '#9A6400'],
            ['Completed', 'closed_success', '#0E7B54'],
            ['Cancelled', 'closed_abandoned', '#BA1A1A'],
        ];

        foreach (['task_statuses' => $tasks, 'project_statuses' => $projects] as $table => $rows) {
            $insert = [];

            foreach ($rows as $index => [$label, $behaviour, $colour]) {
                $insert[] = [
                    'label' => $label,
                    'behaviour' => $behaviour,
                    'colour' => $colour,
                    'sort_order' => $index + 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table($table)->insert($insert);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_statuses');
        Schema::dropIfExists('project_statuses');
    }
};
