<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A LEDGER, not billing.
 *
 * There is no fee concept in the team portal and there are no invoices for
 * staff. AbsenceFineCharge was checked and is coupled to them — it carries an
 * invoice_id, has an invoice() relation, and AbsenceFine::charge places the
 * row on the student's next bill — so extending it would have dragged the
 * billing system into the team portal to hold a number somebody reads off a
 * screen. This table records what is owed and whether it was settled, and
 * nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_absence_fines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The first of the month, cast `date:Y-m-d`.
            $table->date('month');

            // Snapshotted, not looked up later: a row says what the rules were
            // when it was written, so changing the rate next quarter does not
            // silently rewrite last quarter's ledger.
            $table->unsignedInteger('allowance');
            $table->unsignedInteger('absences');
            $table->unsignedInteger('chargeable');
            $table->decimal('rate', 10, 2);
            $table->decimal('total', 12, 2);

            $table->date('settled_on')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();

            // One row per person per month, which is what makes the charge
            // command safe to re-run.
            $table->unique(['user_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_absence_fines');
    }
};
