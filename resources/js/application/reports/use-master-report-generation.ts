import { useCallback, useEffect, useRef, useState } from 'react';

import { httpReportsRepository } from '@/infrastructure/reports/reports.repository.http';
import type { MasterRosterReportFilters, ReportGeneration } from '@/infrastructure/reports/reports.repository.http';

const POLL_INTERVAL_MS = 1500;

// See use-roster-report-generation.ts for the full reasoning: without a
// ceiling, a job that dies mid-way on the server never flips to
// completed/failed and this hook polls forever for as long as the tab
// stays open.
const POLL_TIMEOUT_MS = 5 * 60 * 1000;

// The bar animates on its own clock instead of only jumping on real
// poll results. Real percentages from the server are coarse (a master
// report with one group has only 1-3 actual steps — dompdf in
// particular renders the whole PDF in one blocking call, so it can't
// report mid-render progress at all), so a bar driven purely by real
// data looks stuck, then teleports to 100%. This creeps the displayed
// number up continuously, always staying a little ahead of the last
// known real percentage, and instantly ratchets up (never down)
// whenever a real update arrives — so it always looks like it's
// working, and it's never lying about being further along than the
// last confirmed checkpoint by more than a small, deliberate margin.
const ANIMATION_INTERVAL_MS = 120;
const ANIMATION_STEP = 1.2;
const CREEP_CEILING_AHEAD = 18;

function triggerBrowserDownload(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

/**
 * Drives the whole "start → poll → download" flow for a master report
 * spanning several events at once: start() kicks off generation (a
 * near-instant request, see StartMasterReportGeneration on the server)
 * then polls GET /report-generations/{id} every 1.5s for real,
 * DB-backed progress until the report is completed (auto-downloads) or
 * failed. Structurally identical to useRosterReportGeneration — the
 * only difference is start() takes the full filter set (including
 * eventIds) instead of an eventId fixed at the hook level, since which
 * events are included can change every time the person generates here.
 */
export function useMasterReportGeneration() {
    const [generation, setGeneration] = useState<ReportGeneration | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [displayPercentage, setDisplayPercentage] = useState(0);
    const pollTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const animationRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const targetPercentageRef = useRef(0); // last known REAL percentage from the server
    const pollStartedAtRef = useRef(0); // Date.now() when the current poll loop began

    const clearPoll = useCallback(() => {
        if (pollTimeoutRef.current !== null) {
            clearTimeout(pollTimeoutRef.current);
            pollTimeoutRef.current = null;
        }
    }, []);

    const clearAnimation = useCallback(() => {
        if (animationRef.current !== null) {
            clearInterval(animationRef.current);
            animationRef.current = null;
        }
    }, []);

    useEffect(() => {
        return () => {
            clearPoll();
            clearAnimation();
        };
    }, [clearPoll, clearAnimation]);

    const startAnimation = useCallback(() => {
        clearAnimation();
        animationRef.current = setInterval(() => {
            setDisplayPercentage((current) => {
                const ceiling = Math.min(99, targetPercentageRef.current + CREEP_CEILING_AHEAD);
                return current >= ceiling ? current : Math.min(ceiling, current + ANIMATION_STEP);
            });
        }, ANIMATION_INTERVAL_MS);
    }, [clearAnimation]);

    const poll = useCallback(
        async (id: number) => {
            try {
                const latest = await httpReportsRepository.getReportGeneration(id);
                setGeneration(latest);
                targetPercentageRef.current = latest.percentage;
                setDisplayPercentage((current) => Math.max(current, latest.percentage));

                if (latest.status === 'completed') {
                    clearAnimation();
                    setDisplayPercentage(100);
                    const { blob, filename } = await httpReportsRepository.downloadReportGeneration(latest);
                    triggerBrowserDownload(blob, filename);
                    return;
                }

                if (latest.status === 'failed') {
                    clearAnimation();
                    setError(latest.error_message ?? 'Report generation failed.');
                    return;
                }

                if (Date.now() - pollStartedAtRef.current >= POLL_TIMEOUT_MS) {
                    clearAnimation();
                    setError('This is taking longer than expected. Please try again.');
                    return;
                }

                pollTimeoutRef.current = setTimeout(() => poll(id), POLL_INTERVAL_MS);
            } catch {
                clearAnimation();
                setError('Lost track of the report while it was generating. Try again.');
            }
        },
        [clearAnimation],
    );

    const start = useCallback(
        async (filters: MasterRosterReportFilters) => {
            clearPoll();
            clearAnimation();
            setError(null);
            setGeneration(null);
            setDisplayPercentage(0);
            targetPercentageRef.current = 0;
            pollStartedAtRef.current = Date.now();

            const created = await httpReportsRepository.startMasterReportGeneration(filters);
            setGeneration(created);
            targetPercentageRef.current = created.percentage ?? 0;
            startAnimation();
            pollTimeoutRef.current = setTimeout(() => poll(created.id), POLL_INTERVAL_MS);
        },
        [poll, clearPoll, clearAnimation, startAnimation],
    );

    const reset = useCallback(() => {
        clearPoll();
        clearAnimation();
        setGeneration(null);
        setError(null);
        setDisplayPercentage(0);
    }, [clearPoll, clearAnimation]);

    const isActive = generation !== null && generation.status !== 'completed' && generation.status !== 'failed';

    return { start, reset, generation, isActive, error, displayPercentage };
}
