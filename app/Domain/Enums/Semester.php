<?php

namespace App\Domain\Enums;

/**
 * A term within an AcademicYear. Not its own manageable entity (no CRUD,
 * no table) — every academic year has exactly these three terms, and an
 * event picks one when it's created.
 */
enum Semester: string
{
    case First = 'semester_1';
    case Second = 'semester_2';
    case Summer = 'summer';
}
