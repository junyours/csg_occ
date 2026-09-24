<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets a roster/master report be narrowed to a single major (e.g.
     * "BSBA students, FM major only"), the same way it can already be
     * narrowed by department/year_level/section.
     */
    public function up(): void
    {
        Schema::table('report_generations', function (Blueprint $table) {
            $table->string('major')->nullable()->after('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('report_generations', function (Blueprint $table) {
            $table->dropColumn('major');
        });
    }
};
