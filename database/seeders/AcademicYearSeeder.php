<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Student;
use Illuminate\Database\Seeder;

class AcademicYearSeeder extends Seeder
{
    /**
     * Seeds one active academic year so the app has a default scope to
     * boot into (dashboards, new-event forms) without an admin having to
     * create one by hand first. Runs after StudentSeeder — needs a real
     * student to attribute created_by to.
     */
    public function run(): void
    {
        $creator = Student::orderBy('id')->firstOrFail();

        AcademicYear::firstOrCreate(
            ['name' => '2026-2027'],
            [
                'start_date' => '2026-08-01',
                'end_date' => '2027-05-31',
                'is_active' => true,
                'created_by' => $creator->id,
            ],
        );
    }
}
