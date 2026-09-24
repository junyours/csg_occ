<?php

namespace App\Infrastructure\Pdf;

use App\Domain\Contracts\RosterPdfRendererInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;

/**
 * Plain dompdf/dompdf, not a Blade-package wrapper — the report request
 * explicitly rules out anything that needs a queue worker (shared
 * hosting), and dompdf renders synchronously in-process like every
 * other action in this app.
 *
 * Trade-off worth knowing: dompdf builds the whole document in memory
 * before writing a single byte, so a full-school export (every
 * department/year/section with no filters — e.g. ~7,800 students across
 * 150+ groups) can genuinely need several hundred MB and tens of
 * seconds. That's a real cost of "no queue on shared hosting", not a
 * bug — the fix is either raising these two limits (done below, for
 * this request only) or the person filtering to one department/section
 * at a time from the report screen.
 */
final class DompdfRosterPdfRenderer implements RosterPdfRendererInterface
{
    public function render(array $report): string
    {
        return $this->renderView('reports.roster-pdf', $report);
    }

    /**
     * The master (multi-event) report can span even more columns than
     * a single busy event, so it gets the same treatment — the limit
     * raise below and landscape legal paper apply here too.
     */
    public function renderMaster(array $report): string
    {
        return $this->renderView('reports.master-roster-pdf', $report);
    }

    private function renderView(string $view, array $report): string
    {
        $this->raiseLimitsForLargeExports();

        $html = View::make($view, ['report' => $report])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        // Landscape: session columns run wide once an event has more than
        // a couple of days, and this is meant to be printed/handed out.
        $dompdf->setPaper('legal', 'landscape');
        $dompdf->loadHtml($html);
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * ini_set()/set_time_limit() work per-request without touching
     * php.ini — safe on shared hosting, and scoped to only this
     * expensive request rather than raised globally. Some hosts disable
     * these functions entirely (disable_functions in php.ini), so both
     * calls are guarded — if they're unavailable we just proceed at
     * whatever the host's defaults are instead of fataling here.
     */
    private function raiseLimitsForLargeExports(): void
    {
        if (function_exists('ini_set')) {
            // -1 would be "unlimited", which is too easy to abuse on a
            // shared box if a filter is ever left wide open — 512M
            // comfortably covers even the full ~7,800-student case.
            @ini_set('memory_limit', '512M');
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
    }
}
