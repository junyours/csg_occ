<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendance_penalties', function (Blueprint $table) {
            // `is_reversed` and `reversed_by` already existed but nothing
            // ever wrote to them (spec §7.3 gap). Alongside the endpoint
            // that finally sets them, these two columns round out what's
            // needed to show *when* and *why* a penalty was excused,
            // without joining out to the penalty_reversals audit table
            // for every read (BuildMyPenaltyHistory, reports, etc. all
            // read straight off this row today).
            $table->string('reversal_reason')->nullable()->after('reversed_by');
            $table->timestamp('reversed_at')->nullable()->after('reversal_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_penalties', function (Blueprint $table) {
            $table->dropColumn(['reversal_reason', 'reversed_at']);
        });
    }
};
