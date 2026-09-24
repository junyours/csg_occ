<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // Mirrors sc_admin_department_id's pattern (spec §4.4: an
            // Officer's event scope is optional, hence nullable — unlike
            // SC Admin's department, which is required).
            $table->foreignId('officer_event_id')->nullable()->after('sc_admin_department_id')
                ->constrained('events');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('officer_event_id');
        });
    }
};
