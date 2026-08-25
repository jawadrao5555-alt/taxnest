<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\AuditPack;
use App\Models\Company;
use App\Models\FbrLog;
use App\Models\Invoice;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Builds "FBR Audit Pack" ZIPs in resumable chunks.
 *
 * A pack is processed one small chunk at a time so that:
 *  - the queue worker (database driver, retry_after=90s) never holds a job longer than a chunk,
 *  - the browser polling endpoint can advance the build inline when no queue worker is running
 *    (shared-hosting deployments may not have a worker cron),
 *  - a crashed worker resumes where it left off (claim lock goes stale after STALE_LOCK_SECONDS).
 *
 * ZIP contents: invoices/*.pdf, invoice-register.csv, invoice-register.xlsx,
 * audit-trail.csv, fbr-submission-log.csv, README.txt (+ _pdf-errors.txt when applicable).
 */
class AuditPackBuilderService
{
    public const CHUNK_SIZE = 20;              // invoices (PDFs) per chunk
    public const MAX_INVOICES = 2000;          // per pack — larger ranges must be split
    public const STALE_LOCK_SECONDS = 180;     // claim considered abandoned after this
    public const RETENTION_DAYS = 7;           // generated ZIPs auto-delete after this
    public const AUDIT_TRAIL_ROW_CAP = 100000; // safety cap for huge audit trails

    public static function invoiceQuery(AuditPack $pack)
    {
        return Invoice::where('company_id', $pack->company_id)
            ->whereIn('status', ['locked', 'pending_verification'])
            ->whereDate('invoice_date', '>=', $pack->date_from->toDateString())
            ->whereDate('invoice_date', '<=', $pack->date_to->toDateString());
    }

    /**
     * Atomically claim the pack for one chunk of work.
     */
    public static function claim(AuditPack $pack): bool
    {
        $stale = now()->subSeconds(self::STALE_LOCK_SECONDS);

        return AuditPack::where('id', $pack->id)
            ->whereIn('status', AuditPack::ACTIVE_STATUSES)
            ->where(function ($q) use ($stale) {
                $q->whereNull('locked_at')->orWhere('locked_at', '<', $stale);
            })
            ->update(['locked_at' => now()]) === 1;
    }

    public static function release(AuditPack $pack): void
    {
        AuditPack::where('id', $pack->id)->update(['locked_at' => null]);
    }

    /**
     * Process the next chunk of work for this pack.
     *
     * @return string 'done' (ready or failed), 'continue' (more chunks remain), 'busy' (another process holds the claim)
     */
    public static function processNextChunk(AuditPack $pack): string
    {
        if (!self::claim($pack)) {
            return 'busy';
        }

        try {
            $pack->refresh();

            if (!$pack->isActive()) {
                return 'done';
            }

            if ($pack->status === 'pending') {
                self::initialize($pack);
                return 'continue';
            }

            if ($pack->processed_invoices < $pack->total_invoices) {
                self::processPdfChunk($pack);
                return 'continue';
            }

            self::buildReportsAndFinalize($pack);
            return 'done';
        } catch (\Throwable $e) {
            Log::error('AuditPack build failed', ['pack_id' => $pack->id, 'error' => $e->getMessage()]);
            $pack->forceFill([
                'status' => 'failed',
                'error_message' => mb_substr(trim(($pack->error_message ? $pack->error_message . "\n" : '') . 'FATAL: ' . $e->getMessage()), 0, 4000),
            ])->save();
            self::notify($pack, false);
            return 'done';
        } finally {
            self::release($pack);
        }
    }

    protected static function initialize(AuditPack $pack): void
    {
        $total = self::invoiceQuery($pack)->count();

        if (!$pack->file_path) {
            $pack->file_path = 'audit-packs/company_' . $pack->company_id . '/fbr-audit-pack-' . $pack->id . '.zip';
        }

        Storage::disk('local')->makeDirectory(dirname($pack->file_path));

        $pack->forceFill([
            'status' => 'processing',
            'total_invoices' => $total,
            'processed_invoices' => 0,
            'progress' => 1,
        ])->save();
    }

    protected static function processPdfChunk(AuditPack $pack): void
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '1024M');

        $invoices = self::invoiceQuery($pack)
            ->orderBy('id')
            ->skip($pack->processed_invoices)
            ->take(self::CHUNK_SIZE)
            ->get();

        if ($invoices->isEmpty()) {
            $pack->forceFill(['processed_invoices' => $pack->total_invoices])->save();
            return;
        }

        $passed = 0;
        $failed = 0;
        $missing = 0;
        $failedNumbers = [];
        $errors = [];

        self::withZip($pack, function (\ZipArchive $zip) use ($invoices, &$passed, &$failed, &$missing, &$failedNumbers, &$errors) {
            foreach ($invoices as $invoice) {
                if (!$invoice->integrity_hash) {
                    $missing++;
                } elseif (IntegrityHashService::verify($invoice)) {
                    $passed++;
                } else {
                    $failed++;
                    $failedNumbers[] = (string) ($invoice->internal_invoice_number ?: ($invoice->invoice_number ?: ('#' . $invoice->id)));
                }

                try {
                    $data = InvoicePdfService::buildData($invoice);
                    $pdf = InvoicePdfService::make('invoice.pdf-bw', $data);
                    $pdf->setPaper('A4', 'portrait');

                    $base = $invoice->fbr_invoice_number
                        ?: ($invoice->internal_invoice_number
                            ?: ($invoice->invoice_number ?: ('invoice-' . $invoice->id)));
                    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $base);
                    $name = 'invoices/' . $safe . '.pdf';
                    $n = 1;
                    while ($zip->locateName($name) !== false) {
                        $name = 'invoices/' . $safe . '__' . (++$n) . '.pdf';
                    }
                    $zip->addFromString($name, $pdf->output());
                } catch (\Throwable $e) {
                    $errors[] = ($invoice->internal_invoice_number ?: ('#' . $invoice->id)) . ': ' . $e->getMessage();
                }
            }
        });

        $processed = $pack->processed_invoices + $invoices->count();
        $progress = $pack->total_invoices > 0
            ? min(90, (int) floor(($processed / max(1, $pack->total_invoices)) * 90))
            : 90;

        $failedList = trim(($pack->integrity_failed_list ? $pack->integrity_failed_list . "\n" : '') . implode("\n", $failedNumbers));

        $pack->forceFill([
            'processed_invoices' => $processed,
            'progress' => max(1, $progress),
            'integrity_passed' => $pack->integrity_passed + $passed,
            'integrity_failed' => $pack->integrity_failed + $failed,
            'integrity_missing' => $pack->integrity_missing + $missing,
            'integrity_failed_list' => $failedList !== '' ? mb_substr($failedList, 0, 8000) : null,
            'error_message' => !empty($errors)
                ? mb_substr(trim(($pack->error_message ? $pack->error_message . "\n" : '') . implode("\n", $errors)), 0, 4000)
                : $pack->error_message,
        ])->save();
    }

    protected static function buildReportsAndFinalize(AuditPack $pack): void
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '1024M');

        $company = Company::find($pack->company_id);

        $invoices = self::invoiceQuery($pack)
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get();

        [$registerCsv, $registerRows] = self::buildRegister($invoices);
        $registerXlsx = self::buildRegisterXlsx($registerRows, $company, $pack);
        [$auditCsv, $auditCount] = self::buildAuditTrailCsv($pack);
        [$fbrCsv, $fbrCount, $fbrSummary] = self::buildFbrLogCsv($invoices);
        $readme = self::buildReadme($pack, $company, $invoices->count(), $auditCount, $fbrCount, $fbrSummary);

        self::withZip($pack, function (\ZipArchive $zip) use ($pack, $registerCsv, $registerXlsx, $auditCsv, $fbrCsv, $readme) {
            $zip->addFromString('README.txt', $readme);
            $zip->addFromString('invoice-register.csv', $registerCsv);
            if ($registerXlsx !== null) {
                $zip->addFromString('invoice-register.xlsx', $registerXlsx);
            }
            $zip->addFromString('audit-trail.csv', $auditCsv);
            $zip->addFromString('fbr-submission-log.csv', $fbrCsv);
            if ($pack->error_message) {
                $zip->addFromString('_pdf-errors.txt', "The following invoice PDFs could not be generated:\n\n" . $pack->error_message . "\n");
            }
        });

        $abs = Storage::disk('local')->path($pack->file_path);

        $pack->forceFill([
            'status' => 'ready',
            'progress' => 100,
            'file_size' => is_file($abs) ? (filesize($abs) ?: null) : null,
            'completed_at' => now(),
        ])->save();

        self::notify($pack, true);

        try {
            AuditLogService::log('audit_pack_generated', 'AuditPack', $pack->id, null, [
                'date_from' => $pack->date_from->toDateString(),
                'date_to' => $pack->date_to->toDateString(),
                'invoices' => $pack->total_invoices,
                'integrity_passed' => $pack->integrity_passed,
                'integrity_failed' => $pack->integrity_failed,
                'integrity_missing' => $pack->integrity_missing,
            ], $pack->company_id, $pack->user_id);
        } catch (\Throwable $e) {
            Log::warning('AuditPack audit log failed', ['pack_id' => $pack->id, 'error' => $e->getMessage()]);
        }
    }

    protected static function buildRegister($invoices): array
    {
        $header = ['#', 'Internal No', 'FBR Invoice No', 'Invoice Date', 'Document Type', 'Buyer Name', 'Buyer NTN', 'Buyer CNIC', 'Value Excl. ST', 'Sales Tax', 'WHT', 'Total Amount', 'FBR Status', 'Status'];
        $rows = [$header];

        $displayStatus = ['locked' => 'completed', 'pending_verification' => 'pending verification'];

        $i = 0;
        foreach ($invoices as $inv) {
            $i++;
            $rows[] = [
                $i,
                (string) ($inv->internal_invoice_number ?? ''),
                (string) ($inv->fbr_invoice_number ?? ''),
                (string) $inv->invoice_date,
                (string) ($inv->document_type ?? 'Sale Invoice'),
                (string) ($inv->buyer_name ?? ''),
                (string) ($inv->buyer_ntn ?? ''),
                (string) ($inv->buyer_cnic ?? ''),
                number_format((float) ($inv->total_value_excluding_st ?? ($inv->total_amount - $inv->total_sales_tax)), 2, '.', ''),
                number_format((float) $inv->total_sales_tax, 2, '.', ''),
                number_format((float) ($inv->wht_amount ?? 0), 2, '.', ''),
                number_format((float) $inv->total_amount, 2, '.', ''),
                (string) ($inv->fbr_status ?? ''),
                $displayStatus[$inv->status] ?? (string) $inv->status,
            ];
        }

        return [self::toCsv($rows), $rows];
    }

    protected static function buildRegisterXlsx(array $rows, ?Company $company, AuditPack $pack): ?string
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            return null;
        }

        $tmp = null;
        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Invoice Register');

            $sheet->setCellValue('A1', 'FBR Audit Pack — Invoice Register');
            $sheet->setCellValue('A2', ($company->name ?? 'Company') . ($company && $company->ntn ? ' — NTN ' . $company->ntn : ''));
            $sheet->setCellValue('A3', 'Period: ' . $pack->date_from->format('d M Y') . ' to ' . $pack->date_to->format('d M Y'));
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $sheet->getStyle('A2:A3')->getFont()->setSize(10);

            $startRow = 5;
            foreach ($rows as $r => $row) {
                foreach ($row as $c => $value) {
                    $sheet->setCellValue([$c + 1, $startRow + $r], $value);
                }
            }
            $sheet->getStyle('A' . $startRow . ':N' . $startRow)->getFont()->setBold(true);

            $widths = [6, 18, 24, 12, 14, 30, 14, 16, 14, 12, 10, 14, 12, 20];
            foreach ($widths as $idx => $w) {
                $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1))->setWidth($w);
            }

            $tmp = tempnam(sys_get_temp_dir(), 'audit_reg_');
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($tmp);
            $content = file_get_contents($tmp);
            $spreadsheet->disconnectWorksheets();

            return $content !== false ? $content : null;
        } catch (\Throwable $e) {
            Log::warning('AuditPack xlsx register failed, CSV only', ['error' => $e->getMessage()]);
            return null;
        } finally {
            if ($tmp && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    protected static function buildAuditTrailCsv(AuditPack $pack): array
    {
        $rows = [['Log ID', 'Timestamp', 'Action', 'Entity Type', 'Entity ID', 'User', 'IP Address', 'SHA-256 Hash']];
        $count = 0;
        $capped = false;

        AuditLog::where('company_id', $pack->company_id)
            ->whereBetween('created_at', [
                $pack->date_from->copy()->startOfDay(),
                $pack->date_to->copy()->endOfDay(),
            ])
            ->with('user:id,name')
            ->orderBy('id')
            ->chunk(1000, function ($logs) use (&$rows, &$count, &$capped) {
                foreach ($logs as $log) {
                    if ($count >= self::AUDIT_TRAIL_ROW_CAP) {
                        $capped = true;
                        return false;
                    }
                    $count++;
                    $rows[] = [
                        $log->id,
                        optional($log->created_at)->format('Y-m-d H:i:s'),
                        (string) $log->action,
                        (string) ($log->entity_type ?? ''),
                        (string) ($log->entity_id ?? ''),
                        $log->user ? $log->user->name : 'System',
                        (string) ($log->ip_address ?? ''),
                        (string) ($log->sha256_hash ?? ''),
                    ];
                }
            });

        if ($capped) {
            $rows[] = ['', '', 'NOTE: output capped at ' . number_format(self::AUDIT_TRAIL_ROW_CAP) . ' entries', '', '', '', '', ''];
        }

        return [self::toCsv($rows), $count];
    }

    protected static function buildFbrLogCsv($invoices): array
    {
        $rows = [['Log ID', 'Internal Invoice No', 'FBR Invoice No', 'Environment', 'Status', 'Failure Type', 'Response Time (ms)', 'Retry Count', 'Logged At']];
        $count = 0;
        $summary = [];

        $invoiceMap = [];
        foreach ($invoices as $inv) {
            $invoiceMap[$inv->id] = $inv;
        }

        $ids = array_keys($invoiceMap);
        if (!empty($ids)) {
            foreach (array_chunk($ids, 1000) as $idChunk) {
                FbrLog::whereIn('invoice_id', $idChunk)
                    ->orderBy('id')
                    ->chunk(1000, function ($logs) use (&$rows, &$count, &$summary, $invoiceMap) {
                        foreach ($logs as $log) {
                            $count++;
                            $inv = $invoiceMap[$log->invoice_id] ?? null;
                            $status = (string) ($log->status ?? '');
                            $summary[$status] = ($summary[$status] ?? 0) + 1;
                            $rows[] = [
                                $log->id,
                                $inv ? (string) ($inv->internal_invoice_number ?? '') : ('invoice#' . $log->invoice_id),
                                $inv ? (string) ($inv->fbr_invoice_number ?? '') : '',
                                (string) ($log->environment_used ?? ''),
                                $status,
                                (string) ($log->failure_type ?? ''),
                                (string) ($log->response_time_ms ?? ''),
                                (string) ($log->retry_count ?? ''),
                                optional($log->created_at)->format('Y-m-d H:i:s'),
                            ];
                        }
                    });
            }
        }

        return [self::toCsv($rows), $count, $summary];
    }

    protected static function buildReadme(AuditPack $pack, ?Company $company, int $invoiceCount, int $auditCount, int $fbrCount, array $fbrSummary): string
    {
        $lines = [];
        $lines[] = '=====================================================';
        $lines[] = '  TAXNEST DIGITAL INVOICE — FBR AUDIT PACK';
        $lines[] = '=====================================================';
        $lines[] = '';
        $lines[] = 'Company        : ' . ($company->name ?? ('Company #' . $pack->company_id));
        if ($company && $company->ntn) {
            $lines[] = 'NTN            : ' . $company->ntn;
        }
        $lines[] = 'Period         : ' . $pack->date_from->format('d M Y') . ' to ' . $pack->date_to->format('d M Y');
        $lines[] = 'Generated at   : ' . now()->format('d M Y H:i:s') . ' (' . config('app.timezone', 'UTC') . ')';
        $lines[] = 'Generated by   : ' . ($pack->user->name ?? 'System');
        $lines[] = 'Pack reference : AP-' . $pack->id;
        $lines[] = '';
        $lines[] = '-----------------------------------------------------';
        $lines[] = 'CONTENTS';
        $lines[] = '-----------------------------------------------------';
        $lines[] = 'invoices/                : ' . $invoiceCount . ' invoice PDF(s) (completed / FBR-submitted invoices)';
        $lines[] = 'invoice-register.csv     : Invoice register for the period';
        $lines[] = 'invoice-register.xlsx    : Invoice register (Excel format)';
        $lines[] = 'audit-trail.csv          : ' . $auditCount . ' immutable audit log entrie(s) recorded in the period';
        $lines[] = 'fbr-submission-log.csv   : ' . $fbrCount . ' FBR API submission log entrie(s) for these invoices';
        $lines[] = '';
        $lines[] = '-----------------------------------------------------';
        $lines[] = 'INTEGRITY VERIFICATION (SHA-256)';
        $lines[] = '-----------------------------------------------------';
        $lines[] = 'Each completed invoice carries a SHA-256 integrity hash generated at';
        $lines[] = 'FBR submission time. At pack generation, every hash was re-computed';
        $lines[] = 'and compared against the stored value:';
        $lines[] = '';
        $lines[] = '  Passed        : ' . $pack->integrity_passed;
        $lines[] = '  Failed        : ' . $pack->integrity_failed;
        $lines[] = '  No hash       : ' . $pack->integrity_missing . ' (invoices completed before hashing was introduced)';
        if ($pack->integrity_failed > 0 && $pack->integrity_failed_list) {
            $lines[] = '';
            $lines[] = '  Invoices that FAILED verification:';
            foreach (array_slice(preg_split('/\r?\n/', $pack->integrity_failed_list), 0, 200) as $no) {
                if (trim($no) !== '') {
                    $lines[] = '    - ' . trim($no);
                }
            }
        }
        $lines[] = '';
        $lines[] = '-----------------------------------------------------';
        $lines[] = 'FBR SUBMISSION SUMMARY';
        $lines[] = '-----------------------------------------------------';
        if (empty($fbrSummary)) {
            $lines[] = '  No FBR submission log entries in this period.';
        } else {
            ksort($fbrSummary);
            foreach ($fbrSummary as $status => $n) {
                $lines[] = '  ' . str_pad(($status !== '' ? $status : 'unknown'), 14) . ': ' . $n;
            }
        }
        $lines[] = '';
        $lines[] = '-----------------------------------------------------';
        $lines[] = 'NOTES FOR THE AUDITOR / TAX OFFICER';
        $lines[] = '-----------------------------------------------------';
        $lines[] = '- Audit log entries are immutable: the application refuses updates and';
        $lines[] = '  deletes at the model layer, and each entry carries its own SHA-256 hash.';
        $lines[] = '- The invoice register lists all completed (FBR-submitted or pending';
        $lines[] = '  verification) invoices dated within the selected period.';
        $lines[] = '- Draft invoices are not part of this pack; they are not tax documents.';
        $lines[] = '';
        $lines[] = 'Generated by TaxNest Digital Invoice — https://taxnest.pk';
        $lines[] = '';

        return implode("\r\n", $lines);
    }

    protected static function toCsv(array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv === false ? '' : $csv;
    }

    protected static function withZip(AuditPack $pack, \Closure $callback): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP zip extension is not available on this server.');
        }
        if (!$pack->file_path) {
            throw new \RuntimeException('Audit pack has no file path set.');
        }

        $abs = Storage::disk('local')->path($pack->file_path);
        if (!is_dir(dirname($abs))) {
            @mkdir(dirname($abs), 0775, true);
        }

        $zip = new \ZipArchive();
        $result = $zip->open($abs, \ZipArchive::CREATE);
        if ($result !== true) {
            throw new \RuntimeException('Could not open audit pack ZIP (error code ' . $result . ').');
        }

        $callback($zip);

        if (!$zip->close()) {
            throw new \RuntimeException('Could not write audit pack ZIP to disk.');
        }
    }

    protected static function notify(AuditPack $pack, bool $success): void
    {
        try {
            Notification::create([
                'company_id' => $pack->company_id,
                'user_id' => $pack->user_id,
                'type' => 'audit_pack',
                'title' => $success ? 'FBR Audit Pack ready' : 'FBR Audit Pack failed',
                'message' => $success
                    ? 'Your Audit Pack (' . $pack->date_from->format('d M Y') . ' – ' . $pack->date_to->format('d M Y') . ') is ready. Download it from the Compliance page within ' . self::RETENTION_DAYS . ' days.'
                    : 'Your Audit Pack (' . $pack->date_from->format('d M Y') . ' – ' . $pack->date_to->format('d M Y') . ') could not be generated. Please try again from the Compliance page.',
                'read' => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AuditPack notification failed', ['pack_id' => $pack->id, 'error' => $e->getMessage()]);
        }

        // Email the requester too (queued: only inserts a jobs row, so a broken
        // SMTP setup can never affect pack generation). Non-fatal like the
        // in-app notification above.
        try {
            $user = $pack->user;
            if ($user && $user->email) {
                $company = Company::find($pack->company_id);
                $period = $pack->date_from->format('d M Y') . ' – ' . $pack->date_to->format('d M Y');
                $ctaUrl = route('compliance.index');

                if ($success) {
                    $expiresAt = $pack->expiresAt();
                    $expires = optional($expiresAt)->format('d M Y');

                    // One-click download: temporary signed URL (no login needed).
                    // Expiry = pack retention expiry; after that the link is dead
                    // and the file is deleted anyway. Falls back to the Compliance
                    // page if the URL can't be signed for any reason.
                    $downloadUrl = $ctaUrl;
                    try {
                        if ($expiresAt && $expiresAt->isFuture()) {
                            $downloadUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                                'compliance.packs.download-signed',
                                $expiresAt,
                                ['pack' => $pack->id]
                            );
                        }
                    } catch (\Throwable $e) {
                        Log::warning('AuditPack signed URL failed, using Compliance page link', ['pack_id' => $pack->id, 'error' => $e->getMessage()]);
                    }

                    $paragraphs = [
                        'Your FBR Audit Pack for the period ' . $period . ' is ready to download.',
                        'It contains ' . number_format((int) $pack->total_invoices) . ' invoice PDF(s) plus the invoice register, audit trail and FBR submission log.',
                        'The button below downloads the ZIP directly — no login needed. The link works' . ($expires ? ' until ' . $expires : ' for ' . self::RETENTION_DAYS . ' days') . '; after that the file is automatically deleted (' . self::RETENTION_DAYS . '-day retention). You can also download it any time from your Compliance page.',
                    ];
                    $mail = new \App\Mail\AuditPackMail(
                        subjectLine: 'Your FBR Audit Pack is ready (' . $period . ')',
                        companyName: $company->name ?? 'your company',
                        headline: 'FBR Audit Pack ready',
                        paragraphs: $paragraphs,
                        ctaUrl: $downloadUrl,
                        ctaLabel: 'Download Audit Pack',
                        panelName: 'Digital Invoicing',
                    );
                } else {
                    $mail = new \App\Mail\AuditPackMail(
                        subjectLine: 'FBR Audit Pack could not be generated (' . $period . ')',
                        companyName: $company->name ?? 'your company',
                        headline: 'FBR Audit Pack failed',
                        paragraphs: [
                            'Unfortunately your FBR Audit Pack for the period ' . $period . ' could not be generated.',
                            'Please open the Compliance page and try again. If the problem keeps happening, contact support.',
                        ],
                        ctaUrl: $ctaUrl,
                        ctaLabel: 'Open Compliance Page',
                        panelName: 'Digital Invoicing',
                    );
                }

                \Illuminate\Support\Facades\Mail::to($user->email)->queue($mail);
            }
        } catch (\Throwable $e) {
            Log::warning('AuditPack email failed', ['pack_id' => $pack->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Delete packs (rows + files) older than the retention window for a company.
     */
    public static function cleanupExpired(int $companyId): void
    {
        try {
            $expired = AuditPack::where('company_id', $companyId)
                ->where('created_at', '<', now()->subDays(self::RETENTION_DAYS))
                ->get();

            foreach ($expired as $pack) {
                if ($pack->file_path) {
                    Storage::disk('local')->delete($pack->file_path);
                }
                $pack->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('AuditPack cleanup failed', ['company_id' => $companyId, 'error' => $e->getMessage()]);
        }
    }
}
