<?php

use App\Application\Actions\Semesters\ActivateSemester;
use App\Application\Actions\Semesters\DeactivateSemester;
use App\Domain\Enums\EventStatus;
use App\Domain\Enums\Role;
use App\Domain\Exceptions\AcademicYearNotActiveException;
use App\Domain\Exceptions\EventsStillOngoingException;
use App\Domain\Exceptions\SemesterAlreadyActiveException;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\EventModel;
use App\Models\RoleAssignment;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function rolloverAccount(string $number, Role $role, array $extra = []): Student
{
    $department = Department::firstOrCreate(['code' => 'CCS'], ['name' => 'CCS']);

    return Student::firstOrCreate(['student_number' => $number], array_merge([
        'last_name' => 'Person', 'first_name' => $number,
        'department_id' => $department->id,
        'username' => $number, 'password' => 'password',
        'role' => $role,
    ], $extra));
}

function rolloverSemester(Student $admin, bool $yearActive = true, bool $active = true, string $name = 'semester_1'): Semester
{
    $year = AcademicYear::firstOrCreate(['name' => '2026-2027'], ['is_active' => $yearActive, 'created_by' => $admin->id]);

    return Semester::create(['academic_year_id' => $year->id, 'name' => $name, 'is_active' => $active, 'created_by' => $admin->id]);
}

it('activates a semester when none is active, without sweeping anybody', function () {
    $admin = rolloverAccount('SYS-1', Role::SystemAdmin);
    $csg = rolloverAccount('CSG-1', Role::CsgAdmin);
    $semester = rolloverSemester($admin, active: false);

    expect((new ActivateSemester)($semester)->is_active)->toBeTrue()
        ->and($csg->fresh()->role)->toBe(Role::CsgAdmin)
        ->and($csg->fresh()->status)->toBe('active');
});

it('refuses to activate while another semester is active', function () {
    $admin = rolloverAccount('SYS-1', Role::SystemAdmin);
    $old = rolloverSemester($admin);
    $new = rolloverSemester($admin, active: false, name: 'semester_2');

    expect(fn () => (new ActivateSemester)($new))->toThrow(SemesterAlreadyActiveException::class)
        ->and($old->fresh()->is_active)->toBeTrue()
        ->and($new->fresh()->is_active)->toBeFalse();
});

it('refuses to activate a semester whose academic year is not active', function () {
    $admin = rolloverAccount('SYS-1', Role::SystemAdmin);
    $semester = rolloverSemester($admin, yearActive: false, active: false);

    (new ActivateSemester)($semester);
})->throws(AcademicYearNotActiveException::class);

it('deactivating sweeps every non-System-Admin account and revokes their tokens', function () {
    $admin = rolloverAccount('SYS-1', Role::SystemAdmin);
    $semester = rolloverSemester($admin);
    $department = Department::firstOrCreate(['code' => 'CCS'], ['name' => 'CCS']);
    $csg = rolloverAccount('CSG-1', Role::CsgAdmin);
    $sc = rolloverAccount('SC-1', Role::ScAdmin, ['sc_admin_department_id' => $department->id]);
    $officer = rolloverAccount('OFF-1', Role::Officer);
    $plain = rolloverAccount('STU-1', Role::Student);
    foreach ([$csg, $plain, $admin] as $account) {
        $account->createToken('api');
    }

    $result = (new DeactivateSemester)($semester, $admin);

    expect($result->is_active)->toBeFalse()
        ->and($csg->fresh()->role)->toBe(Role::Student)
        ->and($sc->fresh()->role)->toBe(Role::Student)
        ->and($sc->fresh()->sc_admin_department_id)->toBeNull()
        ->and($officer->fresh()->role)->toBe(Role::Student)
        ->and(Student::where('id', '!=', $admin->id)->where('status', 'active')->count())->toBe(0)
        ->and($admin->fresh()->status)->toBe('active')
        ->and($admin->fresh()->role)->toBe(Role::SystemAdmin)
        ->and($csg->tokens()->count())->toBe(0)
        ->and($plain->tokens()->count())->toBe(0)
        ->and($admin->tokens()->count())->toBe(1)
        ->and(RoleAssignment::count())->toBe(3)
        ->and(RoleAssignment::where('student_id', $csg->id)->first()->changed_by)->toBe($admin->id);
});

it('does nothing when deactivating an already-inactive semester', function () {
    $admin = rolloverAccount('SYS-1', Role::SystemAdmin);
    $csg = rolloverAccount('CSG-1', Role::CsgAdmin);
    $semester = rolloverSemester($admin, active: false);

    (new DeactivateSemester)($semester, $admin);

    expect($csg->fresh()->role)->toBe(Role::CsgAdmin)->and(RoleAssignment::count())->toBe(0);
});

it('refuses to deactivate while an event is ongoing, naming it, and sweeps nothing', function () {
    $admin = rolloverAccount('SYS-1', Role::SystemAdmin);
    $semester = rolloverSemester($admin);
    EventModel::create(['name' => 'Foundation Day', 'created_by' => $admin->id, 'semester_id' => $semester->id]);
    EventModel::create(['name' => 'Old Event', 'created_by' => $admin->id, 'semester_id' => $semester->id, 'status' => EventStatus::Ended]);
    $csg = rolloverAccount('CSG-1', Role::CsgAdmin);

    try {
        (new DeactivateSemester)($semester, $admin);
        $this->fail('Expected EventsStillOngoingException.');
    } catch (EventsStillOngoingException $e) {
        expect($e->eventNames())->toBe(['Foundation Day'])
            ->and($semester->fresh()->is_active)->toBeTrue()
            ->and($csg->fresh()->role)->toBe(Role::CsgAdmin)
            ->and($csg->fresh()->status)->toBe('active');
    }
});
