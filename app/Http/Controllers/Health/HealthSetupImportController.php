<?php

namespace App\Http\Controllers\Health;

use App\Services\HealthOnboardingImportService as Onboarding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Hospital setup by spreadsheet — the screen (Task 1555).
 *
 * Three presses, in this order, and no way to skip the middle one:
 *
 *   1. DOWNLOAD the template for one dataset.
 *   2. UPLOAD the filled file. Nothing is written. The panel says, row by row,
 *      what it would create, what it would update and what it refuses and why.
 *   3. IMPORT, which re-reads THE STORED FILE — not anything the browser is
 *      holding — and applies exactly the decision that was shown.
 *
 * The stored file is why there is no cache here. A preview parked in the cache
 * evaporates on the next deploy, and the hospital's afternoon evaporates with
 * it; a file on disk survives, and re-reading it is the only way the commit can
 * honestly claim to be doing what the preview promised.
 */
class HealthSetupImportController extends HealthPanelController
{
    /** Uploads older than this are swept — a preview is not a filing cabinet. */
    private const KEEP_HOURS = 24;

    public function index()
    {
        $this->require('setup.import');
        $this->sweep();

        return view('health.setup.import', [
            'datasets' => Onboarding::datasetsFor($this->company()),
            'dataset' => null,
            'preview' => null,
            'token' => null,
        ]);
    }

    /** The blank workbook for one dataset. */
    public function template(string $dataset): BinaryFileResponse
    {
        $this->require('setup.import');
        $this->requireDataset($dataset);

        $company = $this->company();
        $path = Onboarding::buildTemplate($dataset, $company);

        return response()
            ->download($path, 'setup-' . $dataset . '.xlsx')
            ->deleteFileAfterSend(true);
    }

    /**
     * Store the upload and show what it would do. Writes nothing.
     */
    public function upload(Request $request, string $dataset)
    {
        $this->require('setup.import');
        $this->requireDataset($dataset);

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240',
        ]);

        $company = $this->company();
        $token = Str::lower(Str::random(32));
        $relative = Onboarding::DISK_DIRECTORY . '/' . $company->id . '/' . $token . '.' . $request->file('file')->getClientOriginalExtension();

        Storage::disk('local')->put($relative, file_get_contents($request->file('file')->getRealPath()));

        return redirect()->route('health.setup.import.preview', ['dataset' => $dataset, 'token' => $token]);
    }

    /** Re-parse the stored file and render the row-by-row verdict. */
    public function preview(string $dataset, string $token)
    {
        $this->require('setup.import');
        $this->requireDataset($dataset);

        $company = $this->company();
        $path = $this->storedPath($token);

        if ($path === null) {
            return redirect()->route('health.setup.import')->with('error', __('health.import_err_file_gone'));
        }

        $parsed = Onboarding::parseFile($dataset, Storage::disk('local')->path($path));

        if ($parsed['error'] === 'unreadable') {
            return redirect()->route('health.setup.import')->with('error', __('health.import_err_unreadable'));
        }
        if ($parsed['error'] === 'headers' || !empty($parsed['missing'])) {
            return redirect()->route('health.setup.import')->with('error', __('health.import_err_missing_columns', [
                'columns' => implode(', ', $parsed['missing']),
            ]));
        }
        if ($parsed['error'] === 'too_many') {
            return redirect()->route('health.setup.import')->with('error', __('health.import_err_too_many', [
                'max' => (string) Onboarding::MAX_ROWS,
            ]));
        }
        if (empty($parsed['rows'])) {
            return redirect()->route('health.setup.import')->with('error', __('health.import_err_no_rows'));
        }

        $analysis = Onboarding::analyse($dataset, $company, $parsed['rows']);

        // This press is the middle step, so it is the one that leaves a record.
        // Until it does, commit refuses.
        $this->markReviewed($dataset, $token, $path);

        return view('health.setup.import', [
            'datasets' => Onboarding::datasetsFor($company),
            'dataset' => $dataset,
            'preview' => $analysis,
            'token' => $token,
        ]);
    }

    /**
     * Apply the import.
     *
     * The whole file is re-parsed and re-analysed here. The browser sends a
     * token and nothing else, so there is no payload to tamper with and no way
     * for the preview and the write to disagree.
     */
    public function commit(Request $request, string $dataset, string $token)
    {
        $this->require('setup.import');
        $this->requireDataset($dataset);

        $company = $this->company();
        $path = $this->storedPath($token);

        if ($path === null) {
            return redirect()->route('health.setup.import')->with('error', __('health.import_err_file_gone'));
        }

        /*
         * The middle step is not optional. Without this, a token straight out
         * of the upload redirect is enough to write the whole file, and the
         * three-press flow is only a suggestion the URL bar can decline.
         *
         * The record also pins the file's CONTENT, so a sheet swapped under the
         * same token between the preview and the press has to be looked at
         * again — otherwise "the commit does exactly what the preview showed"
         * would be true of a file nobody previewed.
         */
        $review = $this->reviewRecord($token);
        $fingerprint = @hash_file('sha256', Storage::disk('local')->path($path)) ?: '';

        if ($review === null || ($review['dataset'] ?? null) !== $dataset) {
            return redirect()->route('health.setup.import.preview', ['dataset' => $dataset, 'token' => $token])
                ->with('error', __('health.import_err_review_first'));
        }
        if (($review['fingerprint'] ?? null) !== $fingerprint) {
            return redirect()->route('health.setup.import.preview', ['dataset' => $dataset, 'token' => $token])
                ->with('error', __('health.import_err_file_changed'));
        }

        $parsed = Onboarding::parseFile($dataset, Storage::disk('local')->path($path));
        if (!empty($parsed['error']) || empty($parsed['rows'])) {
            return redirect()->route('health.setup.import')->with('error', __('health.import_err_unreadable'));
        }

        $analysis = Onboarding::analyse($dataset, $company, $parsed['rows']);
        $result = Onboarding::commit($dataset, $company, $analysis['rows'], $this->user());

        // The reviewed file has done its job. Leaving it on disk would leave a
        // sheet of staff CNICs and patient details sitting in storage.
        Storage::disk('local')->delete($path);
        Storage::disk('local')->delete($this->reviewPath($token));

        $message = __('health.import_done', [
            'created' => (string) $result['created'],
            'updated' => (string) $result['updated'],
            'failed' => (string) $result['failed'],
        ]);

        return redirect()->route('health.setup.import')
            ->with($result['failed'] > 0 ? 'warning' : 'success', $message)
            ->with('importMessages', $result['messages'])
            ->with('importCredentials', $result['credentials']);
    }

    /** Throw the stored file away without importing it. */
    public function discard(string $token)
    {
        $this->require('setup.import');

        $path = $this->storedPath($token);
        if ($path !== null) {
            Storage::disk('local')->delete($path);
        }
        Storage::disk('local')->delete($this->reviewPath($token));

        return redirect()->route('health.setup.import')->with('success', __('health.import_discarded'));
    }

    /* ─────────────────────────────── internals ────────────────────────────── */

    private function requireDataset(string $dataset): void
    {
        if (!Onboarding::isDataset($dataset) || !in_array($dataset, Onboarding::datasetsFor($this->company()), true)) {
            abort(404);
        }
    }

    /**
     * The stored upload for a token, inside THIS company's own folder.
     *
     * The token alone is never trusted to identify a file: the lookup is
     * rooted at the company's directory, so a token guessed or copied from
     * another hospital resolves to nothing rather than to their staff sheet.
     */
    private function storedPath(string $token): ?string
    {
        if (!preg_match('/^[a-z0-9]{32}$/', $token)) {
            return null;
        }

        $directory = Onboarding::DISK_DIRECTORY . '/' . $this->company()->id;

        foreach (Storage::disk('local')->files($directory) as $file) {
            if (Str::startsWith(basename($file), $token . '.')) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Where this company's "the preview really was looked at" record lives.
     *
     * Deliberately NOT named "{token}.something": storedPath() finds the upload
     * by that exact prefix, and a review file that answered to it would be
     * handed to the parser as if it were the workbook.
     */
    private function reviewPath(string $token): string
    {
        $safe = preg_match('/^[a-z0-9]{32}$/', $token) ? $token : 'invalid';

        return Onboarding::DISK_DIRECTORY . '/' . $this->company()->id . '/' . $safe . '-review.json';
    }

    /** Record that the preview rendered, and for which file exactly. */
    private function markReviewed(string $dataset, string $token, string $uploadPath): void
    {
        Storage::disk('local')->put($this->reviewPath($token), json_encode([
            'dataset' => $dataset,
            'fingerprint' => @hash_file('sha256', Storage::disk('local')->path($uploadPath)) ?: '',
            'reviewed_at' => now()->toDateTimeString(),
        ]));
    }

    /** The review record for a token, or null when the preview was skipped. */
    private function reviewRecord(string $token): ?array
    {
        $path = $this->reviewPath($token);

        if (!Storage::disk('local')->exists($path)) {
            return null;
        }

        $decoded = json_decode((string) Storage::disk('local')->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Drop abandoned uploads. Cheap, and it runs whenever the screen opens. */
    private function sweep(): void
    {
        $directory = Onboarding::DISK_DIRECTORY . '/' . $this->company()->id;
        $disk = Storage::disk('local');

        if (!$disk->exists($directory)) {
            return;
        }

        $cutoff = now()->subHours(self::KEEP_HOURS)->getTimestamp();
        foreach ($disk->files($directory) as $file) {
            try {
                if ($disk->lastModified($file) < $cutoff) {
                    $disk->delete($file);
                }
            } catch (\Throwable $e) {
                // A file that vanished under us needs no sweeping.
            }
        }
    }
}
