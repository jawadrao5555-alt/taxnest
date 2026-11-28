<?php

namespace App\Services\SwitchMigration;

use InvalidArgumentException;
use RuntimeException;

final class CsvSourcePreview
{
    public const MAX_BYTES = 1_048_576;
    public const MAX_RECORDS = 5000;

    private const REQUIRED_FIELDS = ['source_id', 'display_name'];
    private const OPTIONAL_FIELDS = ['email', 'phone'];

    /**
     * Prepare a customer-master dry run from bytes read from an authorized extract.
     * Only manifest metadata and digests are returned. The authorization reference
     * is supplied by the operator and does not independently verify access.
     */
    public function preview(string $sourceBytes, string $source, string $scope, string $authorizationReference): array
    {
        foreach ([$source, $scope, $authorizationReference] as $identifier) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,95}\z/', $identifier) !== 1) {
                throw new InvalidArgumentException('Use non-empty reference codes of at most 96 characters for the source, scope and authorization.');
            }
        }
        if (strlen($sourceBytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('The CSV extract exceeds the preview size limit.');
        }

        $manifest = [
            'schema_version' => 1,
            'dataset' => 'customer-master',
            'mode' => 'dry-run',
            'source' => $source,
            'scope' => $scope,
            'authorization_reference' => $authorizationReference,
            'input_sha256' => hash('sha256', $sourceBytes),
            'input_bytes' => strlen($sourceBytes),
        ];
        if (str_contains($sourceBytes, "\0") || !mb_check_encoding($sourceBytes, 'UTF-8')) {
            return $this->report($manifest, 0, [], [$this->issue(null, 'invalid_text_encoding')], false);
        }

        $csv = str_starts_with($sourceBytes, "\xEF\xBB\xBF") ? substr($sourceBytes, 3) : $sourceBytes;
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Could not open the in-memory CSV preview.');
        }

        try {
            if (fwrite($stream, $csv) !== strlen($csv) || !rewind($stream)) {
                throw new RuntimeException('Could not initialize the CSV preview.');
            }

            $header = $this->readRecord($stream, $csv);
            if ($header === null || !$header['well_formed']) {
                return $this->report($manifest, 0, [], [$this->issue(1, 'invalid_header')], false);
            }

            $fields = array_map(fn ($field) => strtolower(trim((string) $field)), $header['fields']);
            $columns = [...self::REQUIRED_FIELDS, ...self::OPTIONAL_FIELDS];
            if (count(array_unique($fields)) !== count($fields)) {
                return $this->report($manifest, 0, [], [$this->issue(1, 'duplicate_header')], false);
            }
            if (array_diff(self::REQUIRED_FIELDS, $fields) || array_diff($fields, $columns)) {
                return $this->report($manifest, 0, [], [$this->issue(1, 'invalid_header_columns')], false);
            }

            return $this->scan($stream, $csv, $manifest, $fields, $columns);
        } finally {
            fclose($stream);
        }
    }

    private function scan($stream, string $csv, array $manifest, array $fields, array $columns): array
    {
        $seen = [];
        $accepted = [];
        $issues = [];
        $records = 0;
        $complete = true;

        while (($record = $this->readRecord($stream, $csv)) !== null) {
            if ($records === self::MAX_RECORDS) {
                $issues[] = $this->issue(null, 'record_limit_exceeded');
                $complete = false;
                break;
            }

            $records++;
            $number = $records + 1;
            if (!$record['well_formed']) {
                $issues[] = $this->issue($number, 'malformed_record');
                $complete = false;
                continue;
            }
            if (count($record['fields']) !== count($fields)) {
                $issues[] = $this->issue($number, 'column_count_mismatch');
                continue;
            }

            $values = array_combine($fields, array_map(fn ($field) => trim((string) $field), $record['fields']));
            $canonical = array_replace(array_fill_keys($columns, ''), $values);
            $sourceId = $canonical['source_id'];
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $sourceId) !== 1) {
                $issues[] = $this->issue($number, 'invalid_source_id');
                continue;
            }

            $key = 'source:'.$sourceId;
            if (isset($seen[$key])) {
                if (count($seen[$key]) === 1) {
                    $first = $seen[$key][0];
                    unset($accepted[$first]);
                    $issues[] = $this->issue($first, 'duplicate_source_id');
                }
                $seen[$key][] = $number;
                $issues[] = $this->issue($number, 'duplicate_source_id');
                continue;
            }
            $seen[$key] = [$number];

            if (!$this->validFields($canonical)) {
                $issues[] = $this->issue($number, 'invalid_customer_fields');
                continue;
            }

            $accepted[$number] = [
                'record' => $number,
                'sha256' => hash('sha256', json_encode(['customer-master-v1', $canonical], JSON_THROW_ON_ERROR)),
            ];
        }

        if ($records === 0) {
            $issues[] = $this->issue(null, 'no_records');
        }

        return $this->report($manifest, $records, $accepted, $issues, $complete);
    }

    private function readRecord($stream, string $csv): ?array
    {
        $offset = ftell($stream);
        $fields = fgetcsv($stream, null, ',', '"', '');
        if ($fields === false) {
            return null;
        }

        // fgetcsv accepts malformed quoting. Validate the exact consumed bytes
        // before treating its decoded fields as a logical source record.
        $raw = substr($csv, $offset, ftell($stream) - $offset);
        $field = '(?:"(?:[^"]++|"")*+"|[^,"\r\n]*+)';
        $wellFormed = preg_match('/\A'.$field.'(?:,'.$field.')*+(?:\r?\n)?\z/', $raw) === 1;

        return ['fields' => $fields, 'well_formed' => $wellFormed];
    }

    private function validFields(array $customer): bool
    {
        return $customer['display_name'] !== ''
            && mb_strlen($customer['display_name']) <= 120
            && preg_match('/[\x00-\x1F\x7F]/', $customer['display_name']) !== 1
            && ($customer['email'] === '' || filter_var($customer['email'], FILTER_VALIDATE_EMAIL) !== false)
            && ($customer['phone'] === '' || preg_match('/\A(?=.*[0-9])[0-9+(). -]{3,32}\z/', $customer['phone']) === 1);
    }

    private function issue(?int $record, string $code): array
    {
        return ['record' => $record, 'code' => $code];
    }

    private function report(array $manifest, int $records, array $accepted, array $issues, bool $complete): array
    {
        usort($issues, fn ($left, $right) => ($left['record'] ?? 0) <=> ($right['record'] ?? 0));
        $acceptedCount = count($accepted);

        return $manifest + [
            'status' => $issues === [] ? 'valid' : 'rejected',
            'record_scan_complete' => $complete,
            'observed_records' => $records,
            'accepted_records' => $acceptedCount,
            'rejected_observed_records' => $records - $acceptedCount,
            'record_checksums' => array_values($accepted),
            'issues' => $issues,
        ];
    }
}
