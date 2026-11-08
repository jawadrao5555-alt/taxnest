<?php

namespace Tests\Unit;

use App\Helpers\DbCompat;
use Tests\TestCase;

class DbCompatTest extends TestCase
{
    public function test_sqlite_date_parts_use_supported_integer_strftime_expressions(): void
    {
        $original = config('database.default');
        config(['database.default' => 'sqlite']);

        try {
            $this->assertSame("CAST(strftime('%Y', occurred_at) AS INTEGER)", DbCompat::extractYear('occurred_at'));
            $this->assertSame("CAST(strftime('%m', occurred_at) AS INTEGER)", DbCompat::extractMonth('occurred_at'));
            $this->assertSame("CAST(strftime('%d', occurred_at) AS INTEGER)", DbCompat::extractDay('occurred_at'));
            $this->assertSame("CAST(strftime('%H', occurred_at) AS INTEGER)", DbCompat::extractHour('occurred_at'));
        } finally {
            config(['database.default' => $original]);
        }
    }
}
