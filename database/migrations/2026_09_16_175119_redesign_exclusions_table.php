<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces the original MVP exclusions shape (event|window_type|session
     * scope, hard delete, no reason) with the shape in
     * student-exclusion-feature-plan.md §3:
     *
     *  - scope is now event | day | window (window = one specific
     *    day + window_type, e.g. "Day 2 Morning" — covering whichever of
     *    that window's time-in/time-out AttendanceSession rows exist).
     *    The old `session`-scope concept (locking to one single check,
     *    e.g. just the time-in half of a window) is dropped and the old
     *    `window_type`-scope concept (every session of a recurring type
     *    across every day) is also dropped; the plan never describes
     *    either, only Event/Day/Window.
     *  - event_day_id replaces the bare session_id: day-scope stores it
     *    alone, window-scope stores it alongside window_type, event-scope
     *    leaves both null.
     *  - reason is required (§5.3).
     *  - status (active|removed) replaces hard deletion, so an ended
     *    day/window's exclusion history is never actually erased (§6) —
     *    RemoveExclusion sets this instead of deleting the row.
     *  - removed_by / removed_at record who removed an exclusion and when
     *    (§4's audit trail: "added by, added at" — this is the mirror for
     *    removal).
     *  - batch_id groups a bulk-upload's rows together (§5, §9.5).
     *
     * No production data exists yet for this feature (it shipped as an
     * unused MVP stub — see plan discussion), so this drops and rebuilds
     * the table wholesale rather than attempting a column-by-column
     * migration of the old scope values.
     */
    public function up(): void
    {
        Schema::dropIfExists('exclusions');

        Schema::create('exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('event_id')->constrained();

            $table->string('scope'); // event|day|window
            $table->foreignId('event_day_id')->nullable()->constrained('event_days')->nullOnDelete();
            $table->string('window_type')->nullable(); // required only when scope=window

            $table->text('reason');

            $table->string('status')->default('active'); // active|removed

            $table->uuid('batch_id')->nullable();

            $table->foreignId('created_by')->constrained('students');
            $table->foreignId('removed_by')->nullable()->constrained('students');
            $table->timestamp('removed_at')->nullable();

            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index(['event_day_id', 'window_type']);
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exclusions');

        // Best-effort restore of the original shape, matching
        // 2026_09_09_133054_create_exclusions_table.php, so rolling back
        // this migration doesn't leave the schema in a state neither
        // migration actually describes.
        Schema::create('exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('event_id')->constrained();
            $table->string('scope');
            $table->string('window_type')->nullable();
            $table->foreignId('session_id')->nullable()->constrained('attendance_sessions');
            $table->foreignId('created_by')->constrained('students');
            $table->timestamps();
        });

        // No data migration back is attempted (see class docblock) — this
        // statement just documents that the down() path is intentionally
        // schema-only.
        DB::statement('SELECT 1');
    }
};
