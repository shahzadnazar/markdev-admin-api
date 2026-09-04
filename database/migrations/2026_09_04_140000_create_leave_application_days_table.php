<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The decision on each day of a leave application.
 *
 * A reviewer can approve part of a range and decline the rest, so one status
 * on the application can no longer say what was decided. It stays as a rollup
 * for lists and filters; this table is what the register and the student read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_application_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_application_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 12); // approved | declined
            $table->timestamps();

            $table->unique(['leave_application_id', 'date']);
            $table->index(['date', 'status']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_application_days');
    }

    /**
     * Applications reviewed before per-day decisions existed were decided in
     * one piece, so every day carries the application's own verdict. Pending
     * ones get no rows: nothing has been decided about them yet.
     */
    protected function backfill(): void
    {
        DB::table('leave_applications')
            ->whereIn('status', ['approved', 'rejected'])
            ->orderBy('id')
            ->each(function (object $leave) {
                $status = $leave->status === 'approved' ? 'approved' : 'declined';
                $day = Carbon::parse($leave->from_date)->startOfDay();
                $last = Carbon::parse($leave->to_date)->startOfDay();
                $rows = [];

                while ($day->lessThanOrEqualTo($last)) {
                    $rows[] = [
                        'leave_application_id' => $leave->id,
                        'date' => $day->toDateString(),
                        'status' => $status,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $day->addDay();
                }

                if ($rows !== []) {
                    DB::table('leave_application_days')->insert($rows);
                }
            });
    }
};
