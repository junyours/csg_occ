<?php

namespace Database\Seeders;

use App\Domain\Enums\Semester as SemesterEnum;
use App\Models\AcademicYear;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Database\Seeder;

class SemesterSeeder extends Seeder
{
    /**
     * Seeds all three terms under the default academic year, with
     * semester_1 active, so events/students have a real semester to
     * point at without an admin creating one by hand first. Runs after
     * AcademicYearSeeder (needs the AY to already exist) and after
     * StudentSeeder (needs a creator, and backfills existing students).
     */
    public function run(): void
    {
        $academicYear = AcademicYear::where('is_active', true)->orderByDesc('id')->firstOrFail();
        $creator = Student::orderBy('id')->firstOrFail();

        $active = null;

        foreach ([SemesterEnum::First, SemesterEnum::Second, SemesterEnum::Summer] as $name) {
            $semester = Semester::firstOrCreate(
                ['academic_year_id' => $academicYear->id, 'name' => $name->value],
                [
                    'is_active' => $name === SemesterEnum::First,
                    'created_by' => $creator->id,
                ],
            );

            if ($name === SemesterEnum::First) {
                $active = $semester;
            }
        }

        // Every student seeded before this ran (StudentSeeder creates
        // rows directly, bypassing CreateStudent's own default) gets
        // enrolled into the active semester so nothing is left scopeless.
        if ($active) {
            Student::whereNull('semester_id')->update(['semester_id' => $active->id]);
        }
    }
}
