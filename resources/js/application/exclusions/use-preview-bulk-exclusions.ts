import { useMutation } from '@tanstack/react-query';

import { httpExclusionsRepository } from '@/infrastructure/exclusions/exclusions.repository.http';

/**
 * No cache invalidation — a preview writes nothing (student-exclusion-
 * feature-plan.md §7: "bulk upload preview always shown before commit").
 * Same reasoning as usePreviewBulkImportStudents.
 */
export function usePreviewBulkExclusions(eventId: number) {
    return useMutation({
        mutationFn: (file: File) => httpExclusionsRepository.previewBulk(eventId, file),
    });
}
