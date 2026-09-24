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
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            // e.g. "2026-2027" — free text rather than a start/end-year pair
            // of integers, since it's only ever displayed, never computed on.
            $table->string('name')->unique();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            // Exactly one academic year is "active" at a time — the one the
            // dashboard defaults to and new events are created into. Enforced
            // in ActivateAcademicYear (transactional flip), not a DB
            // constraint, since a partial unique index isn't portable across
            // the sqlite (dev) / mysql (shared hosting) split this app targets.
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by')->constrained('students');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_years');
    }
};
