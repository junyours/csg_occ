import { useCallback, useEffect, useRef, useState } from 'react';

import { httpReportsRepository } from '@/infrastructure/reports/reports.repository.http';
import type { ReportGeneration, RosterReportFilters } from '@/infrastructure/reports/reports.repository.http';

const POLL_INTERVAL_MS = 1500;

// Wall-clock ceiling on the whole "start → poll" flow. Without this, a
// generation job that dies mid-way on the server (PHP-FPM worker killed,
// memory limit hit, server restart) never flips to completed/failed, and
// this hook would poll every 1.5s forever for as long as the tab stays
// open — a stuck progress bar with no error and no way out but a reload.
// 5 minutes is comfortably above any real roster export on this app's
// data volumes; if it's still pending past that, something is wrong and
// the person needs a clear "try again" instead of silence.
const POLL_TIMEOUT_MS = 5 * 60 * 1000;

// See use-master-report-generation.ts for the full reasoning — same
// hook, same "animate on its own clock, ratchet up on real data"
// approach, because a single event's report can be just as coarse
// (one group, or a PDF whose single blocking dompdf render can't
// report progress mid-way through) as the master report's.
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
 * Drives the whole "start → poll → download" flow for one event's
 * roster report: start() kicks off generation (a near-instant request,
 * see StartRosterReportGeneration on the server) then polls
 * GET /report-generations/{id} every 1.5s for real, DB-backed progress
 * — processed_steps/total_steps, not a fake timer — until the report is
 * completed (auto-downloads) or failed. displayPercentage layers a
 * smooth creep on top of that real number so the bar keeps visibly
 * moving between polls instead of sitting frozen at 0% then jumping to
 * 100%.
 */
export function useRosterReportGeneration(eventId: number) {
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
        async (filters: RosterReportFilters) => {
            clearPoll();
            clearAnimation();
            setError(null);
            setGeneration(null);
            setDisplayPercentage(0);
            targetPercentageRef.current = 0;
            pollStartedAtRef.current = Date.now();

            const created = await httpReportsRepository.startRosterReportGeneration(eventId, filters);
            setGeneration(created);
            targetPercentageRef.current = created.percentage ?? 0;
            startAnimation();
            pollTimeoutRef.current = setTimeout(() => poll(created.id), POLL_INTERVAL_MS);
        },
        [eventId, poll, clearPoll, clearAnimation, startAnimation],
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
