<?php

use App\Models\Department;
use App\Models\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function bulkImportApiDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function bulkImportApiStaff(string $role, string $studentNumber): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Staff', 'first_name' => 'Test',
        'department_id' => bulkImportApiDept('CCS')->id,
        'username' => 'staff'.$studentNumber,
        'password' => 'password',
        'role' => $role,
    ]);
}

function bulkImportApiCsv(string $filename, array $rows): UploadedFile
{
    $headers = ['id_number', 'last_name', 'first_name', 'middle_name', 'date_enrolled'];
    $lines = [implode(',', $headers)];

    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(fn ($v) => (string) ($v ?? ''), $row));
    }

    return UploadedFile::fake()->createWithContent($filename, implode("\n", $lines));
}

it('rejects an unauthenticated bulk import request', function () {
    $this->postJson('/api/students/bulk-import', [])->assertStatus(401);
});

it('rejects an officer attempting a bulk import', function () {
    $officer = bulkImportApiStaff('officer', '2020200001');

    // No file needed here — Laravel authorizes a FormRequest before
    // running its validation rules, so the 403 fires before missing
    // files would even be checked.
    $this->actingAs($officer, 'sanctum')
        ->postJson('/api/students/bulk-import', [])
        ->assertStatus(403);
});

it('validates that at least one file is present', function () {
    $admin = bulkImportApiStaff('csg_admin', '2020000001');

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/students/bulk-import', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('files');
});

it('rejects a file with a disallowed extension', function () {
    $admin = bulkImportApiStaff('csg_admin', '2020000001');
    $file = UploadedFile::fake()->create('import.pdf', 10);

    $this->actingAs($admin, 'sanctum')
        ->post('/api/students/bulk-import', ['files' => [$file]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('files.0');
});

it('lets a csg admin bulk import one section file and returns an import report', function () {
    bulkImportApiDept('BSIT');
    $admin = bulkImportApiStaff('csg_admin', '2020000001');
    $file = bulkImportApiCsv('BSIT-1A.csv', [
        ['2023000001', 'Cruz', 'Juan', 'Dela', ''],
    ]);

    $this->actingAs($admin, 'sanctum')
        ->post('/api/students/bulk-import', ['files' => [$file]])
        ->assertStatus(200)
        ->assertJsonPath('total_files', 1)
        ->assertJsonPath('imported', 1)
        ->assertJsonPath('failed', 0);

    expect(Student::where('student_number', '2023000001')->exists())->toBeTrue();
});

it('lets a csg admin bulk import several section files in one batch', function () {
    bulkImportApiDept('BSIT');
    bulkImportApiDept('BEED');
    $admin = bulkImportApiStaff('csg_admin', '2020000001');
    $files = [
        bulkImportApiCsv('BSIT-1A.csv', [['2023000001', 'Cruz', 'Juan', 'Dela', '']]),
        bulkImportApiCsv('BEED-1A.csv', [['2023000002', 'Reyes', 'Ana', 'Santos', '']]),
    ];

    $this->actingAs($admin, 'sanctum')
        ->post('/api/students/bulk-import', ['files' => $files])
        ->assertStatus(200)
        ->assertJsonPath('total_files', 2)
        ->assertJsonPath('imported', 2)
        ->assertJsonPath('failed', 0);
});

it('returns 422 when a file exceeds the synchronous row cap', function () {
    bulkImportApiDept('BSIT');
    $admin = bulkImportApiStaff('csg_admin', '2020000001');

    $rows = [];
    for ($i = 1; $i <= \App\Application\Actions\Students\BulkImportStudents::MAX_ROWS_PER_FILE + 1; $i++) {
        $rows[] = [sprintf('2023%06d', $i), 'Cruz', 'Juan', 'Dela', ''];
    }

    $this->actingAs($admin, 'sanctum')
        ->post('/api/students/bulk-import', ['files' => [bulkImportApiCsv('BSIT-1A.csv', $rows)]])
        ->assertStatus(422);
});
