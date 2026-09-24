<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which departments an event is open to. An event with zero rows here
     * is unrestricted — every department is included (see
     * EventModel::includedDepartmentIds) — which also means every event
     * created before this migration keeps behaving exactly as before.
     * CreateEvent always writes the explicit set chosen on the creation
     * form (defaulting to every department at the time of creation, but
     * still an explicit list, not "all" as a special case), so this table
     * only ever ends up empty for pre-existing events, never for new ones.
     */
    public function up(): void
    {
        Schema::create('event_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_departments');
    }
};
