import { useCallback, useState } from 'react';

import { httpStudentsRepository } from '@/infrastructure/students/students.repository.http';

/**
 * The template endpoint sits behind Sanctum bearer-token auth (see
 * use-student-qr.ts for the same reasoning), so a plain <a href> can't
 * carry the Authorization header — fetch it through httpClient as a blob
 * and trigger the save manually instead.
 */
export function useDownloadBulkImportTemplate() {
    const [isDownloading, setIsDownloading] = useState(false);

    const download = useCallback(async () => {
        setIsDownloading(true);
        try {
            const blob = await httpStudentsRepository.downloadBulkImportTemplate();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'student-import-template.xlsx';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        } finally {
            setIsDownloading(false);
        }
    }, []);

    return { download, isDownloading };
}
