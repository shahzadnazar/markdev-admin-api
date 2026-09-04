<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The absence fine, kept apart from the late-payment one.
 *
 * `invoices.fine_amount` already exists and already means the defaulter fine
 * SweepInstallments accrues on an overdue installment. The absence fine is a
 * different charge for a different reason, so it gets its own columns rather
 * than sharing that one — folding them together would make "fines" on the
 * billing dashboard mean two things at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('absence_fine_amount', 10, 2)->default(0)->after('fine_days');
            $table->decimal('absence_fine_credit', 10, 2)->default(0)->after('absence_fine_amount');
        });

        Schema::create('absence_fine_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // First day of the calendar month this charge covers.
            $table->date('month');
            $table->unsignedInteger('absences')->default(0);
            $table->unsignedInteger('chargeable')->default(0);
            $table->decimal('fine_per_absent', 10, 2)->default(0);
            // What was put on the invoice. Never rewritten: a correction
            // credits the difference back rather than editing what was billed.
            $table->decimal('amount', 10, 2)->default(0);
            $table->decimal('credited_amount', 10, 2)->default(0);
            // Credit owed but with no invoice yet to carry it.
            $table->decimal('pending_credit', 10, 2)->default(0);
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('charged_at')->nullable();
            $table->timestamps();

            // One charge per student per month is what makes re-running the
            // command harmless.
            $table->unique(['user_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absence_fine_charges');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['absence_fine_amount', 'absence_fine_credit']);
        });
    }
};
