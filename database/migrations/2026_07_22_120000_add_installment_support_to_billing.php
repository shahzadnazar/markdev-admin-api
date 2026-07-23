<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly installment plans:
 *  - a plan can be split into N monthly invoices generated from the
 *    admission date, all due on a chosen day of the month
 *  - each invoice activates (becomes payable) a few days before it is due,
 *    then passes through a grace window before the student defaults
 *  - defaulters accrue a per-day fine on top of the installment amount
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_plans', function (Blueprint $table) {
            $table->unsignedTinyInteger('installment_months')->nullable()->after('billing_cycle');
            $table->unsignedTinyInteger('due_day')->nullable()->after('installment_months');
            $table->decimal('fine_per_day', 8, 2)->nullable()->after('due_day');
            $table->date('starts_at')->nullable()->after('fine_per_day');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedTinyInteger('sequence_no')->nullable()->after('number');
            $table->date('activates_at')->nullable()->after('issued_at');
            $table->decimal('fine_amount', 10, 2)->default(0)->after('amount');
            $table->unsignedInteger('fine_days')->default(0)->after('fine_amount');
            $table->timestamp('grace_notified_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['sequence_no', 'activates_at', 'fine_amount', 'fine_days', 'grace_notified_at']);
        });

        Schema::table('fee_plans', function (Blueprint $table) {
            $table->dropColumn(['installment_months', 'due_day', 'fine_per_day', 'starts_at']);
        });
    }
};
