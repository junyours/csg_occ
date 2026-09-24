<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Replaces events.academic_year_id + events.semester (a plain string)
     * with a single events.semester_id pointing at the new semesters
     * table. Any event created before this migration ran is backfilled
     * onto a matching (or newly created) Semester row so existing data
     * keeps its scope instead of silently going null.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('semester_id')->nullable()->after('description')
                ->constrained('semesters')->nullOnDelete();
        });

        $events = DB::table('events')
            ->whereNotNull('academic_year_id')
            ->whereNotNull('semester')
            ->get(['id', 'academic_year_id', 'semester', 'created_by']);

        // Cache created semesters per (academic_year_id, semester) pair so
        // a file with many events in the same term only creates one row.
        $semesterIdsByKey = [];

        foreach ($events as $event) {
            $key = $event->academic_year_id.'|'.$event->semester;

            if (! isset($semesterIdsByKey[$key])) {
                $existing = DB::table('semesters')
                    ->where('academic_year_id', $event->academic_year_id)
                    ->where('name', $event->semester)
                    ->first();

                if ($existing) {
                    $semesterIdsByKey[$key] = $existing->id;
                } else {
                    $semesterIdsByKey[$key] = DB::table('semesters')->insertGetId([
                        'academic_year_id' => $event->academic_year_id,
                        'name' => $event->semester,
                        'is_active' => false,
                        'created_by' => $event->created_by,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('events')->where('id', $event->id)->update([
                'semester_id' => $semesterIdsByKey[$key],
            ]);
        }

        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_year_id');
            $table->dropColumn('semester');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('academic_year_id')->nullable()->after('description')
                ->constrained('academic_years')->nullOnDelete();
            $table->string('semester')->nullable()->after('academic_year_id');
        });

        $events = DB::table('events')->whereNotNull('semester_id')->get(['id', 'semester_id']);
        $semesters = DB::table('semesters')->get(['id', 'academic_year_id', 'name'])->keyBy('id');

        foreach ($events as $event) {
            $semester = $semesters->get($event->semester_id);

            if ($semester) {
                DB::table('events')->where('id', $event->id)->update([
                    'academic_year_id' => $semester->academic_year_id,
                    'semester' => $semester->name,
                ]);
            }
        }

        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('semester_id');
        });
    }
};
