import '../css/app.css';
// Self-hosted, bundled by Vite (no request to fonts.googleapis.com /
// fonts.gstatic.com at runtime — see app.blade.php and
// AddSecurityHeaders.php, which previously allow-listed those origins
// specifically for this). Inter is shadcn/ui's default typeface; the
// variable weight file covers the full 100–900 range in one file, normal
// and italic.
import '@fontsource-variable/inter';
import '@fontsource-variable/inter/wght-italic.css';

import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from 'react-router-dom';

import { Toaster } from '@/components/ui/sonner';
import { router } from '@/presentation/routes/router';

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            retry: 1,
            refetchOnWindowFocus: false,
            // The single source of truth for "how fresh does cached data
            // need to be". Without this the default is 0 — every query is
            // stale the instant it mounts, so bouncing between tabs (e.g.
            // dashboard -> QR -> dashboard) silently refetches every time.
            // 60s means normal in-app navigation reads from cache, while
            // anything that actually changes state (creating a student,
            // scanning, ending a session, etc.) still updates immediately
            // because mutations call invalidateQueries, which forces a
            // refetch regardless of staleTime.
            //
            // Individual hooks can still opt into a longer staleTime when
            // 60s is wasteful for data that essentially never changes
            // mid-session (see useStudentQr, useAttendanceHistoryFilterOptions)
            // — that should stay the exception, not the norm.
            staleTime: 60 * 1000,
        },
    },
});

const container = document.getElementById('app');

if (container) {
    createRoot(container).render(
        <QueryClientProvider client={queryClient}>
            <RouterProvider router={router} />
            <Toaster />
        </QueryClientProvider>,
    );
}
