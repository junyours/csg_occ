import { httpClient } from '@/infrastructure/http/client';
import type { Student } from '@/domain/entities';
import type {
    BulkImportPreview,
    BulkImportReport,
    CreateStudentPayload,
    PaginatedStudents,
    StudentFilters,
    StudentRepository,
    UpdateStudentRolePayload,
} from '@/application/students/students.repository';

interface RawStudent {
    id: number;
    student_number: string;
    last_name: string;
    first_name: string;
    middle_name: string | null;
    suffix: string | null;
    department_id: number;
    major: string | null;
    year_level: string | null;
    section: string | null;
    date_enrolled: string | null;
    role: Student['role'];
    sc_admin_department_id: number | null;
    officer_event_id: number | null;
    semester_id: number | null;
    photo_path: string | null;
    photo_url: string | null;
    must_change_password: boolean;
    has_accepted_terms: boolean;
}

interface RawPaginatedStudents {
    data: RawStudent[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

interface RawBulkImportReport {
    total_files: number;
    total_rows: number;
    imported: number;
    failed: number;
    errors: Array<{ filename: string; row: number; student_number: string | null; reasons: string[] }>;
}

interface RawBulkImportPreviewRow {
    row: number;
    valid: boolean;
    reasons: string[];
    student_number: string | null;
    last_name: string | null;
    first_name: string | null;
    middle_name: string | null;
    date_enrolled: string | null;
}

interface RawBulkImportFilePreview {
    filename: string;
    valid: boolean;
    parse_error: string | null;
    department_code: string | null;
    major: string | null;
    year_level: string | null;
    section: string | null;
    rows: RawBulkImportPreviewRow[];
}

interface RawBulkImportPreview {
    total_files: number;
    total_rows: number;
    valid: number;
    invalid: number;
    files: RawBulkImportFilePreview[];
}

function toStudent(raw: RawStudent): Student {
    return {
        id: raw.id,
        studentNumber: raw.student_number,
        lastName: raw.last_name,
        firstName: raw.first_name,
        middleName: raw.middle_name,
        suffix: raw.suffix,
        departmentId: raw.department_id,
        major: raw.major,
        yearLevel: raw.year_level,
        section: raw.section,
        dateEnrolled: raw.date_enrolled,
        role: raw.role,
        scAdminDepartmentId: raw.sc_admin_department_id,
        officerEventId: raw.officer_event_id,
        semesterId: raw.semester_id,
        photoPath: raw.photo_path,
        photoUrl: raw.photo_url,
        mustChangePassword: raw.must_change_password,
        hasAcceptedTerms: raw.has_accepted_terms,
    };
}

function toBulkImportReport(raw: RawBulkImportReport): BulkImportReport {
    return {
        totalFiles: raw.total_files,
        totalRows: raw.total_rows,
        imported: raw.imported,
        failed: raw.failed,
        errors: raw.errors.map((error) => ({
            filename: error.filename,
            row: error.row,
            studentNumber: error.student_number,
            reasons: error.reasons,
        })),
    };
}

function toBulkImportPreview(raw: RawBulkImportPreview): BulkImportPreview {
    return {
        totalFiles: raw.total_files,
        totalRows: raw.total_rows,
        valid: raw.valid,
        invalid: raw.invalid,
        files: raw.files.map((file) => ({
            filename: file.filename,
            valid: file.valid,
            parseError: file.parse_error,
            departmentCode: file.department_code,
            major: file.major,
            yearLevel: file.year_level,
            section: file.section,
            rows: file.rows.map((row) => ({
                row: row.row,
                valid: row.valid,
                reasons: row.reasons,
                studentNumber: row.student_number,
                lastName: row.last_name,
                firstName: row.first_name,
                middleName: row.middle_name,
                dateEnrolled: row.date_enrolled,
            })),
        })),
    };
}

export const httpStudentsRepository: StudentRepository = {
    async list(filters: StudentFilters): Promise<PaginatedStudents> {
        const { data } = await httpClient.get<RawPaginatedStudents>('/students', {
            params: {
                department_id: filters.departmentId,
                role: filters.role,
                year_level: filters.yearLevel,
                section: filters.section,
                search: filters.search,
                page: filters.page,
                per_page: filters.perPage,
            },
        });

        return {
            data: data.data.map(toStudent),
            currentPage: data.current_page,
            lastPage: data.last_page,
            total: data.total,
            perPage: data.per_page,
        };
    },

    async create(payload: CreateStudentPayload): Promise<Student> {
        const { data } = await httpClient.post<RawStudent>('/students', {
            student_number: payload.studentNumber,
            last_name: payload.lastName,
            first_name: payload.firstName,
            middle_name: payload.middleName,
            suffix: payload.suffix || null,
            department_id: payload.departmentId,
            year_level: payload.yearLevel,
            section: payload.section || null,
        });
        return toStudent(data);
    },

    async bulkImport(files: File[]): Promise<BulkImportReport> {
        const formData = new FormData();
        files.forEach((file) => formData.append('files[]', file));

        // Let axios/the browser set the multipart boundary itself — an
        // explicit Content-Type here would drop it and the request would
        // arrive with no boundary at all.
        const { data } = await httpClient.post<RawBulkImportReport>('/students/bulk-import', formData);
        return toBulkImportReport(data);
    },

    async previewBulkImport(files: File[]): Promise<BulkImportPreview> {
        const formData = new FormData();
        files.forEach((file) => formData.append('files[]', file));

        const { data } = await httpClient.post<RawBulkImportPreview>('/students/bulk-import/preview', formData);
        return toBulkImportPreview(data);
    },

    async downloadBulkImportTemplate(): Promise<Blob> {
        const { data } = await httpClient.get<Blob>('/students/bulk-import-template', {
            responseType: 'blob',
        });
        return data;
    },

    async updateRole(studentId: number, payload: UpdateStudentRolePayload): Promise<Student> {
        const { data } = await httpClient.patch<RawStudent>(`/students/${studentId}/role`, {
            role: payload.role,
            department_id: payload.departmentId ?? null,
            event_id: payload.eventId ?? null,
        });
        return toStudent(data);
    },

    /**
     * GET /students/{id}/qr returns a raw PNG (Content-Type: image/png), not
     * JSON — the endpoint is served straight from GenerateStudentQrCode, with
     * an ETag the browser/axios cache can key off. We always fetch as a blob
     * so it works the same whether it's shown inline or saved to disk; the
     * `download` disposition the API sets when `?download=1` is passed only
     * changes a header, not what we get back, so the frontend doesn't need to
     * make two separate requests for "view" vs "download".
     */
    async getQrCode(studentId: number): Promise<Blob> {
        const { data } = await httpClient.get<Blob>(`/students/${studentId}/qr`, {
            responseType: 'blob',
        });
        return data;
    },

    async updatePhoto(studentId: number, file: File | Blob): Promise<Student> {
        const formData = new FormData();
        formData.append('photo', file);

        const { data } = await httpClient.post<RawStudent>(`/students/${studentId}/photo`, formData);
        return toStudent(data);
    },
};
