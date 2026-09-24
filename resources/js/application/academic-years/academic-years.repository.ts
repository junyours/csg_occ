import type { AcademicYear } from '@/domain/entities';

export interface CreateAcademicYearPayload {
    name: string;
    start_date?: string | null;
    end_date?: string | null;
    is_active?: boolean;
}

export interface UpdateAcademicYearPayload {
    name?: string;
    start_date?: string | null;
    end_date?: string | null;
}

/**
 * Port the application layer depends on — mirrors auth.repository.ts.
 * The infrastructure layer (academic-years.repository.http.ts) provides
 * the concrete HTTP implementation.
 */
export interface AcademicYearRepository {
    list(): Promise<AcademicYear[]>;
    create(payload: CreateAcademicYearPayload): Promise<AcademicYear>;
    update(id: number, payload: UpdateAcademicYearPayload): Promise<AcademicYear>;
    activate(id: number): Promise<AcademicYear>;
    deactivate(id: number): Promise<AcademicYear>;
}
