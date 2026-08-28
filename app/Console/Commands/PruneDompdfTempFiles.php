<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Clears out DomPDF's abandoned image scratch files.
 *
 * Every image DomPDF puts on a page is first copied into a temp file, and it
 * only tidies those away at the end of a render that finishes. A render that
 * throws leaves them behind permanently, and nothing in DomPDF or Laravel ever
 * comes back for them.
 *
 * That is not a theoretical tidiness problem. A background job rendering
 * invoice PDFs hit an environment fault and failed on every attempt for four
 * days; the failures were retried, and the leftovers — 522,000 of them —
 * quietly ate 7.9 GB of the hosting account's disk quota. They were invisible
 * because they live in the account's private /tmp, not anywhere inside the
 * project.
 *
 * The individual leaks are now closed where we know about them, but this
 * sweep is the backstop: it does not care WHY a file was orphaned, so it also
 * covers the receipt, day-close, report and export renders, and any PDF path
 * written after this comment. A file still being used by a live render is
 * seconds old, never hours, so the age floor makes this safe to run beside
 * anything.
 */
class PruneDompdfTempFiles extends Command
{
    protected $signature = 'pdf:prune-temp
        {--hours=2 : leave anything younger than this alone}';

    protected $description = 'Delete DomPDF image temp files left behind by renders that failed';

    /**
     * DomPDF's own prefixes: "ca_" for cached source images (Image\Cache),
     * "bg_" for backgrounds, and "<type>_" for format conversions.
     */
    private const PATTERNS = [
        'ca_dompdf_img_*',
        'bg_dompdf_img_*',
        '*_dompdf_img_*',
    ];

    public function handle(): int
    {
        $dir = sys_get_temp_dir();
        $cutoff = time() - (max(1, (int) $this->option('hours')) * 3600);

        $removed = 0;
        $bytes = 0;
        $seen = [];

        foreach (self::PATTERNS as $pattern) {
            foreach (glob($dir . '/' . $pattern, GLOB_NOSORT) ?: [] as $file) {
                // The patterns overlap on purpose (the last one is the catch-all
                // for conversion prefixes we have not met yet), so a file can
                // come round twice.
                if (isset($seen[$file])) {
                    continue;
                }
                $seen[$file] = true;

                $mtime = @filemtime($file);
                if ($mtime === false || $mtime >= $cutoff) {
                    continue;
                }

                $size = (int) @filesize($file);
                if (@unlink($file)) {
                    $removed++;
                    $bytes += $size;
                }
            }
        }

        if ($removed > 0) {
            // Worth a line in the log: a sweep that keeps finding thousands of
            // files means a render is still failing somewhere upstream, and
            // that is the thing to go and fix.
            Log::warning('DomPDF temp files pruned', [
                'files' => $removed,
                'megabytes' => round($bytes / 1048576, 1),
                'directory' => $dir,
            ]);
        }

        $this->info("Removed {$removed} orphaned DomPDF temp files (" . round($bytes / 1048576, 1) . ' MB).');

        return self::SUCCESS;
    }
}
