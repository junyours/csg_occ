import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpPenaltiesRepository } from '@/infrastructure/penalties/penalties.repository.http';
import { PENALTIES_QUERY_KEY } from '@/application/penalties/use-penalties';
import { MY_PENALTY_HISTORY_QUERY_KEY } from '@/application/penalties/use-my-penalty-history';

export function useReversePenalty() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ penaltyId, reason }: { penaltyId: number; reason: string }) =>
            httpPenaltiesRepository.reverse(penaltyId, reason),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: PENALTIES_QUERY_KEY });
            // The reversed student's own "my penalty history" cache entry
            // (if it happens to be open, e.g. in another tab/session)
            // should stop showing the charge as active too.
            queryClient.invalidateQueries({ queryKey: MY_PENALTY_HISTORY_QUERY_KEY });
        },
    });
}
