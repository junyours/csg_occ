<?php

use App\Models\Department;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

function bulkExclusionApiDept(string $code = 'CS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function bulkExclusionApiStaff(string $role, string $studentNumber): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Staff', 'first_name' => ucfirst($role),
        'department_id' => bulkExclusionApiDept('CCS')->id,
        'username' => 'bulkstaff'.$studentNumber,
        'password' => 'password',
        'role' => $role,
        'must_change_password' => false,
    ]);
}

function bulkExclusionApiStudent(string $studentNumber): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => bulkExclusionApiDept()->id,
        'username' => 'bulkapi'.$studentNumber,
        'password' => 'password',
    ]);
}

function bulkExclusionApiCsv(array $rows): UploadedFile
{
    $lines = [implode(',', ['student_id', 'scope', 'day', 'window'])];
    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(fn ($v) => (string) ($v ?? ''), $row));
    }

    return UploadedFile::fake()->createWithContent('exclusions.csv', implode("\n", $lines));
}

it('rejects a non-admin from previewing a bulk exclusion upload', function () {
    $officer = bulkExclusionApiStaff('officer', '2020200050');
    $event = EventModel::create(['name' => 'Event', 'created_by' => $officer->id]);

    $this->actingAs($officer, 'sanctum')
        ->postJson("/api/events/{$event->id}/exclusions/bulk/preview", [
            'file' => bulkExclusionApiCsv([['20231000', 'EVENT', '', '']]),
        ])
        ->assertStatus(403);
});

it('lets a csg admin preview a bulk exclusion upload without writing anything', function () {
    $admin = bulkExclusionApiStaff('csg_admin', '2020000050');
    $student = bulkExclusionApiStudent('2023100050');
    $event = EventModel::create(['name' => 'Event', 'created_by' => $admin->id]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/events/{$event->id}/exclusions/bulk/preview", [
            'file' => bulkExclusionApiCsv([[$student->student_number, 'EVENT', '', '']]),
        ])
        ->assertStatus(200)
        ->assertJsonPath('valid', 1);

    expect(Exclusion::count())->toBe(0);
});

it('lets a csg admin commit a bulk exclusion upload, requiring a reason', function () {
    $admin = bulkExclusionApiStaff('csg_admin', '2020000051');
    $student = bulkExclusionApiStudent('2023100051');
    $event = EventModel::create(['name' => 'Event', 'created_by' => $admin->id]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/events/{$event->id}/exclusions/bulk", [
            'file' => bulkExclusionApiCsv([[$student->student_number, 'EVENT', '', '']]),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/events/{$event->id}/exclusions/bulk", [
            'file' => bulkExclusionApiCsv([[$student->student_number, 'EVENT', '', '']]),
            'reason' => 'Disciplinary case',
        ])
        ->assertStatus(200)
        ->assertJsonPath('excluded', 1);

    expect(Exclusion::where('reason', 'Disciplinary case')->count())->toBe(1);
});
