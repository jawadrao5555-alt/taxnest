<?php

namespace Tests\Unit;

use App\Services\SwitchMigration\CsvSourcePreview;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SwitchCsvSourcePreviewTest extends TestCase
{
    public function test_source_and_record_checksums_distinguish_original_bytes_from_canonical_records(): void
    {
        $original = "source_id,display_name,email\ncustomer-07,\"Example \"\"South\"\", Office\",office@example.invalid\n";
        $reordered = "\xEF\xBB\xBFEMAIL,display_name,SOURCE_ID,phone\r\noffice@example.invalid,\"Example \"\"South\"\", Office\",customer-07,\r\n";
        $preview = new CsvSourcePreview();
        $first = $preview->preview($original, 'fixture-source', 'example-installation', 'approval-001');
        $second = $preview->preview($reordered, 'fixture-source', 'example-installation', 'approval-001');

        self::assertSame('valid', $first['status']);
        self::assertSame('valid', $second['status']);
        self::assertSame(hash('sha256', $original), $first['input_sha256']);
        self::assertSame(hash('sha256', $reordered), $second['input_sha256']);
        self::assertNotSame($first['input_sha256'], $second['input_sha256']);
        self::assertSame($first['record_checksums'], $second['record_checksums']);
        self::assertSame('approval-001', $first['authorization_reference']);
        self::assertSame(1, $first['observed_records']);
        self::assertSame(1, $first['accepted_records']);
        self::assertSame(0, $first['rejected_observed_records']);
        $serialized = json_encode($first, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('customer-07', $serialized);
        self::assertStringNotContainsString('office@example.invalid', $serialized);
        self::assertStringNotContainsString('Example', $serialized);
    }

    public function test_all_records_in_a_duplicate_identity_group_are_rejected(): void
    {
        $preview = new CsvSourcePreview();
        $result = $preview->preview(
            "source_id,display_name\nalpha,First\nbeta,Valid\nalpha,Repeated\nalpha,Third\n",
            'fixture-source',
            'example-installation',
            'approval-002',
        );

        self::assertSame('rejected', $result['status']);
        self::assertTrue($result['record_scan_complete']);
        self::assertSame(4, $result['observed_records']);
        self::assertSame(1, $result['accepted_records']);
        self::assertSame(3, $result['rejected_observed_records']);
        self::assertSame([3], array_column($result['record_checksums'], 'record'));
        self::assertSame([2, 4, 5], array_column($result['issues'], 'record'));
        self::assertSame(['duplicate_source_id', 'duplicate_source_id', 'duplicate_source_id'], array_column($result['issues'], 'code'));
    }

    public static function invalidFiles(): array
    {
        return [
            ['source_id,display_name, SOURCE_ID', 'duplicate_header', 0, false],
            ['source_id,display_name,password', 'invalid_header_columns', 0, false],
            ['source_id,phone', 'invalid_header_columns', 0, false],
            ["source_id,display_name\na,\"No closing quote\nb,Other", 'malformed_record', 1, false],
            ["source_id,display_name\na,\"Valid close\"trailing", 'malformed_record', 1, false],
            ["source_id,display_name\na,Unescaped\"quote", 'malformed_record', 1, false],
            ["source_id,display_name\na,Extra,Third", 'column_count_mismatch', 1, true],
            ["source_id,display_name\n,Bad source", 'invalid_source_id', 1, true],
            ["source_id,display_name,email\na,Name,bad-address", 'invalid_customer_fields', 1, true],
            ["source_id,display_name\n", 'no_records', 0, true],
            ["source_id,display_name\na,\xFF", 'invalid_text_encoding', 0, false],
        ];
    }

    #[DataProvider('invalidFiles')]
    public function test_invalid_sources_cannot_produce_a_valid_preview(string $input, string $code, int $records, bool $complete): void
    {
        $result = (new CsvSourcePreview())->preview($input, 'fixture-source', 'scope-1', 'approval-003');

        self::assertSame('rejected', $result['status']);
        self::assertSame($records, $result['observed_records']);
        self::assertSame($records, $result['rejected_observed_records']);
        self::assertSame($complete, $result['record_scan_complete']);
        self::assertSame([$code], array_column($result['issues'], 'code'));
        self::assertSame([], $result['record_checksums']);
    }

    public function test_record_limit_marks_the_manifest_incomplete(): void
    {
        $input = "source_id,display_name\n";
        for ($number = 0; $number <= CsvSourcePreview::MAX_RECORDS; $number++) {
            $input .= "customer-{$number},Example\n";
        }

        $result = (new CsvSourcePreview())->preview($input, 'fixture-source', 'scope-1', 'approval-004');

        self::assertSame('rejected', $result['status']);
        self::assertFalse($result['record_scan_complete']);
        self::assertSame(CsvSourcePreview::MAX_RECORDS, $result['observed_records']);
        self::assertSame(['record_limit_exceeded'], array_column($result['issues'], 'code'));
    }

    public function test_preview_requires_an_authorization_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CsvSourcePreview())->preview("source_id,display_name\na,Customer", 'fixture-source', 'scope-1', '');
    }
}
