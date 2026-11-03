<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('surveys')) {
            return;
        }
        foreach (['starts_at', 'expires_at', 'targeting', 'response_policy'] as $column) {
            if (! Schema::hasColumn('surveys', $column)) {
                Schema::table('surveys', function (Blueprint $t) use ($column) {
                    if (in_array($column, ['starts_at', 'expires_at'])) {
                        $t->timestamp($column)->nullable();
                    } elseif ($column === 'targeting') {
                        $t->json($column)->nullable();
                    } else {
                        $t->string($column, 16)->default('user');
                    }
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['starts_at', 'expires_at', 'targeting', 'response_policy'] as $column) {
            if (Schema::hasColumn('surveys', $column)) {
                Schema::table('surveys', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
