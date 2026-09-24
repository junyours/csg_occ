<?php

namespace App\Application\Actions\Auth;

use App\Models\Student;

final class AcceptStudentTerms
{
    /**
     * Records that the student has read and accepted the Terms &
     * Conditions / Privacy Policy. One-way flag — once true, the
     * frontend never shows the agreement screen to this student again.
     */
    public function __invoke(Student $student): Student
    {
        $student->update(['has_accepted_terms' => true]);

        return $student;
    }
}
