<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gives every session a precise "when did this actually end" instant,
     * not just a status flag. EndSession already knows the exact moment
     * it flips a session to Ended — this just persists that moment.
     *
     * This single column is what lets an EVENT-scope exclusion's cascade
     * (student-exclusion-feature-plan.md §3, §6a) be evaluated with a
     * plain timestamp comparison instead of a separate "snapshot" table:
     * a session is covered by an event-level exclusion created at time T
     * exactly when ended_at is null (never ended, whether it existed
     * before T or was created after) or ended_at > T (it ended, but only
     * after the exclusion was already in force). A session that had
     * already ended before T (ended_at <= T) is left untouched — see
     * App\Models\Exclusion::excludedStudentIdsForSession().
     */
    public function up(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->timestamp('ended_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->dropColumn('ended_at');
        });
    }
};
