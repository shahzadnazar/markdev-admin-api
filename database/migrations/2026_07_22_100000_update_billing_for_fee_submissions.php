<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fee-submission workflow: students upload proof of payment, admins review.
 *
 * Status columns move from enums to strings so the sets can grow:
 *  - invoices: open | pending (submission under review) | paid | past_due | void
 *  - transactions: pending | success | rejected | failed | refunded
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('status', 20)->default('open')->change();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('payer_name')->nullable()->after('method_last4');
            $table->string('bank_name')->nullable()->after('payer_name');
            $table->string('reference_no')->nullable()->after('bank_name');
            $table->date('payment_date')->nullable()->after('reference_no');
            $table->text('notes')->nullable()->after('payment_date');
            $table->boolean('submitted_by_student')->default(false)->after('notes');
            $table->text('rejection_reason')->nullable()->after('submitted_by_student');
            $table->foreignId('reviewed_by')->nullable()->after('rejection_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn([
                'payer_name',
                'bank_name',
                'reference_no',
                'payment_date',
                'notes',
                'submitted_by_student',
                'rejection_reason',
                'reviewed_at',
            ]);
        });
    }
};
