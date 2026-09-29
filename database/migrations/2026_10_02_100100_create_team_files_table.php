<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files attached to a project or a task.
 *
 * ONE TABLE, polymorphic owner. One table means one set of upload rules, one
 * private-disk path and one deletion story covering both — three things that
 * would otherwise drift the first time somebody changed one of them.
 *
 * ON THE PRIVATE DISK, ALWAYS. A student's photograph was once served to
 * anyone who guessed the URL, fixed in 30309b4 by moving the sensitive kinds
 * off the public disk entirely; `team-files` joins PrivateFiles::PRIVATE_PREFIXES
 * and is reached only through FileController, which asks whether this viewer
 * can see the owning project or task before streaming a byte. A signature
 * proves identity, never permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_files', function (Blueprint $table) {
            $table->id();

            // A project or a task. Nothing else attaches files in this phase,
            // and a new owner type is a decision somebody makes rather than a
            // default they inherit.
            $table->morphs('owner');

            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 160)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Set ONLY by an admin or super-admin.
             *
             * A team-lead and a team member may upload and may not decide what
             * a client sees — which matches milestones, whose routes are
             * already admin-only, so the two answers agree. Nothing renders
             * this until the client portal in phase 6.
             */
            $table->boolean('is_client_visible')->default(false);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_files');
    }
};
