import { httpClient } from '@/infrastructure/http/client';
import type { Department } from '@/domain/entities';
import type {
    CreateDepartmentPayload,
    DepartmentRepository,
    UpdateDepartmentPayload,
} from '@/application/departments/departments.repository';

interface RawDepartment {
    id: number;
    name: string;
    code: string;
    logo_path: string | null;
    logo_url: string | null;
}

function toDepartment(raw: RawDepartment): Department {
    return {
        id: raw.id,
        name: raw.name,
        code: raw.code,
        logoPath: raw.logo_path,
        logoUrl: raw.logo_url,
    };
}

export const httpDepartmentsRepository: DepartmentRepository = {
    async list(): Promise<Department[]> {
        const { data } = await httpClient.get<RawDepartment[]>('/departments');
        return data.map(toDepartment);
    },

    async create(payload: CreateDepartmentPayload): Promise<Department> {
        const { data } = await httpClient.post<RawDepartment>('/departments', payload);
        return toDepartment(data);
    },

    async update(id: number, payload: UpdateDepartmentPayload): Promise<Department> {
        const { data } = await httpClient.patch<RawDepartment>(`/departments/${id}`, payload);
        return toDepartment(data);
    },

    async updateLogo(id: number, file: File | Blob): Promise<Department> {
        const formData = new FormData();
        formData.append('logo', file);

        const { data } = await httpClient.post<RawDepartment>(`/departments/${id}/logo`, formData);
        return toDepartment(data);
    },

    async delete(id: number): Promise<void> {
        await httpClient.delete(`/departments/${id}`);
    },
};
