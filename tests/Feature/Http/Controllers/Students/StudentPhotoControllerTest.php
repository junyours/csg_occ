<?php

use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function photoCtrlDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function photoCtrlStudent(string $studentNumber, string $role = 'student', ?int $departmentId = null, ?int $scAdminDepartmentId = null): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Test', 'first_name' => 'Student',
        'department_id' => $departmentId ?? photoCtrlDept('CCS')->id,
        'sc_admin_department_id' => $scAdminDepartmentId,
        'username' => 'pc'.$studentNumber,
        'password' => 'password',
        'role' => $role,
    ]);
}

it('lets a student upload their own photo', function () {
    Storage::fake('public');
    $student = photoCtrlStudent('2020300001');

    $this->actingAs($student, 'sanctum')
        ->post("/api/students/{$student->id}/photo", [
            'photo' => UploadedFile::fake()->image('me.jpg', 900, 900),
        ])
        ->assertStatus(200)
        ->assertJsonPath('photo_url', fn ($url) => ! empty($url));
});

it('rejects a student uploading someone else\'s photo', function () {
    Storage::fake('public');
    $student = photoCtrlStudent('2020300001');
    $other = photoCtrlStudent('2020300002');

    $this->actingAs($student, 'sanctum')
        ->post("/api/students/{$other->id}/photo", [
            'photo' => UploadedFile::fake()->image('other.jpg'),
        ])
        ->assertStatus(403);
});

it('lets a csg admin upload a photo for any student', function () {
    Storage::fake('public');
    $admin = photoCtrlStudent('2020000001', 'csg_admin');
    $target = photoCtrlStudent('2020300001');

    $this->actingAs($admin, 'sanctum')
        ->post("/api/students/{$target->id}/photo", [
            'photo' => UploadedFile::fake()->image('target.jpg'),
        ])
        ->assertStatus(200);
});

it('lets an sc admin upload only within their own department', function () {
    Storage::fake('public');
    $bsit = photoCtrlDept('BSIT');
    $bsba = photoCtrlDept('BSBA');
    $admin = photoCtrlStudent('2020000002', 'sc_admin', $bsit->id, $bsit->id);
    $inDept = photoCtrlStudent('2020300001', 'student', $bsit->id);
    $outsideDept = photoCtrlStudent('2020300002', 'student', $bsba->id);

    $this->actingAs($admin, 'sanctum')
        ->post("/api/students/{$inDept->id}/photo", ['photo' => UploadedFile::fake()->image('a.jpg')])
        ->assertStatus(200);

    $this->actingAs($admin, 'sanctum')
        ->post("/api/students/{$outsideDept->id}/photo", ['photo' => UploadedFile::fake()->image('b.jpg')])
        ->assertStatus(403);
});

it('rejects an oversized or non-image file', function () {
    Storage::fake('public');
    $student = photoCtrlStudent('2020300001');

    $this->actingAs($student, 'sanctum')
        ->post("/api/students/{$student->id}/photo", [
            'photo' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('photo');
});

it('returns 423 when an eligible session is ongoing', function () {
    Storage::fake('public');
    $student = photoCtrlStudent('2020300001');
    $creator = photoCtrlStudent('2020000099', 'csg_admin');

    $event = EventModel::create(['name' => 'Intramurals', 'created_by' => $creator->id]);
    $day = EventDay::create(['event_id' => $event->id, 'date' => now(), 'day_number' => 1]);
    AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => 'morning',
        'check_type' => 'time_in',
        'start_time' => '07:00', 'end_time' => '08:00',
        'status' => 'ongoing',
    ]);

    $this->actingAs($student, 'sanctum')
        ->post("/api/students/{$student->id}/photo", [
            'photo' => UploadedFile::fake()->image('me.jpg'),
        ])
        ->assertStatus(423);
});
