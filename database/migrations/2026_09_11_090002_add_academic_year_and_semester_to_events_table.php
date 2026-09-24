<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Everything that needs to be scoped by academic year — present/absent/
     * late records, penalties — hangs off an event through
     * event -> event_day -> session, so the event is the single place the
     * academic year (and semester within it) needs to be recorded; nothing
     * downstream needs its own academic_year_id column.
     *
     * Nullable at the DB level (not every driver this app targets — sqlite
     * in dev, mysql on shared hosting — makes backfilling a NOT NULL column
     * painless), but StoreEventRequest requires both fields for every new
     * event going forward.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('academic_year_id')->nullable()->after('description')
                ->constrained('academic_years')->nullOnDelete();
            // enum-backed, cast in the Model (App\Domain\Enums\Semester) —
            // same convention as students.role and attendance_sessions.status.
            $table->string('semester')->nullable()->after('academic_year_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_year_id');
            $table->dropColumn('semester');
        });
    }
};
