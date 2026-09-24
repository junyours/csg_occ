import { httpClient } from '@/infrastructure/http/client';
import type { Semester } from '@/domain/entities';
import type {
    CreateSemesterPayload,
    SemesterRepository,
    UpdateSemesterPayload,
} from '@/application/semesters/semesters.repository';

interface RawSemester {
    id: number;
    academic_year_id: number;
    name: string;
    start_date: string | null;
    end_date: string | null;
    is_active: boolean;
    created_by: number;
}

function toSemester(raw: RawSemester): Semester {
    return {
        id: raw.id,
        academicYearId: raw.academic_year_id,
        name: raw.name,
        startDate: raw.start_date,
        endDate: raw.end_date,
        isActive: raw.is_active,
        createdBy: raw.created_by,
    };
}

export const httpSemestersRepository: SemesterRepository = {
    async list(academicYearId: number): Promise<Semester[]> {
        const { data } = await httpClient.get<RawSemester[]>(`/academic-years/${academicYearId}/semesters`);
        return data.map(toSemester);
    },

    async create(academicYearId: number, payload: CreateSemesterPayload): Promise<Semester> {
        const { data } = await httpClient.post<RawSemester>(`/academic-years/${academicYearId}/semesters`, payload);
        return toSemester(data);
    },

    async update(id: number, payload: UpdateSemesterPayload): Promise<Semester> {
        const { data } = await httpClient.patch<RawSemester>(`/semesters/${id}`, payload);
        return toSemester(data);
    },

    async activate(id: number): Promise<Semester> {
        const { data } = await httpClient.post<RawSemester>(`/semesters/${id}/activate`);
        return toSemester(data);
    },

    async deactivate(id: number): Promise<Semester> {
        const { data } = await httpClient.post<RawSemester>(`/semesters/${id}/deactivate`);
        return toSemester(data);
    },
};
