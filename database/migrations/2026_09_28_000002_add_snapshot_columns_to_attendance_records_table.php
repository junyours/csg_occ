<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Nullable enrollment snapshot; readers COALESCE to the live student row. */
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('scanned_by')
                ->constrained('departments')->restrictOnDelete();
            $table->string('major')->nullable()->after('department_id');
            $table->string('section')->nullable()->after('major');
            $table->string('year_level')->nullable()->after('section');
            $table->foreignId('semester_id')->nullable()->after('year_level')
                ->constrained('semesters')->nullOnDelete();
            $table->boolean('auto_present')->default(false)->after('semester_id');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('semester_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn(['major', 'section', 'year_level', 'auto_present']);
        });
    }
};
