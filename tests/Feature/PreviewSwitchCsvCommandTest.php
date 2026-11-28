<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PreviewSwitchCsvCommandTest extends TestCase
{
    public function test_authorized_local_preview_does_not_query_or_write_database_rows(): void
    {
        $file = tmpfile();
        self::assertIsResource($file);
        try {
            fwrite($file, "source_id,display_name\ncustomer-1,Example Customer\n");
            $path = stream_get_meta_data($file)['uri'];
            $queries = [];
            DB::listen(function ($event) use (&$queries) {
                $queries[] = $event->sql;
            });

            $status = Artisan::call('switch:preview-csv', [
                'file' => $path, 'source' => 'fixture-source', 'scope' => 'scope-1', 'authorization' => 'approval-001',
            ]);

            self::assertSame(0, $status);
            $manifest = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('valid', $manifest['status']);
            self::assertSame('dry-run', $manifest['mode']);
            self::assertSame([], $queries);
        } finally {
            fclose($file);
        }
    }

    public function test_production_environment_refuses_to_open_the_extract(): void
    {
        $this->app->instance('env', 'production');

        $this->artisan('switch:preview-csv', [
            'file' => '/does-not-exist.csv', 'source' => 'fixture-source', 'scope' => 'scope-1', 'authorization' => 'approval-001',
        ])->expectsOutputToContain('available only in local or testing environments')->assertExitCode(1);
    }

    public function test_unsupported_stream_wrappers_are_rejected(): void
    {
        $this->artisan('switch:preview-csv', [
            'file' => 'https://example.invalid/extract.csv', 'source' => 'fixture-source', 'scope' => 'scope-1', 'authorization' => 'approval-001',
        ])->expectsOutputToContain('Select a readable local file')->assertExitCode(1);
    }
}
