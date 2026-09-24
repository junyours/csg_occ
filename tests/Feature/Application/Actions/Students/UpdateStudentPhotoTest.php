<?php

use App\Application\Actions\Students\UpdateStudentPhoto;
use App\Domain\Exceptions\PhotoUploadLockedException;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function photoTestDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function photoTestStudent(string $studentNumber, string $role = 'student'): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Test', 'first_name' => 'Student',
        'department_id' => photoTestDept('CCS')->id,
        'username' => 'p'.$studentNumber,
        'password' => 'password',
        'role' => $role,
    ]);
}

function photoTestOngoingSession(): AttendanceSession
{
    // events.created_by is a required fk (a student who created the
    // event); a throwaway CSG admin stands in for whoever set this up.
    $creator = photoTestStudent('2020000099', 'csg_admin');
    $event = EventModel::create(['name' => 'Intramurals', 'created_by' => $creator->id]);
    $day = EventDay::create(['event_id' => $event->id, 'date' => now(), 'day_number' => 1]);

    return AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => 'morning',
        'check_type' => 'time_in',
        'start_time' => '07:00', 'end_time' => '08:00',
        'status' => 'ongoing',
    ]);
}

it('stores a compressed photo and sets photo_path', function () {
    Storage::fake('public');
    $student = photoTestStudent('2020300001');
    $file = UploadedFile::fake()->image('photo.jpg', 1200, 1200)->size(3000);

    $updated = (new UpdateStudentPhoto(app(\Intervention\Image\ImageManager::class)))($student, $file);

    expect($updated->photo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($updated->photo_path);
});

it('deletes the previous photo on re-upload', function () {
    Storage::fake('public');
    $student = photoTestStudent('2020300002');
    $action = new UpdateStudentPhoto(app(\Intervention\Image\ImageManager::class));

    $first = $action($student, UploadedFile::fake()->image('a.jpg', 900, 900));
    $oldPath = $first->photo_path;

    $second = $action($first, UploadedFile::fake()->image('b.jpg', 900, 900));

    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($second->photo_path);
    expect($second->photo_path)->not->toBe($oldPath);
});

it('blocks upload while an eligible session is ongoing', function () {
    Storage::fake('public');
    $student = photoTestStudent('2020300003');
    photoTestOngoingSession();

    (new UpdateStudentPhoto(app(\Intervention\Image\ImageManager::class)))(
        $student,
        UploadedFile::fake()->image('photo.jpg'),
    );
})->throws(PhotoUploadLockedException::class);

it('allows upload when the only ongoing session excludes this student', function () {
    Storage::fake('public');
    $student = photoTestStudent('2020300004');
    $session = photoTestOngoingSession();

    Exclusion::create([
        'student_id' => $student->id,
        'event_id' => $session->eventDay->event_id,
        'scope' => 'event',
        'reason' => 'Testing exclusion',
        'created_by' => $student->id,
    ]);

    $updated = (new UpdateStudentPhoto(app(\Intervention\Image\ImageManager::class)))(
        $student,
        UploadedFile::fake()->image('photo.jpg'),
    );

    expect($updated->photo_path)->not->toBeNull();
});

it('never locks non-student roles even during an ongoing session', function () {
    Storage::fake('public');
    $officer = photoTestStudent('2020200001', 'officer');
    photoTestOngoingSession();

    $updated = (new UpdateStudentPhoto(app(\Intervention\Image\ImageManager::class)))(
        $officer,
        UploadedFile::fake()->image('photo.jpg'),
    );

    expect($updated->photo_path)->not->toBeNull();
});
