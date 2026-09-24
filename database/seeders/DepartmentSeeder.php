<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Spec §3: seeded by default; CSG Admin adds more from here on via
     * POST /api/departments.
     *
     * EDUC was a placeholder before the real College of Teacher Education
     * course codes were known — the section files (e.g. "BEED-1A.xlsx",
     * "BSED-ENG-2C.xlsx") show it's actually two separate courses, BEED
     * and BSED, each course code being the first hyphen-separated segment
     * of its section filenames.
     */
    public function run(): void
    {
        foreach ([
            ['code' => 'BEED', 'name' => 'Bachelor of Elementary Education'],
            ['code' => 'BSBA', 'name' => 'Bachelor of Science in Business Administration'],
            ['code' => 'BSED', 'name' => 'Bachelor of Secondary Education'],
            ['code' => 'BSIT', 'name' => 'Bachelor of Science in Information Technology'],
        ] as $department) {
            Department::firstOrCreate(['code' => $department['code']], $department);
        }
    }
}
