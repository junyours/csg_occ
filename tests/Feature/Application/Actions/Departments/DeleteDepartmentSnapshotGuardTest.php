<?php

use App\Application\Actions\Departments\DeleteDepartment;
use App\Domain\Enums\AttendanceStatus;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\Exceptions\DepartmentHasStudentsException;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('refuses to delete a department that only survives as an attendance snapshot', function () {
    $home = Department::create(['name' => 'CCS', 'code' => 'CCS']);
    $old = Department::create(['name' => 'BSIT', 'code' => 'BSIT']);

    // The student has since transferred to CCS, so no live row points at BSIT.
    $student = Student::create([
        'student_number' => '2023000777',
        'last_name' => 'Transfer', 'first_name' => 'Tina',
        'department_id' => $home->id,
        'username' => 'ddsnap2023000777', 'password' => 'password',
        'role' => Role::Student,
    ]);

    $creator = Student::create([
        'student_number' => '2020000777',
        'last_name' => 'Admin', 'first_name' => 'CSG',
        'department_id' => $home->id,
        'username' => 'ddsnapadmin', 'password' => 'password',
        'role' => Role::CsgAdmin,
    ]);

    $event = EventModel::create(['name' => 'Old Event', 'created_by' => $creator->id]);
    $day = EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);
    $session = AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'grace_minutes' => 15,
        'status' => SessionStatus::Ended,
    ]);

    AttendanceRecord::create([
        'session_id' => $session->id,
        'student_id' => $student->id,
        'scanned_at' => now(),
        'status' => AttendanceStatus::Present,
        'department_id' => $old->id,
    ]);

    expect(fn () => (new DeleteDepartment)($old))->toThrow(DepartmentHasStudentsException::class);
    expect(Department::find($old->id))->not->toBeNull();
});
