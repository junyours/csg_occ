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
        Schema::create('penalty_reversals', function (Blueprint $table) {
            $table->id();
            // No cascade on delete: penalties are never deleted in this
            // app (see AttendancePenalty usage — only ever created or
            // updated), so this is a straightforward FK, not a decision
            // about what should happen on a delete that doesn't occur.
            $table->foreignId('attendance_penalty_id')->constrained('attendance_penalties');
            // Denormalized alongside attendance_penalty_id, same as
            // role_assignments denormalizes student_id next to the
            // change itself — lets "every reversal for this student"
            // be queried without joining through attendance_penalties.
            $table->foreignId('student_id')->constrained('students');
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
        Schema::dropIfExists('penalty_reversals');
    }
};
