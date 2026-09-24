<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs the new "master report" flow (see BuildMasterRosterReport /
     * StartMasterReportGeneration): one combined export spanning every
     * selected event side-by-side, generated from the Reports screen
     * itself rather than from inside a single event.
     *
     * event_ids stores the ordered list of every event id the export
     * actually spans, in the same left-to-right order they appear as
     * column groups in the output. event_id itself is deliberately left
     * alone (still required) — a master row just points it at the first
     * event in event_ids, purely so the existing foreign key/eager-load
     * on `event` keeps working unchanged; event_ids is what every
     * master-aware reader (BuildMasterRosterReport, the controller,
     * the frontend) actually looks at. This sidesteps needing
     * doctrine/dbal (required for Blueprint::change()) just to relax a
     * NOT NULL constraint.
     */
    public function up(): void
    {
        Schema::table('report_generations', function (Blueprint $table) {
            $table->json('event_ids')->nullable()->after('event_id');
        });
    }

    public function down(): void
    {
        Schema::table('report_generations', function (Blueprint $table) {
            $table->dropColumn('event_ids');
        });
    }
};
