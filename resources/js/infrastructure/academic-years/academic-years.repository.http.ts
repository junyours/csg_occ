import { httpClient } from '@/infrastructure/http/client';
import type { AcademicYear } from '@/domain/entities';
import type {
    AcademicYearRepository,
    CreateAcademicYearPayload,
    UpdateAcademicYearPayload,
} from '@/application/academic-years/academic-years.repository';

interface RawAcademicYear {
    id: number;
    name: string;
    start_date: string | null;
    end_date: string | null;
    is_active: boolean;
    created_by: number;
}

function toAcademicYear(raw: RawAcademicYear): AcademicYear {
    return {
        id: raw.id,
        name: raw.name,
        startDate: raw.start_date,
        endDate: raw.end_date,
        isActive: raw.is_active,
        createdBy: raw.created_by,
    };
}

export const httpAcademicYearsRepository: AcademicYearRepository = {
    async list(): Promise<AcademicYear[]> {
        const { data } = await httpClient.get<RawAcademicYear[]>('/academic-years');
        return data.map(toAcademicYear);
    },

    async create(payload: CreateAcademicYearPayload): Promise<AcademicYear> {
        const { data } = await httpClient.post<RawAcademicYear>('/academic-years', payload);
        return toAcademicYear(data);
    },

    async update(id: number, payload: UpdateAcademicYearPayload): Promise<AcademicYear> {
        const { data } = await httpClient.patch<RawAcademicYear>(`/academic-years/${id}`, payload);
        return toAcademicYear(data);
    },

    async activate(id: number): Promise<AcademicYear> {
        const { data } = await httpClient.post<RawAcademicYear>(`/academic-years/${id}/activate`);
        return toAcademicYear(data);
    },

    async deactivate(id: number): Promise<AcademicYear> {
        const { data } = await httpClient.post<RawAcademicYear>(`/academic-years/${id}/deactivate`);
        return toAcademicYear(data);
    },
};
