import type { Student } from '@/domain/entities';
import type { Role, StudentStatus } from '@/domain/enums';

export interface StudentFilters {
    departmentId?: number;
    role?: Role;
    major?: string;
    yearLevel?: string;
    section?: string;
    status?: StudentStatus | 'all';
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

/** Thrown by create() when the student number already exists (HTTP 409) —
 *  carries the record so the form can switch to "update enrollment" mode. */
export class StudentAlreadyExistsError extends Error {
    constructor(public readonly existing: Student) {
        super("Student ID already exists. You can update this student's enrollment instead.");
        this.name = 'StudentAlreadyExistsError';
    }
}

export interface UpdateStudentPayload {
    lastName?: string;
    firstName?: string;
    middleName?: string | null;
    suffix?: string | null;
    departmentId?: number;
    major?: string | null;
    yearLevel?: string;
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
    created: number;
    updated: number;
    skipped: number;
    failed: number;
    errors: BulkImportRowError[];
}

/** One row from a bulk-import preview — nothing has been written yet. */
export type BulkImportRowAction = 'create' | 'update' | 'skip';

export interface BulkImportPreviewRow {
    row: number;
    valid: boolean;
    reasons: string[];
    /** null on invalid rows. */
    action: BulkImportRowAction | null;
    /** "BSIT-2A → BSBA-FM-3H" on update rows whose placement changes. */
    change: string | null;
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
    created: number;
    updated: number;
    skipped: number;
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
    /**
     * Single add. With `updateExisting` an already-known student number is
     * re-enrolled (same as an import update row) instead of rejected with
     * StudentAlreadyExistsError.
     */
    create(payload: CreateStudentPayload, options?: { updateExisting?: boolean }): Promise<Student>;
    update(studentId: number, payload: UpdateStudentPayload): Promise<Student>;
    updateStatus(studentId: number, status: StudentStatus): Promise<Student>;
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
    /**
     * Puts the student's password back to the default and makes them choose
     * a new one at next sign-in. Who may do this is enforced by the server
     * (StudentPolicy::resetPassword); canResetStudentPassword() only decides
     * whether the button is worth showing.
     */
    resetPassword(studentId: number): Promise<void>;
    getQrCode(studentId: number): Promise<Blob>;
    updatePhoto(studentId: number, file: File | Blob): Promise<Student>;
}
