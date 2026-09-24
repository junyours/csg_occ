import { httpClient } from '@/infrastructure/http/client';

export type RosterReportFormat = 'xlsx' | 'pdf';

export interface RosterReportFilters {
    departmentId?: number;
    yearLevel?: string;
    section?: string;
    format: RosterReportFormat;
}

export interface DownloadedFile {
    blob: Blob;
    filename: string;
}

export type ReportGenerationStatus = 'pending' | 'processing' | 'completed' | 'failed';

export interface ReportGeneration {
    id: number;
    status: ReportGenerationStatus;
    format: RosterReportFormat;
    total_steps: number;
    processed_steps: number;
    percentage: number;
    error_message: string | null;
    download_url: string | null;
}

export interface MasterReportEvent {
    id: number;
    name: string;
    status: string;
    present: number;
    late: number;
    absent: number;
    excluded: number;
}

export interface MasterReport {
    academic_year: { id: number; name: string } | null;
    semester: { id: number; name: string } | null;
    events: MasterReportEvent[];
}

export interface MasterRosterReportFilters {
    eventIds: number[];
    departmentId?: number;
    yearLevel?: string;
    section?: string;
    format: RosterReportFormat;
}

const EXTENSION: Record<RosterReportFormat, string> = { xlsx: 'xlsx', pdf: 'pdf' };

/**
 * Pulls the filename Laravel set on the response's
 * Content-Disposition header, falling back to a generic name if it's
 * ever missing — axios still exposes response headers on a blob
 * response, it's only response.data that's opaque.
 */
function filenameFrom(contentDisposition: unknown, format: RosterReportFormat): string {
    if (typeof contentDisposition === 'string') {
        const match = contentDisposition.match(/filename="?([^"]+)"?/);
        if (match) return match[1];
    }
    return `roster-report.${EXTENSION[format]}`;
}

export const httpReportsRepository = {
    /**
     * GET /events/{id}/roster-report — sits behind Sanctum bearer-token
     * auth (see use-download-bulk-import-template.ts for the same
     * reasoning), so this is fetched as a blob through httpClient
     * rather than a plain <a href>, and the save is triggered manually.
     *
     * Kept for small/quick exports, but the report screen itself now
     * drives the async report-generations flow below so a big export
     * never ties up one long request — see startRosterReportGeneration.
     */
    async downloadRosterReport(eventId: number, filters: RosterReportFilters): Promise<DownloadedFile> {
        const response = await httpClient.get(`/events/${eventId}/roster-report`, {
            params: {
                department_id: filters.departmentId,
                year_level: filters.yearLevel || undefined,
                section: filters.section || undefined,
                format: filters.format,
            },
            responseType: 'blob',
        });

        return {
            blob: response.data,
            filename: filenameFrom(response.headers?.['content-disposition'], filters.format),
        };
    },

    /**
     * POST /events/{id}/report-generations — returns almost immediately
     * with a 202 and a tracking row id; the actual build+render happens
     * after this response, on the server (see StartRosterReportGeneration).
     */
    async startRosterReportGeneration(eventId: number, filters: RosterReportFilters): Promise<ReportGeneration> {
        const response = await httpClient.post(`/events/${eventId}/report-generations`, {
            department_id: filters.departmentId,
            year_level: filters.yearLevel || undefined,
            section: filters.section || undefined,
            format: filters.format,
        });

        return response.data;
    },

    /** GET /report-generations/{id} — polled for real, DB-backed progress. */
    async getReportGeneration(id: number): Promise<ReportGeneration> {
        const response = await httpClient.get(`/report-generations/${id}`);
        return response.data;
    },

    /**
     * GET /report-generations/{id}/download — fetched as a blob for the
     * same auth reason as downloadRosterReport above.
     */
    async downloadReportGeneration(generation: ReportGeneration): Promise<DownloadedFile> {
        const response = await httpClient.get(`/report-generations/${generation.id}/download`, {
            responseType: 'blob',
        });

        return {
            blob: response.data,
            filename: filenameFrom(response.headers?.['content-disposition'], generation.format),
        };
    },

    /** GET /reports/master — the Settings → Reports landing screen. */
    async getMasterReport(): Promise<MasterReport> {
        const response = await httpClient.get('/reports/master');
        return response.data;
    },

    /**
     * POST /reports/master/report-generations — the master report's own
     * "generate" action, combining every selected event into one file
     * (see MasterReportGenerationController). Returns almost instantly
     * with a 202 and a tracking row id, polled/downloaded the exact
     * same way the per-event flow already is.
     */
    async startMasterReportGeneration(filters: MasterRosterReportFilters): Promise<ReportGeneration> {
        const response = await httpClient.post('/reports/master/report-generations', {
            event_ids: filters.eventIds,
            department_id: filters.departmentId,
            year_level: filters.yearLevel || undefined,
            section: filters.section || undefined,
            format: filters.format,
        });

        return response.data;
    },
};
