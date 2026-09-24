<?php

namespace App\Http\Controllers\Students;

use App\Application\Actions\Students\CreateStudent;
use App\Domain\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Students\IndexStudentRequest;
use App\Http\Requests\Students\StoreStudentRequest;
use App\Models\Student;

class StudentController extends Controller
{
    /**
     * Spec §10: "scoped by role — CSG sees all, SC sees own dept". An SC
     * Admin's department_id is forced to the one they administer even if a
     * different one was requested, rather than 403ing on a mismatch — the
     * endpoint just quietly shows them their own roster either way.
     */
    public function index(IndexStudentRequest $request)
    {
        $user = $request->user();
        $filters = $request->validated();

        if ($user->role === Role::ScAdmin) {
            $filters['department_id'] = $user->sc_admin_department_id;
        }

        $query = Student::query()->with('department');

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (! empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (! empty($filters['major'])) {
            $query->where('major', $filters['major']);
        }

        if (! empty($filters['year_level'])) {
            $query->where('year_level', $filters['year_level']);
        }

        if (! empty($filters['section'])) {
            $query->where('section', $filters['section']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('student_number', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('first_name', 'like', $term);
            });
        }

        // Spec's report section calls out "sort by a-z last name" — apply
        // that as the default order here too, so the roster list and the
        // printable reports read consistently.
        $query->orderBy('last_name')->orderBy('first_name');

        return response()->json(
            $query->paginate($filters['per_page'] ?? 50)
        );
    }

    public function store(StoreStudentRequest $request, CreateStudent $createStudent)
    {
        $student = $createStudent($request->validated());

        return response()->json($student->fresh('department'), 201);
    }
}
