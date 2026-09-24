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
        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_day_id')->constrained()->cascadeOnDelete();
            $table->string('window_type'); // morning|afternoon|evening
            $table->string('check_type'); // time_in|time_out — each session is one check only

            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('grace_minutes')->default(0);

            $table->decimal('penalty_late_amount', 8, 2)->default(0);
            $table->decimal('penalty_absent_amount', 8, 2)->default(0);

            $table->string('status')->default('scheduled'); // scheduled|ongoing|ended
            $table->timestamps();

            // A window can have both a time-in and a time-out session (two
            // rows, same window_type), but never two of the same check.
            $table->unique(['event_day_id', 'window_type', 'check_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_sessions');
    }
};
