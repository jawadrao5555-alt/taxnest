<?php

namespace App\Console\Commands;

use App\Services\SwitchMigration\CsvSourcePreview;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class PreviewSwitchCsv extends Command
{
    protected $signature = 'switch:preview-csv
        {file : Local UTF-8 CSV with source_id, display_name, optional email and phone}
        {source : Stable source system reference code}
        {scope : Stable approved customer scope reference code}
        {authorization : Reference to the recorded authorization for this extract}';

    protected $description = 'Validate an authorized local CSV extract and print a dry-run manifest';

    public function handle(CsvSourcePreview $preview): int
    {
        if (!app()->environment(['local', 'testing'])) {
            $this->error('CSV dry runs are available only in local or testing environments.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('file');
        if (str_contains($path, '://') || !is_file($path) || !is_readable($path)) {
            $this->error('Select a readable local file.');

            return self::FAILURE;
        }

        $file = @fopen($path, 'rb');
        if ($file === false) {
            $this->error('Could not read the local CSV extract.');

            return self::FAILURE;
        }

        try {
            $bytes = stream_get_contents($file, CsvSourcePreview::MAX_BYTES + 1);
        } finally {
            fclose($file);
        }
        if ($bytes === false) {
            $this->error('Could not read the local CSV extract.');

            return self::FAILURE;
        }

        try {
            $manifest = $preview->preview(
                $bytes,
                (string) $this->argument('source'),
                (string) $this->argument('scope'),
                (string) $this->argument('authorization'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $manifest['status'] === 'valid' ? self::SUCCESS : self::FAILURE;
    }
}
