<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Anti free-trial-abuse ledger.
     *
     * Records every credential (email / phone / ntn / username / cnic) ever used
     * to create an account. Public registration is blocked when any submitted
     * credential is already here — persisting even after the account is deleted,
     * so a person cannot spin up a second free trial and must subscribe instead.
     *
     * Backfilled from existing users + companies (incl. soft-deleted companies via
     * a raw read that bypasses the SoftDeletes scope) so current customers are
     * already protected.
     */
    public function up(): void
    {
        if (!Schema::hasTable('registered_credentials')) {
            Schema::create('registered_credentials', function (Blueprint $table) {
                $table->id();
                $table->string('credential_type', 20);   // email | phone | ntn | username | cnic
                $table->string('credential_value', 191);
                $table->string('product_type', 20)->nullable();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->unique(['credential_type', 'credential_value']);
                $table->index('company_id');
            });
        }

        $this->backfill();
    }

    private function backfill(): void
    {
        $now = now();
        $rows = [];

        if (Schema::hasTable('users')) {
            foreach (DB::table('users')->select('id', 'email', 'phone', 'username', 'company_id')->cursor() as $u) {
                if (!empty($u->email)) {
                    $rows[] = ['email', strtolower(trim($u->email)), $u->company_id];
                }
                if (!empty($u->phone)) {
                    $rows[] = ['phone', preg_replace('/[^0-9]/', '', $u->phone), $u->company_id];
                }
                if (!empty($u->username)) {
                    $rows[] = ['username', strtolower(trim($u->username)), $u->company_id];
                }
            }
        }

        // Raw read = no SoftDeletes scope, so soft-deleted companies are included.
        if (Schema::hasTable('companies')) {
            $cols = ['id', 'ntn', 'email'];
            if (Schema::hasColumn('companies', 'cnic')) {
                $cols[] = 'cnic';
            }
            foreach (DB::table('companies')->select($cols)->cursor() as $c) {
                if (!empty($c->ntn)) {
                    $rows[] = ['ntn', strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $c->ntn)), $c->id];
                }
                if (!empty($c->email)) {
                    $rows[] = ['email', strtolower(trim($c->email)), $c->id];
                }
                if (!empty($c->cnic ?? null)) {
                    $rows[] = ['cnic', preg_replace('/[^0-9]/', '', $c->cnic), $c->id];
                }
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $insert = [];
            foreach ($chunk as [$type, $value, $companyId]) {
                if ($value === '' || $value === null) {
                    continue;
                }
                $insert[] = [
                    'credential_type' => $type,
                    'credential_value' => mb_substr($value, 0, 191),
                    'product_type' => null,
                    'company_id' => $companyId,
                    'created_at' => $now,
                ];
            }
            if ($insert) {
                DB::table('registered_credentials')->insertOrIgnore($insert);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('registered_credentials');
    }
};
