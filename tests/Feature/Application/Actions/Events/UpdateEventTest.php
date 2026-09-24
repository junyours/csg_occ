<?php

use App\Application\Actions\Events\UpdateEvent;
use App\Domain\Enums\EventStatus;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Models\Department;
use App\Models\EventModel;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ueDept(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function ueAdmin(): Student
{
    return Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => ueDept()->id,
            'username' => 'csgadmin', 'password' => 'password',
            'role' => 'csg_admin',
        ],
    );
}

function ueEvent(array $overrides = []): EventModel
{
    return EventModel::create(array_merge([
        'name' => 'Intramurals 2026', 'description' => 'Original description', 'created_by' => ueAdmin()->id,
    ], $overrides));
}

it('updates the event name and description', function () {
    $event = ueEvent();

    $updated = (new UpdateEvent)($event, ['name' => 'Renamed Event', 'description' => 'New description']);

    expect($updated->name)->toBe('Renamed Event')
        ->and($updated->description)->toBe('New description');
    $event->refresh();
    expect($event->name)->toBe('Renamed Event');
});

it('allows a partial update of only one field', function () {
    $event = ueEvent();

    $updated = (new UpdateEvent)($event, ['name' => 'Only Name Changed']);

    expect($updated->name)->toBe('Only Name Changed')
        ->and($updated->description)->toBe('Original description');
});

it('throws when updating an already-ended event', function () {
    $event = ueEvent(['status' => EventStatus::Ended]);

    (new UpdateEvent)($event, ['name' => 'Should not apply']);
})->throws(EventAlreadyEndedException::class);
