<?php

namespace App\Infrastructure\Repositories;

use App\Domain\Contracts\StudentRepositoryInterface;
use App\Models\Student;
use Illuminate\Support\Collection;

class EloquentStudentRepository implements StudentRepositoryInterface
{
    public function find(int $id): ?Student
    {
        return Student::find($id);
    }

    public function findByStudentNumber(string $studentNumber): ?Student
    {
        return Student::where('student_number', $studentNumber)->first();
    }

    public function create(array $data): Student
    {
        return Student::create($data);
    }

    public function forDepartment(int $departmentId): Collection
    {
        return Student::where('department_id', $departmentId)->get();
    }
}
