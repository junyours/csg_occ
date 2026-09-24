<?php

use App\Application\Actions\Events\CreateEventDay;
use App\Domain\Enums\EventStatus;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Models\Department;
use App\Models\EventModel;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a day under the given event', function () {
    $department = Department::create(['name' => 'CCS', 'code' => 'CCS']);
    $admin = Student::create([
        'student_number' => '2020000001',
        'last_name' => 'Admin', 'first_name' => 'CSG',
        'department_id' => $department->id,
        'username' => 'csgadmin', 'password' => 'password',
        'role' => 'csg_admin',
    ]);
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);

    $day = (new CreateEventDay)($event, ['date' => '2026-11-10', 'day_number' => 1]);

    expect($day->event_id)->toBe($event->id)
        ->and($day->day_number)->toBe(1)
        ->and($day->date->format('Y-m-d'))->toBe('2026-11-10');
});

it('throws and creates nothing when the event has already ended', function () {
    $department = Department::create(['name' => 'CCS', 'code' => 'CCS']);
    $admin = Student::create([
        'student_number' => '2020000001',
        'last_name' => 'Admin', 'first_name' => 'CSG',
        'department_id' => $department->id,
        'username' => 'csgadmin', 'password' => 'password',
        'role' => 'csg_admin',
    ]);
    $event = EventModel::create([
        'name' => 'Intramurals 2026', 'created_by' => $admin->id, 'status' => EventStatus::Ended,
    ]);

    expect(fn () => (new CreateEventDay)($event, ['date' => '2026-11-10', 'day_number' => 1]))
        ->toThrow(EventAlreadyEndedException::class);

    expect($event->days()->count())->toBe(0);
});
