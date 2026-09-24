import type { Semester } from '@/domain/entities';

export interface CreateSemesterPayload {
    name: string;
    start_date?: string | null;
    end_date?: string | null;
    is_active?: boolean;
}

/**
 * Name and academic_year_id are immutable after creation (see the
 * UpdateSemester action) — only the dates can change.
 */
export interface UpdateSemesterPayload {
    start_date?: string | null;
    end_date?: string | null;
}

/**
 * Port the application layer depends on — mirrors academic-years.repository.ts.
 * Semesters are scoped to an academic year, so list/create take the parent id.
 * The infrastructure layer (semesters.repository.http.ts) provides the
 * concrete HTTP implementation.
 */
export interface SemesterRepository {
    list(academicYearId: number): Promise<Semester[]>;
    create(academicYearId: number, payload: CreateSemesterPayload): Promise<Semester>;
    update(id: number, payload: UpdateSemesterPayload): Promise<Semester>;
    activate(id: number): Promise<Semester>;
    deactivate(id: number): Promise<Semester>;
}
