import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { httpPenaltiesRepository } from '@/infrastructure/penalties/penalties.repository.http';
import type { PenaltyLedgerFilters } from '@/infrastructure/penalties/penalties.repository.http';

export const PENALTIES_QUERY_KEY = ['penalties'];

export function penaltiesQueryKey(filters: PenaltyLedgerFilters) {
    return [...PENALTIES_QUERY_KEY, filters] as const;
}

export function usePenalties(filters: PenaltyLedgerFilters) {
    return useQuery({
        queryKey: penaltiesQueryKey(filters),
        queryFn: () => httpPenaltiesRepository.list(filters),
        // Same reasoning as useStudents: keeps the current page on screen
        // while a new filter/page loads instead of flashing to empty.
        placeholderData: keepPreviousData,
    });
}
