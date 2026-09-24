<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * There's no generic "test user" concept in this app (every account is
     * a Student row per spec §2), so this stays a thin dispatcher to
     * feature-specific seeders rather than creating rows directly.
     */
    public function run(): void
    {
        $this->call(DepartmentSeeder::class);
        $this->call(StudentSeeder::class);
        $this->call(AcademicYearSeeder::class);
        $this->call(SemesterSeeder::class);
    }
}
