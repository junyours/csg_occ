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
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('attendance_sessions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained();

            $table->timestamp('scanned_at')->nullable();
            $table->string('status'); // present|late|absent|excluded
            $table->foreignId('scanned_by')->nullable()->constrained('students');

            $table->timestamps();

            $table->unique(['session_id', 'student_id']); // enforces idempotent scans
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
