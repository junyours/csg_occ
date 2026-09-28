<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit trail (same idea as role_assignments and
     * penalty_reversals): one row each time an admin resets a student's
     * password to the default. Never updated or deleted.
     */
    public function up(): void
    {
        Schema::create('student_password_resets', function (Blueprint $table) {
            $table->id();
            // Whose password was reset.
            $table->foreignId('student_id')->constrained('students');
            // The admin who did it.
            $table->foreignId('reset_by')->constrained('students');
            $table->timestamps();

            $table->index(['student_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_password_resets');
    }
};
