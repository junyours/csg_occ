<?php

namespace App\Application\Actions\PasswordResetCodes;

use App\Models\PasswordResetCode;
use App\Models\Student;

final class DeleteDeadPasswordResetCodes
{
    /**
     * The "Clear used & expired" button: deletes the codes this admin can
     * see that have nothing left to do (see PasswordResetCode::scopePurgeable
     * — expired, or used and finished/abandoned). A code that is still
     * pending, locked, or whose student is mid-way through choosing a new
     * password is never touched.
     *
     * Limited to what the admin can see, so an SC Admin can't clear rows
     * belonging to another department.
     *
     * @return int how many rows were deleted
     */
    public function __invoke(Student $actor): int
    {
        return PasswordResetCode::query()
            ->visibleTo($actor)
            ->purgeable()
            ->delete();
    }
}
