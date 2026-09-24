<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A student's current-enrollment semester. Nullable/optional (mirrors
     * officer_event_id's pattern) — CreateStudent defaults it to the
     * currently active semester when omitted, but the column itself
     * doesn't enforce that so older rows and edge cases (no active
     * semester yet) don't need a backfill migration to stay valid.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('semester_id')->nullable()->after('officer_event_id')
                ->constrained('semesters')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('semester_id');
        });
    }
};
