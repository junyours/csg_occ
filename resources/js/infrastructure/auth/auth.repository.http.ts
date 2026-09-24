import { httpClient } from '@/infrastructure/http/client';
import type { AuthRepository, ChangePasswordPayload, LoginCredentials } from '@/application/auth/auth.repository';
import type { AuthenticatedUser, Student } from '@/domain/entities';

interface StudentDto {
    id: number;
    student_number: string;
    last_name: string;
    first_name: string;
    middle_name: string | null;
    suffix: string | null;
    department_id: number;
    // Present when the backend eager-loads the department relation
    // (AuthenticateStudent / AuthController::me both do) — absent only
    // if some future caller of this DTO skips that load.
    department?: { id: number; name: string; code: string } | null;
    major: string | null;
    year_level: string | null;
    section: string | null;
    date_enrolled: string | null;
    role: Student['role'];
    sc_admin_department_id: number | null;
    photo_path: string | null;
    photo_url: string | null;
    must_change_password: boolean;
    has_accepted_terms: boolean;
}

function toStudent(dto: StudentDto): Student {
    return {
        id: dto.id,
        studentNumber: dto.student_number,
        lastName: dto.last_name,
        firstName: dto.first_name,
        middleName: dto.middle_name,
        suffix: dto.suffix,
        departmentId: dto.department_id,
        departmentName: dto.department?.name,
        departmentCode: dto.department?.code,
        major: dto.major,
        yearLevel: dto.year_level,
        section: dto.section,
        dateEnrolled: dto.date_enrolled,
        role: dto.role,
        scAdminDepartmentId: dto.sc_admin_department_id,
        officerEventId: null,
        semesterId: null,
        photoPath: dto.photo_path,
        photoUrl: dto.photo_url,
        mustChangePassword: dto.must_change_password,
        hasAcceptedTerms: dto.has_accepted_terms,
    };
}

export const httpAuthRepository: AuthRepository = {
    async login(credentials: LoginCredentials): Promise<AuthenticatedUser> {
        const { data } = await httpClient.post<{ token: string; student: StudentDto }>('/auth/login', credentials);
        return { token: data.token, student: toStudent(data.student) };
    },

    async changePassword(payload: ChangePasswordPayload): Promise<void> {
        await httpClient.post('/auth/change-password', payload);
    },

    async acceptTerms(): Promise<void> {
        await httpClient.post('/auth/accept-terms');
    },

    async logout(): Promise<void> {
        await httpClient.post('/auth/logout');
    },
};
