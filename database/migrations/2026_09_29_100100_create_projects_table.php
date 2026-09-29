<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A piece of client work, delivered by exactly one team.
 *
 * ONE TEAM, ONE FOREIGN KEY, not a pivot. MarkDev's teams are separate
 * disciplines; a client who needs web and graphics gets two projects, because
 * a shared project would mean a shared task list and neither team's lead could
 * answer for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();

            // Two projects may share a name — the same job for two clients is
            // "Website Redesign" twice, and renaming one of them to keep a
            // database happy is not something to ask an admin to do.
            $table->string('name', 160);

            // The handle. Unique, set by the admin, and the ONLY thing a team
            // person can use to tell two same-named projects apart, since they
            // never see which client either belongs to.
            $table->string('code', 40);

            /*
             * The normalised code the unique index sits on, exactly as teams
             * and attendance slots do it with their names.
             *
             * Nullable, and cleared when the row is soft-deleted, because an
             * index on `code` alone would reserve a cancelled project's code
             * for ever — and reusing PRJ-014 for the re-signed version of the
             * same job is the normal thing to want.
             */
            $table->string('code_key', 40)->nullable();

            // restrictOnDelete on both: the controller refuses to delete a
            // client that has projects, and this is the same refusal one layer
            // down, where a script that skipped the controller still meets it.
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();

            // Required, and must point at an ACTIVE status. Code branches on
            // the status's BEHAVIOUR, never its label — see ProjectStatus.
            $table->foreignId('project_status_id')->constrained()->restrictOnDelete();

            // Dates, not datetimes, and cast `date:Y-m-d` on the model. Never
            // compared with equality: see App\Models\Concerns\ScopesToDay.
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();

            /*
             * ONE money figure, and it is the contract. There is deliberately
             * no second internal budget column: two numbers for what a project
             * is worth means two answers to "how much is this", and the one
             * that gets quoted is whichever the reader found first.
             *
             * Never shown to a team person. Nullable so a project can be set up
             * before the value is agreed.
             */
            $table->decimal('contract_value', 12, 2)->nullable();

            // Follows the fallback the rest of the codebase already uses:
            // AbsenceFine::currencyFor reads the latest invoice and falls back
            // to PKR, and RuleBook does the same. There is no setting for it,
            // so the column defaults the same way rather than inventing one.
            $table->string('currency', 3)->default('PKR');

            $table->text('description')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('code_key');
            $table->index('team_id');
            $table->index('client_id');
            $table->index('project_status_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
