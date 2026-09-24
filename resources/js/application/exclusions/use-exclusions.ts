import { useQuery } from '@tanstack/react-query';

import { httpExclusionsRepository } from '@/infrastructure/exclusions/exclusions.repository.http';

export const EXCLUSIONS_QUERY_KEY = ['exclusions'];

export function exclusionsQueryKey(eventId: number) {
    return [...EXCLUSIONS_QUERY_KEY, eventId] as const;
}

/** Backs the Manage Exclusions screen (student-exclusion-feature-plan.md §4B). */
export function useExclusions(eventId: number) {
    return useQuery({
        queryKey: exclusionsQueryKey(eventId),
        queryFn: () => httpExclusionsRepository.listForEvent(eventId),
        enabled: Number.isFinite(eventId) && eventId > 0,
    });
}
