<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A Semester is now its own manageable entity (create/edit/activate/
     * deactivate — same shape as AcademicYear), scoped to exactly one
     * academic year. Events point at a semester (not academic_year_id +
     * a semester string directly), so "all data needs to point to the
     * academic year" now flows: event -> semester -> academic_year.
     */
    public function up(): void
    {
        Schema::create('semesters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            // enum-backed (App\Domain\Enums\Semester), cast in the Model —
            // same convention as students.role and events.semester used to be.
            $table->string('name');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            // Exactly one semester is "active" *within its academic year*
            // at a time (mirrors AcademicYear.is_active), flipped
            // atomically in ActivateSemester. Not a DB constraint for the
            // same portability reason as academic_years.is_active.
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by')->constrained('students');
            $table->timestamps();

            // A given term (semester_1/semester_2/summer) can only exist
            // once per academic year.
            $table->unique(['academic_year_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('semesters');
    }
};
