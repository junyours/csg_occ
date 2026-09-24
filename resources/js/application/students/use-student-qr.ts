import { useCallback, useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { toast } from 'sonner';

import { httpStudentsRepository } from '@/infrastructure/students/students.repository.http';

export function studentQrQueryKey(studentId: number) {
    return ['students', studentId, 'qr'] as const;
}

// iOS Safari is the one platform that silently ignores <a download> — the
// click just opens the image instead of saving it. The Web Share API is
// the workaround there. Other platforms (Android, desktop Chrome/Edge/
// Brave, etc.) also report navigator.canShare support for files these
// days, but a plain anchor download already works fine on them, so
// routing them through the OS share sheet too is an unnecessary detour —
// gate the workaround to iOS specifically instead of "canShare exists".
function isIOS(): boolean {
    if (typeof navigator === 'undefined') return false;
    const platformMatch = /iPad|iPhone|iPod/.test(navigator.userAgent);
    // iPadOS 13+ reports as "MacIntel" in the UA string but is touch-
    // capable, unlike an actual Mac.
    const iPadOS13Up = navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
    return platformMatch || iPadOS13Up;
}

/**
 * Fetches a student's QR code PNG and exposes it as an object URL for
 * <img src>, since the endpoint sits behind Sanctum bearer-token auth and
 * a plain <img> tag can't attach an Authorization header the way our
 * httpClient does. The object URL is revoked whenever the underlying
 * blob changes or the hook unmounts, so we don't leak memory across
 * repeated visits to the page.
 *
 * `enabled` defaults to true; pass `false` to skip the fetch entirely —
 * e.g. the QR screen behind a "photo required" gate, where there's
 * nothing worth fetching yet and doing so anyway would just be a wasted
 * request against an endpoint the officer flow doesn't need until the
 * gate clears.
 */
export function useStudentQr(studentId: number, options?: { enabled?: boolean }) {
    const enabled = options?.enabled ?? true;

    const query = useQuery({
        queryKey: studentQrQueryKey(studentId),
        queryFn: () => httpStudentsRepository.getQrCode(studentId),
        staleTime: 5 * 60 * 1000,
        enabled,
    });

    const [qrUrl, setQrUrl] = useState<string | null>(null);
    const objectUrlRef = useRef<string | null>(null);

    useEffect(() => {
        if (!query.data) return;

        const url = URL.createObjectURL(query.data);
        objectUrlRef.current = url;
        setQrUrl(url);

        return () => {
            URL.revokeObjectURL(url);
            objectUrlRef.current = null;
        };
    }, [query.data]);

    const save = useCallback(
        async (filename: string) => {
            if (!query.data) return;

            const file = new File([query.data], filename, { type: 'image/png' });

            // Web Share API with file support is the only reliable way to
            // get an actual "Save Image" prompt on iOS Safari — the
            // <a download> attribute is silently ignored there. Android
            // Chrome and most desktop browsers support it too, and users
            // there already recognize the native share sheet.
            if (isIOS() && navigator.canShare?.({ files: [file] })) {
                try {
                    await navigator.share({ files: [file], title: filename });
                    return;
                } catch (error) {
                    // User dismissed the share sheet — that's a deliberate
                    // cancel, not a failure, so don't fall back to a
                    // surprise second download attempt underneath it.
                    if (error instanceof Error && error.name === 'AbortError') return;
                }
            }

            // Desktop and browsers without file-sharing support: a normal
            // anchor download triggers a straight save-to-disk.
            const url = objectUrlRef.current ?? URL.createObjectURL(query.data);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            if (!objectUrlRef.current) URL.revokeObjectURL(url);
        },
        [query.data],
    );

    const handleSave = useCallback(
        (filename: string) => {
            save(filename).catch(() => {
                toast('Couldn\'t save the QR code — try again.');
            });
        },
        [save],
    );

    return {
        qrUrl,
        isLoading: query.isLoading,
        isError: query.isError,
        error: query.error,
        refetch: query.refetch,
        save: handleSave,
    };
}
