import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpExclusionsRepository } from '@/infrastructure/exclusions/exclusions.repository.http';
import { exclusionsQueryKey } from '@/application/exclusions/use-exclusions';

/**
 * Removal is soft on the backend and blocked once the exclusion's own
 * target scope has already ended (student-exclusion-feature-plan.md §6)
 * — a 409 from the server surfaces as a normal mutation error for the
 * page to toast, rather than being special-cased here.
 */
export function useRemoveExclusion(eventId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (exclusionId: number) => httpExclusionsRepository.remove(exclusionId),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: exclusionsQueryKey(eventId) });
        },
    });
}
