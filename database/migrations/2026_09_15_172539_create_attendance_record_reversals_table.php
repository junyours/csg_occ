<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attendance_record_reversals', function (Blueprint $table) {
            $table->id();

            // No cascade on delete: same reasoning as penalty_reversals —
            // this is an append-only audit row, and the session/student it
            // references outlive the attendance_records row it's recording
            // the deletion of (see ReverseAttendanceRecord).
            $table->foreignId('session_id')->constrained('attendance_sessions');
            $table->foreignId('student_id')->constrained('students');

            // A snapshot of what was deleted, not a live reference — the
            // attendance_records row itself is gone by the time this
            // exists (contrast attendance_penalties, which stays and is
            // merely flagged). Kept here so "what was this student wrongly
            // marked as, and when" survives independently of the row.
            $table->string('status');
            $table->timestamp('scanned_at')->nullable();
            $table->foreignId('scanned_by')->nullable()->constrained('students');

            $table->foreignId('reversed_by')->constrained('students');
            $table->string('reason');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_record_reversals');
    }
};
