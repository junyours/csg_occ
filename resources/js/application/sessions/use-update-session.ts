import { useMutation, useQueryClient } from '@tanstack/react-query';

import {
    httpSessionsRepository,
    type UpdateSessionPayload,
} from '@/infrastructure/sessions/sessions.repository.http';
import { EVENTS_QUERY_KEY } from '@/application/events/use-events';

// event-day-window-edit-delete-plan.md §4.3a: edits a single check while
// it's still Scheduled, via PATCH /sessions/{session}. A 422 on the
// `window_type` field means the edited (window_type, check_type) pair now
// collides with a sibling check on the same day.
export function useUpdateSession() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, payload }: { id: number; payload: UpdateSessionPayload }) =>
            httpSessionsRepository.updateSession(id, payload),
        onSuccess: () => {
            // Sessions live nested inside the /events response too — same
            // reasoning as use-create-session.ts.
            queryClient.invalidateQueries({ queryKey: EVENTS_QUERY_KEY });
        },
    });
}
