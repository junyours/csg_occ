import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpExclusionsRepository } from '@/infrastructure/exclusions/exclusions.repository.http';
import { exclusionsQueryKey } from '@/application/exclusions/use-exclusions';

export function useCreateBulkExclusions(eventId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ file, reason }: { file: File; reason: string }) => httpExclusionsRepository.createBulk(eventId, file, reason),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: exclusionsQueryKey(eventId) });
        },
    });
}
