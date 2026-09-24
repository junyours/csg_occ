import type { Student } from '@/domain/entities';
import type { Role } from '@/domain/enums';

export interface StudentFilters {
    departmentId?: number;
    role?: Role;
    major?: string;
    yearLevel?: string;
    section?: string;
    search?: string;
    page?: number;
    perPage?: number;
}

export interface PaginatedStudents {
    data: Student[];
    currentPage: number;
    lastPage: number;
    total: number;
    perPage: number;
}

export interface CreateStudentPayload {
    studentNumber: string;
    lastName: string;
    firstName: string;
    middleName: string;
    suffix?: string | null;
    departmentId: number;
    major?: string | null;
    yearLevel: string;
    section?: string | null;
    dateEnrolled?: string | null;
}

export interface BulkImportRowError {
    filename: string;
    row: number;
    studentNumber: string | null;
    reasons: string[];
}

export interface BulkImportReport {
    totalFiles: number;
    totalRows: number;
    imported: number;
    failed: number;
    errors: BulkImportRowError[];
}

/** One row from a bulk-import preview — nothing has been written yet. */
export interface BulkImportPreviewRow {
    row: number;
    valid: boolean;
    reasons: string[];
    studentNumber: string | null;
    lastName: string | null;
    firstName: string | null;
    middleName: string | null;
    dateEnrolled: string | null;
}

/**
 * One uploaded file's preview — a file is one section (e.g.
 * "BSBA-FM-1H.xlsx"), so a parse failure (bad filename, unknown
 * department, unauthorized department) fails the whole file rather than
 * any individual row.
 */
export interface BulkImportFilePreview {
    filename: string;
    valid: boolean;
    parseError: string | null;
    departmentCode: string | null;
    major: string | null;
    yearLevel: string | null;
    section: string | null;
    rows: BulkImportPreviewRow[];
}

export interface BulkImportPreview {
    totalFiles: number;
    totalRows: number;
    valid: number;
    invalid: number;
    files: BulkImportFilePreview[];
}

export interface UpdateStudentRolePayload {
    role: Role;
    departmentId?: number | null;
    eventId?: number | null;
}

/**
 * Port the application layer depends on — mirrors academic-years.repository.ts.
 * The infrastructure layer (students.repository.http.ts) provides the
 * concrete HTTP implementation.
 */
export interface StudentRepository {
    list(filters: StudentFilters): Promise<PaginatedStudents>;
    create(payload: CreateStudentPayload): Promise<Student>;
    /**
     * Validates a batch of section files (one per section, e.g.
     * "BSBA-FM-1H.xlsx") and reports what importing them would do, file
     * by file and row by row — nothing is written to the database. Call
     * this first; only call bulkImport() with the same files once the
     * admin confirms.
     */
    previewBulkImport(files: File[]): Promise<BulkImportPreview>;
    bulkImport(files: File[]): Promise<BulkImportReport>;
    downloadBulkImportTemplate(): Promise<Blob>;
    updateRole(studentId: number, payload: UpdateStudentRolePayload): Promise<Student>;
    getQrCode(studentId: number): Promise<Blob>;
    updatePhoto(studentId: number, file: File | Blob): Promise<Student>;
}
