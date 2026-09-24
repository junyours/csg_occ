import type { Department } from '@/domain/entities';

export interface CreateDepartmentPayload {
    name: string;
    code: string;
}

export interface UpdateDepartmentPayload {
    name?: string;
    code?: string;
}

/**
 * Port the application layer depends on — mirrors academic-years.repository.ts.
 * The infrastructure layer (departments.repository.http.ts) provides the
 * concrete HTTP implementation.
 */
export interface DepartmentRepository {
    list(): Promise<Department[]>;
    create(payload: CreateDepartmentPayload): Promise<Department>;
    update(id: number, payload: UpdateDepartmentPayload): Promise<Department>;
    updateLogo(id: number, file: File | Blob): Promise<Department>;
    delete(id: number): Promise<void>;
}
