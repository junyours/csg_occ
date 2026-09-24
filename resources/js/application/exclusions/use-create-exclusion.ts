import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpExclusionsRepository } from '@/infrastructure/exclusions/exclusions.repository.http';
import type { CreateExclusionPayload } from '@/infrastructure/exclusions/exclusions.repository.http';
import { exclusionsQueryKey } from '@/application/exclusions/use-exclusions';

/** Single manual exclusion (student-exclusion-feature-plan.md §5, non-bulk path). */
export function useCreateExclusion() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: CreateExclusionPayload) => httpExclusionsRepository.create(payload),
        onSuccess: (_data, payload) => {
            queryClient.invalidateQueries({ queryKey: exclusionsQueryKey(payload.eventId) });
        },
    });
}
