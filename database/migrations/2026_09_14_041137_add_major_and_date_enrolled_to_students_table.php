<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The new section-file naming convention (COURSE-MAJOR-YEARLETTER,
     * e.g. "BSBA-FM-1H") encodes a sub-major (FM/MM under BSBA, ENG under
     * BSED) that BEED/BSIT sections never have. Kept as a free string
     * rather than its own lookup table — a major only ever exists in the
     * context of one department, so "BSBA has FM and MM" doesn't need
     * normalizing any more than section letters do.
     *
     * date_enrolled mirrors the new import template's "Date Enrolled"
     * column, replacing the old template's "suffix" column.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('major')->nullable()->after('department_id');
            $table->date('date_enrolled')->nullable()->after('section');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['major', 'date_enrolled']);
        });
    }
};
