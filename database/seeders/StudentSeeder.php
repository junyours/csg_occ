<?php

namespace Database\Seeders;

use App\Domain\Enums\Role;
use App\Domain\ValueObjects\QrPayload;
use App\Models\Department;
use App\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StudentSeeder extends Seeder
{
    private const TEST_PASSWORD = 'password123';

    public function run(): void
    {
        $bsit = Department::where('code', 'BSIT')->firstOrFail();

        $this->makeStudent([
            'student_number' => '9090-9-90909',
            'last_name' => 'Reyes',
            'first_name' => 'Sofia',
            'department_id' => $bsit->id,
            'year_level' => '4',
            'section' => '4A',
            'role' => Role::SystemAdmin,
        ]);
    }

    /**
     * @param array{
     *     student_number: string,
     *     last_name: string,
     *     first_name: string,
     *     department_id: int,
     *     year_level: string,
     *     section: string,
     *     role: Role,
     *     sc_admin_department_id?: int,
     *     officer_event_id?: int,
     * } $data
     */
    private function makeStudent(array $data): Student
    {
        $student = Student::updateOrCreate(
            ['student_number' => $data['student_number']],
            [
                'last_name' => $data['last_name'],
                'first_name' => $data['first_name'],
                'department_id' => $data['department_id'],
                'year_level' => $data['year_level'],
                'section' => $data['section'],
                'role' => $data['role'],
                'sc_admin_department_id' => $data['sc_admin_department_id'] ?? null,
                'officer_event_id' => $data['officer_event_id'] ?? null,
                'username' => $data['student_number'],
                'password' => Hash::make(self::TEST_PASSWORD),
                'must_change_password' => false,
            ],
        );

        $student->refresh();

        $student->update([
            'qr_token' => QrPayload::forStudent(
                $student->student_number,
                $student->qr_version
            )->encrypt(),
        ]);

        return $student;
    }
}
