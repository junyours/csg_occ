<?php

use App\Application\Actions\Departments\UpdateDepartmentLogo;
use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('stores a compressed logo and sets logo_path', function () {
    Storage::fake('public');
    $department = Department::create(['name' => 'BSIT', 'code' => 'BSIT']);
    $file = UploadedFile::fake()->image('logo.png', 1200, 1200);

    $updated = (new UpdateDepartmentLogo(app(\Intervention\Image\ImageManager::class)))($department, $file);

    expect($updated->logo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($updated->logo_path);
});

it('deletes the previous logo on re-upload', function () {
    Storage::fake('public');
    $department = Department::create(['name' => 'BSIT', 'code' => 'BSIT']);
    $action = new UpdateDepartmentLogo(app(\Intervention\Image\ImageManager::class));

    $first = $action($department, UploadedFile::fake()->image('a.png', 900, 900));
    $oldPath = $first->logo_path;

    $second = $action($first, UploadedFile::fake()->image('b.png', 900, 900));

    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($second->logo_path);
    expect($second->logo_path)->not->toBe($oldPath);
});

it('exposes a logo_url once a logo is stored', function () {
    Storage::fake('public');
    $department = Department::create(['name' => 'BSIT', 'code' => 'BSIT']);

    $updated = (new UpdateDepartmentLogo(app(\Intervention\Image\ImageManager::class)))(
        $department,
        UploadedFile::fake()->image('logo.png'),
    );

    expect($updated->logo_url)->not->toBeNull();
});
