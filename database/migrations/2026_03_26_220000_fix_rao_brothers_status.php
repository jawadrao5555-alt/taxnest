<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('companies')
            ->where('ntn', '3620344337269')
            ->where('name', 'RAO BROTHERS')
            ->update([
                'status' => 'approved',
                'company_status' => 'active',
            ]);
    }

    public function down(): void
    {
    }
};
