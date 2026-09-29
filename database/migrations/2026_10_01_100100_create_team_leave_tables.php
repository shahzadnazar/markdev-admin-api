<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team leave — its own tables, the academy's flow.
 *
 * SEPARATE TABLES for the same reason the register is separate, and the reason
 * is stronger here rather than weaker: leave feeds the register, the register
 * feeds the fine, and an academy leave screen listing staff applications would
 * hand an instructor a colleague's medical note. The academy's reviewers are
 * scoped by teaching category, which says nothing about a team.
 *
 * THE FLOW IS NOT DUPLICATED. The per-day decision mechanics — opening a row
 * per expected day, recording a verdict on each, and what the rollup means —
 * live in App\Models\Concerns\DecidesLeavePerDay, extracted from
 * LeaveApplication and used by both. The only thing each model supplies is
 * which days it expects: a student follows their slot, a team member follows
 * the academy's working week and the holiday list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_leave_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->text('reason');

            // pending | approved | partially_approved | rejected — a rollup for
            // lists. The day rows are the answer.
            $table->string('status', 24)->default('pending');

            // Required on any decline, and shown to the member. Somebody told
            // "no" is owed the reason.
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('team_leave_application_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_leave_application_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            // A declined day keeps its row: without one there is no telling a
            // day that was turned down from a day nobody looked at.
            $table->string('status', 20)->default('pending');

            $table->timestamps();

            $table->index(['team_leave_application_id', 'status']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_leave_application_days');
        Schema::dropIfExists('team_leave_applications');
    }
};
