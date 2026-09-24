<?php

use App\Application\Actions\Events\CreateEvent;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createEventAdmin(): Student
{
    $department = Department::firstOrCreate(['code' => 'CCS'], ['name' => 'CCS']);

    return Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => $department->id,
            'username' => 'csgadmin', 'password' => 'password',
            'role' => 'csg_admin',
        ],
    );
}

function createEventActiveSemester(Student $createdBy): Semester
{
    $academicYear = AcademicYear::firstOrCreate(
        ['name' => '2026-2027'],
        ['is_active' => true, 'created_by' => $createdBy->id],
    );

    return Semester::firstOrCreate(
        ['academic_year_id' => $academicYear->id, 'name' => 'semester_1'],
        ['is_active' => true, 'created_by' => $createdBy->id],
    );
}

it('creates an event owned by the given creator', function () {
    $admin = createEventAdmin();
    // The event's semester is resolved from whatever's active — this
    // test isn't about semester scoping, so just make one active.
    createEventActiveSemester($admin);
    $department = Department::first();

    $event = (new CreateEvent)([
        'name' => 'Intramurals 2026',
        'description' => 'Annual sports fest',
        'department_ids' => [$department->id],
    ], $admin);

    expect($event->name)->toBe('Intramurals 2026')
        ->and($event->description)->toBe('Annual sports fest')
        ->and($event->created_by)->toBe($admin->id);
});

it('scopes the created event to the given semester, and transitively to its academic year', function () {
    $admin = createEventAdmin();
    $semester = createEventActiveSemester($admin);
    $department = Department::first();

    $event = (new CreateEvent)([
        'name' => 'Intramurals 2026',
        'semester_id' => $semester->id,
        'department_ids' => [$department->id],
    ], $admin);

    expect($event->semester_id)->toBe($semester->id)
        ->and($event->semester->name)->toBe(\App\Domain\Enums\Semester::First)
        ->and($event->academicYear->id)->toBe($semester->academic_year_id)
        ->and($event->academicYear->name)->toBe('2026-2027')
        ->and($event->academic_year->id)->toBe($semester->academic_year_id);
});

it('syncs the given department_ids onto the created event', function () {
    $admin = createEventAdmin();
    createEventActiveSemester($admin);
    $bsit = Department::firstOrCreate(['code' => 'BSIT'], ['name' => 'BSIT']);
    $bsba = Department::firstOrCreate(['code' => 'BSBA'], ['name' => 'BSBA']);
    $bed = Department::firstOrCreate(['code' => 'BED'], ['name' => 'BEd']);

    $event = (new CreateEvent)([
        'name' => 'BSIT + BEd only Intramurals',
        'department_ids' => [$bsit->id, $bed->id],
    ], $admin);

    expect($event->departments->pluck('id')->sort()->values()->all())
        ->toBe(collect([$bsit->id, $bed->id])->sort()->values()->all())
        ->and($event->includesDepartment($bsit->id))->toBeTrue()
        ->and($event->includesDepartment($bed->id))->toBeTrue()
        ->and($event->includesDepartment($bsba->id))->toBeFalse();
});

it('treats an event with every department checked as unrestricted, same as an empty scope', function () {
    $admin = createEventAdmin();
    createEventActiveSemester($admin);
    $bsit = Department::firstOrCreate(['code' => 'BSIT'], ['name' => 'BSIT']);
    $bsba = Department::firstOrCreate(['code' => 'BSBA'], ['name' => 'BSBA']);

    $event = (new CreateEvent)([
        'name' => 'Open to everyone',
        'department_ids' => [$bsit->id, $bsba->id],
    ], $admin);

    expect($event->includesDepartment($bsit->id))->toBeTrue()
        ->and($event->includesDepartment($bsba->id))->toBeTrue();
});
