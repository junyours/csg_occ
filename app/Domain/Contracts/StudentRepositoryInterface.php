<?php

namespace App\Domain\Contracts;

use App\Models\Student;
use Illuminate\Support\Collection;

interface StudentRepositoryInterface
{
    public function find(int $id): ?Student;

    public function findByStudentNumber(string $studentNumber): ?Student;

    public function create(array $data): Student;

    public function forDepartment(int $departmentId): Collection;
}
