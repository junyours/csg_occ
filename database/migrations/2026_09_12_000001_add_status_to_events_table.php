<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An event's own lifecycle (ongoing → ended) is separate from any
     * individual session's status. Without this column, the frontend had
     * to *infer* "is this event still going on?" from whether any of its
     * sessions happened to be `ongoing` — which meant a CSG Admin merely
     * ending a session (e.g. the Day 1 Morning window closes) made the
     * whole event look "finished" even though Afternoon/Evening — or Day
     * 2 — hadn't happened yet. Every event now carries its own explicit
     * status, defaulting to `ongoing` at creation (mirrors
     * attendance_sessions.status's default-at-creation pattern) and only
     * moving to `ended` via an explicit CSG action (see EndEvent).
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('status')->default('ongoing')->after('description'); // ongoing|ended
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
