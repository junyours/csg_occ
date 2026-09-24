import { useMutation } from '@tanstack/react-query';

import { httpStudentsRepository } from '@/infrastructure/students/students.repository.http';

/**
 * No cache invalidation here on purpose — a preview never writes
 * anything, so there's nothing about the students list to refresh.
 * Compare with useBulkImportStudents, which does invalidate.
 */
export function usePreviewBulkImportStudents() {
    return useMutation({
        mutationFn: (files: File[]) => httpStudentsRepository.previewBulkImport(files),
    });
}
