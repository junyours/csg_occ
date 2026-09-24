<?php

namespace App\Domain\Contracts;

interface RosterPdfRendererInterface
{
    /**
     * Render a roster report — the array shape returned by
     * BuildEventRosterReport — as a printable PDF and return the raw
     * binary bytes. Mirrors QrCodeRendererInterface's shape: the domain
     * only knows it needs "bytes out of a report array", not which PDF
     * library produces them.
     */
    public function render(array $report): string;

    /**
     * Same contract as render(), for the array shape BuildMasterRosterReport
     * returns instead — an 'events' list in place of a single 'event',
     * and 'sessions' entries carrying their own event_id/event_name.
     */
    public function renderMaster(array $report): string;
}
