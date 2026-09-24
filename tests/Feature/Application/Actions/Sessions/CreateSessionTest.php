<?php

use App\Application\Actions\Sessions\CreateSession;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\EventStatus;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a scheduled session with defaults applied for omitted fields', function () {
    $department = Department::create(['name' => 'CCS', 'code' => 'CCS']);
    $admin = Student::create([
        'student_number' => '2020000001',
        'last_name' => 'Admin', 'first_name' => 'CSG',
        'department_id' => $department->id,
        'username' => 'csgadmin', 'password' => 'password',
        'role' => 'csg_admin',
    ]);
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);
    $day = EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);

    $session = (new CreateSession)($day, [
        'window_type' => 'morning',
        'check_type' => 'time_in',
        'start_time' => '07:00',
        'end_time' => '08:00',
    ]);

    expect($session->event_day_id)->toBe($day->id)
        ->and($session->window_type)->toBe(WindowType::Morning)
        ->and($session->check_type)->toBe(CheckType::TimeIn)
        ->and($session->status)->toBe(SessionStatus::Scheduled)
        ->and($session->grace_minutes)->toBe(0)
        ->and((float) $session->penalty_late_amount)->toBe(0.0);
});

it('throws and creates nothing when the parent event has already ended', function () {
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
    $day = EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);

    expect(fn () => (new CreateSession)($day, [
        'window_type' => 'morning',
        'check_type' => 'time_in',
        'start_time' => '07:00',
        'end_time' => '08:00',
    ]))->toThrow(EventAlreadyEndedException::class);

    expect($day->sessions()->count())->toBe(0);
});
